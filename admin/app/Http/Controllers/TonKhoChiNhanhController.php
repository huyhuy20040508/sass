<?php

namespace App\Http\Controllers;

use App\Services\ApiClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Tồn kho chi nhánh — Quản lý kho → Tồn kho chi nhánh.
 *
 * Dựng theo màn "Báo cáo tồn kho hiện tại" của bản cũ v2: bảng gom thành từng
 * nhóm chi nhánh, mỗi nhóm gập/mở được, đầu nhóm ghi tên kho kèm số dòng.
 *
 * VÌ SAO TÁCH RA KHỎI TRANG TỒN KHO thay vì thêm một cột: trang Tồn kho trả lời
 * "cả cửa hàng còn bao nhiêu" và mỗi biến thể đúng MỘT dòng — cả bộ lọc, phép
 * sắp xếp lẫn ô tổng đều dựng trên giả định đó. Màn này hỏi câu khác: "số hàng
 * ấy đang nằm ở đâu", nên một biến thể thành nhiều dòng. Nhét hai cách đọc vào
 * một bảng thì mọi con số tổng ở đầu trang phải kèm một câu giải thích.
 *
 * Chi nhánh ĐANG LÀM VIỆC trên thanh trên cùng KHÔNG cắt màn này: người mở nó ra
 * là người muốn nhìn nhiều kho cùng lúc. Ô lọc chi nhánh ở đây mới là thứ quyết
 * định, và mặc định nó chọn sẵn chi nhánh đang làm việc để lần mở đầu tiên vẫn
 * ăn khớp với chỗ người dùng đang đứng.
 */
class TonKhoChiNhanhController extends Controller
{
    /** Nhãn NGẮN cho thanh điều hướng. */
    public const TITLE = 'Tồn kho chi nhánh';

    public const TITLE_PAGE = 'Tồn kho chi nhánh';

    public const EMPTY_TEXT = 'Chưa có dòng tồn nào khớp bộ lọc. Thử bỏ bớt điều kiện hoặc chọn thêm chi nhánh.';

    /** Nhóm mức tồn — dùng chung tên gọi với trang Tồn kho. */
    public const STOCK_STATES = [
        'in' => 'Còn hàng',
        'low' => 'Sắp hết',
        'out' => 'Hết hàng',
    ];

    public const STOCK_TONES = [
        'in' => 'done',
        'low' => 'wait',
        'out' => 'stop',
    ];

    public const SORTS = [
        'stock_asc' => 'Tồn ít nhất',
        'stock_desc' => 'Tồn nhiều nhất',
        'value_desc' => 'Giá trị vốn cao nhất',
        'name_asc' => 'Tên A → Z',
        'name_desc' => 'Tên Z → A',
    ];

    /** Ngưỡng "sắp hết" khi không đọc được cấu hình hệ thống. */
    public const LOW_STOCK = 5;

    /**
     * Số dòng mỗi trang. Cao hơn trang Tồn kho vì một biến thể ở đây sinh ra
     * nhiều dòng: chọn 3 chi nhánh mà vẫn để 20 dòng thì một trang chỉ xem được
     * bảy mặt hàng, và tiêu đề nhóm chiếm gần hết màn hình.
     */
    public const PAGE_SIZES = [20, 50, 100, 200];

    public const PAGE_SIZE = 50;

    /** Số bút toán mỗi lượt nạp trong hộp thoại sổ kho — cùng mức với trang Tồn kho. */
    public const LEDGER_PAGE_SIZE = 20;

    public function __construct(protected ApiClient $api) {}

    public function index(Request $request)
    {
        $chiNhanh = $this->chiNhanh();
        $filters = $this->filters($request, $chiNhanh);

        $rows = [];
        $groups = [];
        $meta = ['page' => $filters['page'], 'page_size' => $filters['page_size'], 'total' => 0, 'total_pages' => 1];
        $error = null;

        try {
            $res = $this->api->tonKhoChiNhanh($this->toQuery($filters));
            if ($res->successful()) {
                $rows = $res->json('data.dong') ?? [];
                $groups = $res->json('data.chi_nhanh') ?? [];
                $meta = array_merge($meta, $res->json('meta') ?? []);
            } else {
                Log::warning('Load ton kho chi nhanh failed', ['status' => $res->status()]);
                $error = $res->json('message') ?: 'Không tải được tồn kho theo chi nhánh.';
            }
        } catch (\Throwable $e) {
            Log::error('Load ton kho chi nhanh failed', ['msg' => $e->getMessage()]);
            $error = 'Không tải được tồn kho theo chi nhánh. Kiểm tra kết nối API.';
        }

        $view = view('ton-kho-chi-nhanh.index', compact('rows', 'groups', 'filters', 'meta', 'chiNhanh'))
            ->with('categories', $this->danhMuc());

        return $error ? $view->with('error', $error) : $view;
    }

    /**
     * Xuất CSV theo đúng bộ lọc đang áp.
     *
     * Có cột chi nhánh ở đầu mỗi dòng chứ không gom nhóm như trên màn hình: file
     * này còn được lọc lại bằng Excel, mà bảng gom nhóm thì lọc xong là mất mất
     * tiêu đề nhóm và không còn biết dòng nào của kho nào.
     */
    public function export(Request $request)
    {
        $chiNhanh = $this->chiNhanh();
        $filters = $this->filters($request, $chiNhanh);
        $rows = $this->fetchAll($filters);
        $low = $filters['low_stock'];
        $fileName = 'ton-kho-chi-nhanh-'.date('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($rows, $low) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Mã chi nhánh', 'Chi nhánh', 'Mã hàng', 'Tên hàng hóa', 'Biến thể', 'ĐVT', 'Nhóm hàng', 'Tồn kho', 'Mức tồn', 'Giá vốn', 'Giá trị vốn tồn kho', 'Trạng thái']);
            foreach ($rows as $r) {
                $qty = (int) ($r['quantity'] ?? 0);
                fputcsv($out, [
                    $r['shop_code'] ?? '',
                    $r['shop_name'] ?? '',
                    $r['sku'] ?? '',
                    $r['product_name'] ?? '',
                    $r['variant_name'] ?? '',
                    $r['unit_name'] ?? '',
                    $r['category_name'] ?? '',
                    $qty,
                    self::STOCK_STATES[self::mucTon($qty, $low)] ?? '',
                    // Ô trống = chưa khai giá vốn. Điền 0 là dựng ra một con số sai
                    // mà người nhận file không có cách nào biết là sai.
                    isset($r['cost_price']) && $r['cost_price'] !== null ? (float) $r['cost_price'] : '',
                    (float) ($r['stock_value'] ?? 0),
                    ! empty($r['is_active']) ? 'Đang bán' : 'Ngừng bán',
                ]);
            }
            fclose($out);
        }, $fileName, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * JSON: sổ kho của MỘT biến thể TẠI MỘT chi nhánh.
     *
     * Phải gửi `shop_id` đích danh chứ không dựa vào chi nhánh đang làm việc: màn
     * này nhìn nhiều kho cùng lúc, người dùng bấm vào dòng của kho nào thì phải ra
     * sổ của kho đó — trong khi chi nhánh đang làm việc có thể là một kho khác
     * hẳn, hoặc không có kho nào (đang xem gộp).
     */
    public function history(Request $request, int $id)
    {
        $shopID = (int) $request->query('shop_id', 0);
        if ($shopID <= 0) {
            return response()->json(['message' => 'Thiếu chi nhánh cần xem sổ kho.'], 422);
        }

        $page = max(1, (int) $request->query('page', 1));

        try {
            $res = $this->api->inventoryHistory($id, [
                'shop_id' => $shopID,
                'page' => $page,
                'page_size' => self::LEDGER_PAGE_SIZE,
            ]);
            if ($res->successful()) {
                return response()->json([
                    'data' => $res->json('data') ?? [],
                    'meta' => $res->json('meta') ?? [],
                ]);
            }
            Log::warning('Load so kho chi nhanh failed', ['id' => $id, 'shop' => $shopID, 'status' => $res->status()]);
        } catch (\Throwable $e) {
            Log::error('Load so kho chi nhanh failed', ['id' => $id, 'shop' => $shopID, 'msg' => $e->getMessage()]);
        }

        return response()->json(['message' => 'Không tải được sổ kho.'], 404);
    }

    // ---------- Helpers ----------

    /**
     * mucTon phân loại một con số tồn về nhóm hiển thị.
     *
     * Cùng một luật với InventoryController::stockState — hai màn đọc chung một
     * kho, để mỗi bên tự tính là mở đường cho cùng một mặt hàng lúc "sắp hết"
     * lúc không, tuỳ người dùng đang mở trang nào.
     */
    public static function mucTon(int $qty, int $low): string
    {
        if ($qty <= 0) {
            return 'out';
        }

        return $qty <= $low ? 'low' : 'in';
    }

    /** Danh sách chi nhánh cho ô lọc. Hỏng thì trả rỗng và trang tự nói ra. */
    protected function chiNhanh(): array
    {
        try {
            $res = $this->api->chiNhanh();
            if ($res->successful()) {
                return $res->json('data') ?? [];
            }
        } catch (\Throwable $e) {
            Log::info('Load chi nhanh for ton kho failed', ['msg' => $e->getMessage()]);
        }

        return [];
    }

    /** Nhóm hàng hoá cho ô lọc. */
    protected function danhMuc(): array
    {
        try {
            $res = $this->api->categories(true);
            if ($res->successful()) {
                return $res->json('data') ?? [];
            }
        } catch (\Throwable $e) {
            Log::info('Load categories for ton kho chi nhanh failed', ['msg' => $e->getMessage()]);
        }

        return [];
    }

    protected function lowStock(): int
    {
        return $this->api->settingInt('low_stock_threshold', self::LOW_STOCK);
    }

    protected function filters(Request $request, array $chiNhanh): array
    {
        $stock = (string) $request->query('stock', 'all');
        $sort = (string) $request->query('sort', 'stock_asc');
        $psize = (int) $request->query('page_size', self::PAGE_SIZE);

        // Chỉ nhận id CÓ THẬT trong danh sách chi nhánh của cửa hàng này. Không
        // phải để chặn (API đã lọc theo tenant rồi) mà để ô lọc không hiện "1 chi
        // nhánh" trong khi bảng trống trơn vì id đó không tồn tại.
        $hopLe = array_map(fn ($c) => (int) $c['id'], $chiNhanh);
        $chon = array_values(array_filter(
            array_map('intval', (array) $request->query('shops', [])),
            fn ($id) => in_array($id, $hopLe, true)
        ));

        // Lần đầu vào trang (URL chưa có tham số nào) thì chọn sẵn chi nhánh đang
        // làm việc: người dùng vừa chọn nó trên thanh trên cùng, mở màn kho ra mà
        // thấy kho khác là một bước hụt.
        if ($chon === [] && ! $request->has('shops') && ! $request->hasAny(['keyword', 'stock', 'category_id', 'sort', 'page'])) {
            $dangLam = (int) session(ApiClient::KHOA_CHI_NHANH, 0);
            if ($dangLam > 0 && in_array($dangLam, $hopLe, true)) {
                $chon = [$dangLam];
            }
        }

        $default = $this->lowStock();
        $low = (int) $request->query('low_stock', $default);

        return [
            'keyword' => trim((string) $request->query('keyword', '')),
            'shops' => $chon,
            'category_id' => max(0, (int) $request->query('category_id', 0)),
            'stock' => isset(self::STOCK_STATES[$stock]) ? $stock : 'all',
            'low_stock' => $low > 0 ? $low : $default,
            'sort' => isset(self::SORTS[$sort]) ? $sort : 'stock_asc',
            'page' => max(1, (int) $request->query('page', 1)),
            'page_size' => in_array($psize, self::PAGE_SIZES, true) ? $psize : self::PAGE_SIZE,
        ];
    }

    protected function toQuery(array $filters): array
    {
        $query = [
            'keyword' => $filters['keyword'],
            'stock' => $filters['stock'],
            'low_stock' => $filters['low_stock'],
            'sort' => $filters['sort'],
            'page' => $filters['page'],
            'page_size' => $filters['page_size'],
        ];
        if ($filters['category_id'] > 0) {
            $query['category_id'] = $filters['category_id'];
        }
        // Không chọn chi nhánh nào = xem mọi chi nhánh đang mở. Gửi chuỗi rỗng thì
        // API vẫn hiểu đúng như vậy, nhưng bỏ hẳn tham số cho URL gọn.
        if ($filters['shops'] !== []) {
            $query['shops'] = implode(',', $filters['shops']);
        }

        return $query;
    }

    /**
     * Nạp toàn bộ dòng khớp bộ lọc để xuất file.
     *
     * Trần 50 trang × 200 dòng = 10.000 dòng: đủ cho vài nghìn mặt hàng nhân với
     * số chi nhánh của một chuỗi thật, và chặn được trường hợp lọc hỏng kéo cả
     * kho về bộ nhớ.
     */
    protected function fetchAll(array $filters): array
    {
        $all = [];
        $query = array_merge($this->toQuery($filters), ['page' => 1, 'page_size' => 200]);
        $totalPages = 1;

        try {
            do {
                $res = $this->api->tonKhoChiNhanh($query);
                if (! $res->successful()) {
                    break;
                }
                $all = array_merge($all, $res->json('data.dong') ?? []);
                $totalPages = (int) ($res->json('meta.total_pages') ?? 1);
                $query['page']++;
            } while ($query['page'] <= $totalPages && $query['page'] <= 50);
        } catch (\Throwable $e) {
            Log::error('Export ton kho chi nhanh failed', ['msg' => $e->getMessage()]);
        }

        return $all;
    }
}
