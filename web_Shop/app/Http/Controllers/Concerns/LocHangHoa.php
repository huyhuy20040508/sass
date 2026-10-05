<?php

namespace App\Http\Controllers\Concerns;

use App\Http\Controllers\ReportController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Ô lọc "Hàng hóa" (nhóm + một mặt hàng + ô tìm) dùng chung cho các tab báo cáo
 * cuối ngày theo mặt hàng: Hàng hóa, Chi phí & lợi nhuận. View đi kèm là
 * v2::reports.end-day-loc-hang. Lớp dùng trait phải có $this->api.
 */
trait LocHangHoa
{
    /** Bộ lọc chung của Báo cáo cuối ngày + nhóm / mặt hàng / ô tìm / dạng xem. */
    protected function locHang(Request $request): array
    {
        $so = fn (string $k) => ctype_digit((string) $request->query($k, '')) && (int) $request->query($k) > 0
            ? (string) (int) $request->query($k) : '';
        $nhom = $so('category_id');

        return ReportController::locCuoiNgay($request) + [
            'category_id' => $nhom,
            // Ô Hàng hóa chỉ mở khi đã chọn nhóm, như v2 — bỏ nhóm là bỏ luôn mặt hàng.
            'product_id' => $nhom !== '' ? $so('product_id') : '',
            'keyword' => trim((string) $request->query('keyword', '')),
            'show' => $request->query('show') === 'chart' ? 'chart' : 'table',
        ];
    }

    /** Tham số chung gửi sang API — bỏ ô trống. */
    protected function truyVan(array $filters): array
    {
        return array_filter([
            'from' => $filters['from_date'],
            'to' => $filters['to_date'],
            'shop_id' => $filters['shop_id'],
            'channel' => $filters['channel'],
            'category_id' => $filters['category_id'],
            'product_id' => $filters['product_id'],
        ], fn ($v) => $v !== '');
    }

    /**
     * Nhóm hàng cho ô lọc, xếp theo cây: con đứng ngay dưới cha, lùi đầu dòng
     * theo cấp — API trả danh sách phẳng.
     *
     * @return array<int, array{id:int, name:string, cap:int}>
     */
    protected function nhomHang(): array
    {
        try {
            $res = $this->api->categories(true);
            $ds = $res->successful() ? ($res->json('data') ?? []) : [];
        } catch (\Throwable $e) {
            Log::info('Load categories for report filter failed', ['msg' => $e->getMessage()]);
            $ds = [];
        }

        $con = [];
        foreach ($ds as $c) {
            $con[(int) ($c['parent_id'] ?? 0)][] = $c;
        }
        $ra = [];
        $di = function (int $cha, int $cap) use (&$di, &$ra, $con) {
            foreach ($con[$cha] ?? [] as $c) {
                $ra[] = ['id' => (int) $c['id'], 'name' => (string) ($c['name'] ?? ''), 'cap' => $cap];
                // Chặn vòng lặp nếu dữ liệu lỡ trỏ cha vào chính nó.
                if ($cap < 5) {
                    $di((int) $c['id'], $cap + 1);
                }
            }
        };
        $di(0, 0);

        return $ra;
    }

    /** Mặt hàng của nhóm đang chọn (gồm nhóm con) cho ô Hàng hóa; chưa chọn nhóm thì rỗng. */
    protected function hangCuaNhom(array $filters): array
    {
        if ($filters['category_id'] === '') {
            return [];
        }

        try {
            $res = $this->api->products([
                'all' => 'true', 'slim' => 'true', 'page' => 1, 'page_size' => 100,
                'category_id' => (int) $filters['category_id'], 'shop_id' => 0,
            ]);

            return $res->successful()
                ? collect($res->json('data') ?? [])->map(fn ($p) => ['id' => (int) $p['id'], 'name' => (string) ($p['name'] ?? '')])->all()
                : [];
        } catch (\Throwable $e) {
            Log::info('Load products for report filter failed', ['msg' => $e->getMessage()]);

            return [];
        }
    }
}
