<?php

namespace App\Http\Controllers\Concerns;

use App\Http\Controllers\ReportController;
use App\Http\Controllers\StaffReportController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Ô lọc "Nhân viên" (nhóm + một người + ô tìm) dùng chung cho các tab báo cáo
 * cuối ngày theo nhân viên: Báo cáo ca, Báo cáo nhân viên. View đi kèm là
 * v2::reports.end-day-loc-nhan-vien. Lớp dùng trait phải có $this->api.
 */
trait LocNhanVien
{
    /** Bộ lọc chung của Báo cáo cuối ngày + nhóm / một người / ô tìm. */
    protected function locNhanVien(Request $request): array
    {
        $nhom = (string) $request->query('area', '');
        $nguoi = (string) $request->query('user_id', '');

        return ReportController::locCuoiNgay($request) + [
            'area' => isset(StaffReportController::NHOM[$nhom]) ? $nhom : '',
            'user_id' => ctype_digit($nguoi) && (int) $nguoi > 0 ? (string) (int) $nguoi : '',
            'keyword' => trim((string) $request->query('keyword', '')),
        ];
    }

    /** Tham số gửi sang API — bỏ ô trống. */
    protected function truyVanNhanVien(array $filters): array
    {
        return array_filter([
            'from' => $filters['from_date'],
            'to' => $filters['to_date'],
            'shop_id' => $filters['shop_id'],
            'channel' => $filters['channel'],
            'area' => $filters['area'],
            'user_id' => $filters['user_id'],
            'keyword' => $filters['keyword'],
        ], fn ($v) => $v !== '');
    }

    /**
     * Nhân viên cho ô "Tên" — chọn nhóm thì chỉ còn người có cửa vào ấy. Hỏng
     * thì trả rỗng: mất một ô lọc còn hơn mất cả trang.
     */
    protected function nhanVien(string $nhom): array
    {
        try {
            $res = $this->api->users(['status' => 'active', 'page_size' => 100]);
            $ds = $res->successful() ? ($res->json('data') ?? []) : [];
        } catch (\Throwable $e) {
            Log::info('Load staff for report filter failed', ['msg' => $e->getMessage()]);
            $ds = [];
        }

        return collect($ds)
            // `quyen` là cửa vào ĐÃ suy sẵn (cột trống thì theo vai trò) — cùng
            // luật API dùng để lọc bảng, nên ô Tên và bảng không lệch nhau.
            ->filter(fn ($u) => $nhom === '' || in_array($nhom, (array) ($u['quyen'] ?? []), true))
            ->map(fn ($u) => ['id' => (int) $u['id'], 'name' => (string) ($u['full_name'] ?? '')])
            ->values()->all();
    }
}
