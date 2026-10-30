<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Db;
use App\Core\Request;
use App\Core\Response;

/**
 * Kiểm sống: PHP chạy, đọc được DB bán hàng và DB nền tảng, và quyền của tài khoản DB đúng phạm
 * vi — ghi được bảng `web_`, KHÔNG ghi được bảng của POS, DB nền tảng chỉ đọc. Đọc từ SHOW
 * GRANTS, không thử ghi thật.
 */
final class HealthController
{
    public function show(Request $req): void
    {
        $kq = ['doc_pos' => false, 'doc_nen_tang' => false, 'ghi_bang_web' => false, 'khong_ghi_bang_pos' => false];
        try {
            $kq['doc_pos'] = Db::pdo()->query('SELECT COUNT(*) FROM tenants')->fetchColumn() !== false;
            $kq['doc_nen_tang'] = Db::nenTang()->query('SELECT COUNT(*) FROM tenant_domains')->fetchColumn() !== false;
            $quyen = Db::pdo()->query('SHOW GRANTS FOR CURRENT_USER()')->fetchAll(\PDO::FETCH_COLUMN);
            $ghi = fn (string $q) => (bool) preg_match('/\b(ALL PRIVILEGES|INSERT|UPDATE|DELETE)\b/', $q);
            $kq['ghi_bang_web'] = (bool) array_filter($quyen, fn ($q) => $ghi($q) && str_contains($q, '`web_cai_dat`'));
            // Quyền ghi cấp cho cả DB (`db`.*), toàn máy (*.*) hay bất kỳ bảng nào ngoài `web_` là quá tay.
            $kq['khong_ghi_bang_pos'] = !array_filter($quyen, fn ($q) => $ghi($q) && !preg_match('/ ON `[^`]+`\.`web_[^`]+` TO /', $q));
        } catch (\Throwable $e) {
            error_log('health: ' . $e->getMessage());
        }
        if (in_array(false, $kq, true)) {
            Response::error(503, 'Kiểm sống không đạt', $kq);
            return;
        }
        Response::ok(['status' => 'ok', ...$kq]);
    }
}
