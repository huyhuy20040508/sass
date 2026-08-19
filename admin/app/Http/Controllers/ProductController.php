<?php

namespace App\Http\Controllers;

use App\Services\ApiClient;
use App\Services\ImageStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Quản lý sản phẩm — trang danh sách.
 *
 * Toàn bộ dữ liệu đọc/ghi qua Go API. Lọc, tìm kiếm và phân trang chạy phía
 * server (API đã hỗ trợ sẵn); mỗi thay đổi bộ lọc là một GET mới. Các thao tác
 * xoá / đổi trạng thái đi qua POST form (chuẩn CSRF của Laravel).
 */
class ProductController extends Controller
{
    /** Số sản phẩm mỗi trang cho phép chọn. */
    public const PER_PAGE_OPTIONS = [10, 20, 30, 50];

    /**
     * Nhãn hiển thị cho từng loại áo (đồng bộ enum kit_type của API).
     *
     * Dùng chung cho cả trang Sản phẩm, Kho và Kiểm kho — đổi ở đây là đổi hết,
     * đừng gõ lại nhãn ở từng trang.
     */
    public const KIT_TYPES = [
        'fan' => 'FAN',
        'player' => 'PLAYER',
    ];

    /** Mã ngắn của loại áo — dùng dựng SKU tự sinh. */
    public const KIT_SKU = [
        'fan' => 'FAN',
        'player' => 'PLAYER',
    ];

    /**
     * Trạng thái kinh doanh (đồng bộ enum `status` của API).
     *
     * Tách "tạm ẩn" khỏi "ngừng kinh doanh": trước đây cả hai đều là is_active = 0
     * nên nhìn danh sách không phân biệt được sản phẩm chờ ảnh với sản phẩm đã bỏ
     * hẳn không nhập nữa.
     */
    public const STATUSES = [
        'active' => 'Đang bán',
        'hidden' => 'Tạm ẩn',
        'discontinued' => 'Ngừng kinh doanh',
    ];

    /** Câu giải thích từng trạng thái — hiện ngay trong modal để chọn cho đúng. */
    public const STATUS_HINTS = [
        'active' => 'Hiện ngoài cửa hàng, khách mua được.',
        'hidden' => 'Không hiện ngoài cửa hàng nhưng vẫn nhập hàng, vẫn tính vào kho.',
        'discontinued' => 'Không hiện, không nhập thêm. Đơn cũ và báo cáo vẫn tra ra được.',
    ];

    /** Các kiểu sắp xếp hợp lệ. */
    public const SORTS = [
        'newest' => 'Mới nhất',
        'price_asc' => 'Giá tăng dần',
        'price_desc' => 'Giá giảm dần',
        'best_selling' => 'Bán chạy',
    ];

    public function __construct(protected ApiClient $api) {}

    /** Danh sách sản phẩm kèm bộ lọc + phân trang. */
    public function index(Request $request)
    {
        $filters = $this->filters($request);
        $products = [];
        $meta = ['page' => 1, 'page_size' => $filters['per_page'], 'total' => 0, 'total_pages' => 1];

        try {
            $res = $this->api->products($this->apiQuery($filters));
            if ($res->successful()) {
                $products = $res->json('data') ?? [];
                $meta = array_merge($meta, $res->json('meta') ?? []);
            } else {
                Log::warning('Load products failed', ['status' => $res->status()]);

                return $this->render($products, $meta, $filters)
                    ->with('error', $res->json('message') ?: 'Không tải được danh sách sản phẩm.');
            }
        } catch (\Throwable $e) {
            Log::error('Load products failed', ['msg' => $e->getMessage()]);

            return $this->render($products, $meta, $filters)
                ->with('error', 'Không tải được danh sách sản phẩm. Kiểm tra kết nối API.');
        }

        return $this->render($products, $meta, $filters);
    }

    /**
     * Đổi trạng thái kinh doanh — chỉ gửi đúng trường status tới endpoint chuyên biệt.
     *
     * Cố ý KHÔNG gửi lại toàn bộ sản phẩm: API ghi cả dòng khi PUT, thiếu một
     * trường nào đó ở đây là bấm đổi trạng thái một cái mất luôn dữ liệu.
     */
    public function toggleStatus(Request $request, int $id)
    {
        $status = (string) $request->input('status', '');
        if (! array_key_exists($status, self::STATUSES)) {
            // Tương thích ngược với form cũ chỉ biết gửi cờ bật/tắt.
            $status = $request->boolean('is_active') ? 'active' : 'hidden';
        }

        $messages = [
            'active' => 'Đã hiển thị sản phẩm trên web.',
            'hidden' => 'Đã tạm ẩn sản phẩm khỏi web.',
            'discontinued' => 'Đã chuyển sản phẩm sang ngừng kinh doanh.',
        ];

        return $this->send(
            fn () => $this->api->setProductStatus($id, $status),
            $messages[$status],
            $request
        );
    }

    /** Chi tiết một sản phẩm (JSON) — modal sửa gọi để làm việc trên dữ liệu mới nhất. */
    public function show(int $id)
    {
        try {
            $res = $this->api->product($id);
        } catch (\Throwable $e) {
            Log::error('Load product detail failed', ['id' => $id, 'msg' => $e->getMessage()]);

            return response()->json(['message' => 'Không kết nối được máy chủ API.'], 502);
        }

        if (! $res->successful()) {
            return response()->json(
                ['message' => $res->json('message') ?: 'Không tải được sản phẩm.'],
                $res->status() === 404 ? 404 : 502
            );
        }

        return response()->json(['data' => $res->json('data')]);
    }

    /** Xoá một sản phẩm. */
    public function destroy(Request $request, int $id)
    {
        return $this->send(
            fn () => $this->api->deleteProduct($id),
            'Đã xoá sản phẩm.',
            $request
        );
    }

    /** Xoá nhiều sản phẩm đã chọn. */
    public function bulkDestroy(Request $request)
    {
        $ids = collect($request->input('ids', []))
            ->map(fn ($v) => (int) $v)
            ->filter()
            ->unique()
            ->all();

        if (empty($ids)) {
            return $this->backToList($request)->with('error', 'Chưa chọn sản phẩm nào để xoá.');
        }

        // Một lượt gọi, API chạy trong một giao dịch: hoặc xoá được hết, hoặc
        // không đụng gì cả. Trước đây lặp gọi từng id — 50 sản phẩm là 50 lượt
        // HTTP nối đuôi nhau, hỏng giữa chừng thì một nửa đã xoá mất.
        try {
            $res = $this->api->bulkDeleteProducts($ids);
        } catch (\Throwable $e) {
            Log::error('Bulk delete products failed', ['count' => count($ids), 'msg' => $e->getMessage()]);

            return $this->backToList($request)->with('error', 'Không kết nối được máy chủ API.');
        }

        if (! $res->successful()) {
            return $this->backToList($request)
                ->with('error', $res->json('message') ?: 'Xoá hàng loạt không thành công.');
        }

        $deleted = (int) ($res->json('data.deleted') ?? count($ids));
        $missing = count($ids) - $deleted;

        // Nói rõ khi số xoá được ít hơn số đã chọn — thường là do người khác vừa
        // xoá mất trong lúc mình đang mở danh sách.
        return $this->backToList($request)->with(
            'success',
            $missing > 0
                ? "Đã xoá {$deleted} sản phẩm; {$missing} sản phẩm không còn tồn tại."
                : "Đã xoá {$deleted} sản phẩm."
        );
    }

    /** Tạo sản phẩm mới. */
    public function store(Request $request)
    {
        $data = $this->productValidated($request);

        try {
            $res = $this->api->createProduct($data);
        } catch (\Throwable $e) {
            Log::error('Create product failed', ['msg' => $e->getMessage()]);

            return back()->withInput()->with('error', 'Không kết nối được máy chủ API.');
        }

        if ($res->successful()) {
            return redirect()->route('admin.products.index')->with('success', 'Đã thêm sản phẩm.');
        }

        return back()->withInput()->with('error', $res->json('message') ?: 'Tạo sản phẩm thất bại.');
    }

    /** Cập nhật sản phẩm (form modal). */
    public function update(Request $request, int $id)
    {
        $data = $this->productValidated($request);

        return $this->send(
            fn () => $this->api->updateProduct($id, $data),
            'Đã cập nhật sản phẩm.',
            $request
        );
    }

    /**
     * Nhận file ảnh từ modal, lưu vào public disk (public/storage/products),
     * trả URL tuyệt đối để gán vào trường thumbnail của sản phẩm.
     */
    public function uploadImage(Request $request)
    {
        $request->validate([
            'image' => ImageStore::rules(),
        ], ImageStore::messages());

        return response()->json(['url' => ImageStore::put($request->file('image'), 'products')]);
    }

    /** Số trang tối đa export chịu lật (mỗi trang 200 dòng). */
    public const EXPORT_MAX_PAGES = 100;

    /** Xuất danh sách sản phẩm (theo bộ lọc hiện tại) ra file CSV (mở tốt bằng Excel). */
    public function export(Request $request)
    {
        $filters = $this->filters($request);
        $query = $this->apiQuery($filters);
        $query['page_size'] = 200;

        $all = [];
        $page = 1;
        $totalPages = 1;
        $total = 0;
        $broken = false;
        do {
            $query['page'] = $page;
            try {
                $res = $this->api->products($query);
            } catch (\Throwable $e) {
                Log::warning('Export products failed', ['msg' => $e->getMessage()]);
                $broken = true;
                break;
            }
            if (! $res->successful()) {
                Log::warning('Export products failed', ['status' => $res->status()]);
                $broken = true;
                break;
            }
            $all = array_merge($all, $res->json('data') ?? []);
            $totalPages = (int) ($res->json('meta.total_pages') ?? 1);
            $total = (int) ($res->json('meta.total') ?? $total);
            $page++;
        } while ($page <= $totalPages && $page <= self::EXPORT_MAX_PAGES);

        // Tệp thiếu dữ liệu mà im lặng là tệ nhất: người dùng mở ra thấy đủ cột,
        // đủ định dạng, không có cách nào biết là đang cầm bản cắt dở.
        $missing = $total > 0 ? $total - count($all) : 0;
        if ($broken || $missing > 0) {
            return $this->backToList($request)->with(
                'error',
                $broken
                    ? 'Máy chủ API ngắt giữa chừng nên chưa xuất được đầy đủ. Đã tải '.count($all).'/'.($total ?: '?').' sản phẩm — vui lòng thử lại.'
                    : 'Bộ lọc hiện có '.$total.' sản phẩm, vượt mức '.(self::EXPORT_MAX_PAGES * 200).' sản phẩm mỗi lần xuất. Vui lòng lọc hẹp lại rồi xuất thành nhiều đợt.'
            );
        }

        $kit = self::KIT_TYPES;
        $statuses = self::STATUSES;
        $filename = 'san-pham-'.date('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($all, $kit, $statuses) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM để Excel đọc đúng tiếng Việt
            fputcsv($out, ['Mã SP', 'SKU', 'Tên sản phẩm', 'Danh mục', 'Thương hiệu', 'Đội bóng', 'Mùa giải', 'Loại áo', 'Giá gốc', 'Giá KM', 'Giá vốn', 'Tổng tồn kho', 'Trạng thái', 'Nổi bật']);
            foreach ($all as $p) {
                $stock = collect($p['variants'] ?? [])->sum('stock_quantity');
                fputcsv($out, [
                    'SP'.str_pad((string) ($p['id'] ?? ''), 6, '0', STR_PAD_LEFT),
                    $p['sku'] ?? '',
                    $p['name'] ?? '',
                    data_get($p, 'category.name', ''),
                    data_get($p, 'brand.name', ''),
                    $p['team'] ?? '',
                    $p['season'] ?? '',
                    $kit[$p['kit_type'] ?? ''] ?? ($p['kit_type'] ?? ''),
                    (int) ($p['base_price'] ?? 0),
                    isset($p['sale_price']) && $p['sale_price'] !== null ? (int) $p['sale_price'] : '',
                    // Ô trống = chưa khai giá vốn. Không đổi thành 0, người đọc file sẽ
                    // tưởng hàng này giá vốn bằng không.
                    isset($p['cost_price']) && $p['cost_price'] !== null ? (int) $p['cost_price'] : '',
                    $stock,
                    $statuses[$p['status'] ?? ''] ?? (! empty($p['is_active']) ? 'Đang bán' : 'Tạm ẩn'),
                    ! empty($p['is_featured']) ? 'Có' : 'Không',
                ]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Tải file CSV mẫu để nhập sản phẩm. */
    public function importTemplate()
    {
        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            // Không có cột tồn kho: sản phẩm nhập vào luôn ở tồn 0, phải qua kho.
            fputcsv($out, ['name', 'category_id', 'brand_id', 'team', 'season', 'kit_type', 'base_price', 'sale_price', 'cost_price', 'sizes']);
            fputcsv($out, ['Áo Real Madrid Sân Nhà 2024/2025 - FAN', '4', '2', 'Real Madrid', '2024/2025', 'fan', '850000', '799000', '520000', 'S,M,L,XL']);
            fputcsv($out, ['Áo Man City Sân Khách 2024/2025 - PLAYER', '3', '1', 'Manchester City', '2024/2025', 'player', '820000', '', '', 'M,L']);
            fclose($out);
        }, 'mau-nhap-san-pham.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Nhập sản phẩm từ file CSV (theo mẫu). Mỗi dòng 1 sản phẩm; cột sizes tạo biến thể. */
    public function import(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
        ], [
            'file.required' => 'Vui lòng chọn file CSV.',
            'file.mimes' => 'Chỉ chấp nhận file CSV.',
            'file.max' => 'File tối đa 5MB.',
        ]);

        $lines = file($request->file('file')->getRealPath(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (! $lines) {
            return back()->with('error', 'File rỗng hoặc không đọc được.');
        }

        $lines[0] = preg_replace('/^\xEF\xBB\xBF/', '', $lines[0]); // bỏ BOM
        $header = array_map(fn ($h) => strtolower(trim($h)), str_getcsv(array_shift($lines)));
        $idx = array_flip($header);
        $get = fn (array $row, string $key) => isset($idx[$key]) ? trim((string) ($row[$idx[$key]] ?? '')) : '';
        $num = fn (string $s) => (float) preg_replace('/[^\d.]/', '', $s);

        $ok = 0;
        // Ghi lại DÒNG NÀO sai và SAI VÌ GÌ. Chỉ đếm số dòng lỗi thì với tệp vài
        // trăm dòng, người dùng không có cách nào lần ra chỗ phải sửa.
        $errors = [];
        // +2 vì: mảng đếm từ 0, và dòng đầu tệp là dòng tiêu đề đã bị lấy ra.
        $lineNo = fn (int $i) => $i + 2;

        foreach ($lines as $i => $line) {
            $row = str_getcsv($line);
            $name = $get($row, 'name');
            if ($name === '') {
                continue;
            }
            $categoryId = (int) $get($row, 'category_id');
            $sizes = array_values(array_filter(array_map('trim', preg_split('/[,;]+/', $get($row, 'sizes')))));
            if ($categoryId <= 0) {
                $errors[] = 'Dòng '.$lineNo($i).' ('.$name.'): thiếu hoặc sai category_id.';

                continue;
            }
            if (empty($sizes)) {
                $errors[] = 'Dòng '.$lineNo($i).' ('.$name.'): chưa khai cột sizes.';

                continue;
            }

            $team = $get($row, 'team');
            $season = $get($row, 'season');
            $kit = $get($row, 'kit_type');
            $kit = in_array($kit, array_keys(self::KIT_TYPES), true) ? $kit : '';
            $sku = $this->productSku(['team' => $team, 'name' => $name, 'kit_type' => $kit, 'season' => $season]);
            $brandId = (int) $get($row, 'brand_id');
            $sale = $get($row, 'sale_price');
            $cost = $get($row, 'cost_price');

            $variants = [];
            foreach ($sizes as $sz) {
                // Biến thể để null cả giá bán lẫn giá vốn: cùng lấy theo sản phẩm cha.
                // Không khai tồn kho — sản phẩm nhập vào đứng ở tồn 0 cho tới khi
                // có phiếu nhập hàng hoặc điều chỉnh kho.
                $variants[] = [
                    'id' => 0, 'sku' => $this->variantSku($sku, $sz, ''),
                    'size' => $sz, 'color' => '', 'price' => null, 'cost_price' => null,
                    'weight_gram' => 0, 'image' => '', 'is_active' => true,
                ];
            }

            $payload = [
                'category_id' => $categoryId,
                'brand_id' => $brandId > 0 ? $brandId : null,
                'name' => $name,
                'slug' => $this->slugify($name),
                'sku' => $sku,
                'short_description' => '', 'description' => '',
                'team' => $team, 'season' => $season, 'kit_type' => $kit,
                'base_price' => $num($get($row, 'base_price')),
                'sale_price' => $sale !== '' ? $num($sale) : null,
                'cost_price' => $cost !== '' ? $num($cost) : null,
                'thumbnail' => '', 'status' => 'hidden', 'is_featured' => false,
                'meta_title' => '', 'meta_description' => '',
                'variants' => $variants, 'images' => [],
            ];

            try {
                $res = $this->api->createProduct($payload);
                if ($res->successful()) {
                    $ok++;
                } else {
                    // Câu lỗi của API đã nói rõ trùng SKU / trùng slug / sai danh mục.
                    $errors[] = 'Dòng '.$lineNo($i).' ('.$name.'): '.($res->json('message') ?: 'API từ chối dòng này.');
                }
            } catch (\Throwable $e) {
                Log::warning('Import product row failed', ['name' => $name, 'msg' => $e->getMessage()]);
                $errors[] = 'Dòng '.$lineNo($i).' ('.$name.'): không gọi được API.';
            }
        }

        $fail = count($errors);
        $msg = "Đã nhập {$ok} sản phẩm".($fail > 0 ? "; {$fail} dòng chưa nhập được." : '.');
        if ($ok > 0) {
            $msg .= ' Sản phẩm nhập vào đang ở trạng thái tạm ẩn và tồn kho 0 — kiểm tra lại rồi mới cho bán.';
        }

        return redirect()->route('admin.products.index')
            ->with($ok === 0 && $fail > 0 ? 'error' : 'success', $msg)
            // Danh sách lỗi hiện thành bảng riêng dưới thông báo, cắt ở 20 dòng
            // cho khỏi tràn màn hình.
            ->with('importErrors', array_slice($errors, 0, 20))
            ->with('importErrorsMore', max(0, $fail - 20));
    }

    // ---------- Helpers ----------

    /** Chuẩn hoá & validate dữ liệu sản phẩm từ form modal. */
    protected function productValidated(Request $request): array
    {
        $v = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:200'],
            'sku' => ['nullable', 'string', 'max:64'],
            'category_id' => ['required', 'integer', 'min:1'],
            'brand_id' => ['nullable', 'integer'],
            'slug' => ['nullable', 'string', 'max:191'],
            'short_description' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string'],
            'team' => ['nullable', 'string', 'max:150'],
            'season' => ['nullable', 'string', 'max:20'],
            'kit_type' => ['nullable', Rule::in(array_keys(self::KIT_TYPES))],
            'status' => ['nullable', Rule::in(array_keys(self::STATUSES))],
            'base_price' => ['required', 'numeric', 'min:0'],
            'sale_price' => ['nullable', 'numeric', 'min:0'],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'thumbnail' => ['nullable', 'string', 'max:255'],
            'meta_title' => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string', 'max:320'],
            'variants' => ['required', 'array', 'min:1'],
            'variants.*.id' => ['nullable', 'integer'],
            'variants.*.sku' => ['nullable', 'string', 'max:64'],
            'variants.*.barcode' => ['nullable', 'string', 'max:64'],
            'variants.*.size' => ['required', 'string', 'max:20'],
            'variants.*.color' => ['nullable', 'string', 'max:50'],
            'variants.*.price' => ['nullable', 'numeric', 'min:0'],
            'variants.*.cost_price' => ['nullable', 'numeric', 'min:0'],
            'images' => ['nullable', 'array'],
            'images.*.id' => ['nullable', 'integer'],
            'images.*.url' => ['required', 'string', 'max:255'],
            'images.*.sort_order' => ['nullable', 'integer'],
        ], [
            'name.required' => 'Vui lòng nhập tên sản phẩm.',
            'name.max' => 'Tên sản phẩm tối đa 200 ký tự.',
            'sku.max' => 'SKU tối đa 64 ký tự.',
            'category_id.required' => 'Vui lòng chọn danh mục.',
            'category_id.min' => 'Vui lòng chọn danh mục.',
            'base_price.required' => 'Vui lòng nhập giá gốc.',
            'base_price.numeric' => 'Giá gốc không hợp lệ.',
            'base_price.min' => 'Giá gốc không được âm.',
            'sale_price.numeric' => 'Giá khuyến mãi không hợp lệ.',
            'sale_price.min' => 'Giá khuyến mãi không được âm.',
            'cost_price.numeric' => 'Giá vốn không hợp lệ.',
            'cost_price.min' => 'Giá vốn không được âm.',
            'variants.*.cost_price.numeric' => 'Giá vốn biến thể không hợp lệ.',
            'variants.required' => 'Sản phẩm phải có ít nhất 1 biến thể (size).',
            'variants.min' => 'Sản phẩm phải có ít nhất 1 biến thể (size).',
            'variants.*.size.required' => 'Mỗi biến thể phải có Size.',
            'variants.*.price.numeric' => 'Giá biến thể không hợp lệ.',
        ])->stopOnFirstFailure()->validate();

        $brandId = $v['brand_id'] ?? null;
        $salePrice = $v['sale_price'] ?? null;
        $costPrice = $v['cost_price'] ?? null;
        // SKU: người dùng gõ thì tôn trọng. Bỏ trống thì hoặc để API đặt theo quy
        // tắc mã hàng hoá của cửa hàng, hoặc — khi chưa bật quy tắc — ghép từ đội
        // bóng · loại áo · mùa giải như trước nay.
        $sku = filled($v['sku'] ?? null) ? trim($v['sku']) : '';
        if ($sku === '' && ! $this->maTuSinh()) {
            $sku = $this->productSku($v);
        }

        // Thư viện ảnh; nếu chưa có ảnh đại diện thì lấy ảnh chính (hoặc ảnh đầu) làm thumbnail.
        $images = $this->imageRows($request);
        $thumbnail = $v['thumbnail'] ?? '';
        if ($thumbnail === '' && ! empty($images)) {
            $primary = collect($images)->firstWhere('is_primary', true);
            $thumbnail = $primary['url'] ?? $images[0]['url'];
        }

        // ---- Ảnh & biến thể: CHỈ gửi khi màn hình thật sự nắm được chúng ----
        //
        // Bên API, gửi `images: []` nghĩa là "xoá sạch thư viện ảnh" (ReplaceImages
        // xoá cứng mọi dòng không nằm trong danh sách). Mà modal sửa sản phẩm chỉ
        // gom được những ảnh ĐANG HIỂN THỊ trên màn hình — nên mỗi lần khung thư
        // viện chưa kịp dựng (API chưa chạy nên modal rơi về bản đã tải sẵn, lỗi JS
        // giữa chừng...) là nó gửi lên mảng rỗng và toàn bộ ảnh của sản phẩm bị xoá
        // trong im lặng. Đã có lần mất thật.
        //
        // Nên phân biệt hai việc khác hẳn nhau:
        //   - "tôi muốn xoá hết ảnh"      -> modal gửi images_loaded=1 kèm mảng rỗng
        //   - "tôi không tải được ảnh"    -> modal KHÔNG gửi images_loaded
        // Trường hợp sau thì bỏ hẳn khoá `images` khỏi payload; API thấy thiếu khoá
        // là bỏ qua bước đồng bộ ảnh và giữ nguyên những gì đang có.
        $payload = [
            'category_id' => (int) $v['category_id'],
            'brand_id' => filled($brandId) ? (int) $brandId : null,
            'name' => $v['name'],
            'slug' => filled($v['slug'] ?? null) ? $this->slugify($v['slug']) : $this->slugify($v['name']),
            'sku' => $sku,
            'short_description' => $v['short_description'] ?? '',
            'description' => $v['description'] ?? '',
            'team' => $v['team'] ?? '',
            'season' => $v['season'] ?? '',
            'kit_type' => $v['kit_type'] ?? '',
            'base_price' => (float) $v['base_price'],
            'sale_price' => filled($salePrice) ? (float) $salePrice : null,
            // null = chưa khai giá vốn; API hiểu đó là "không tính vào giá trị kho",
            // khác hẳn với giá vốn bằng 0.
            'cost_price' => filled($costPrice) ? (float) $costPrice : null,
            'thumbnail' => $thumbnail,
            // Trạng thái là nguồn sự thật; API tự suy is_active ra từ nó. Gửi kèm
            // cả hai là có ngày chúng lệch nhau.
            'status' => $v['status'] ?? 'active',
            'is_featured' => $request->boolean('is_featured'),
            'meta_title' => $v['meta_title'] ?? '',
            'meta_description' => $v['meta_description'] ?? '',
        ];

        // Mảng rỗng vẫn gửi — đó là cách nói "xoá hết" hợp lệ. Chỉ khi màn hình
        // KHÔNG khai là đã nắm được dữ liệu thì mới bỏ khoá đi.
        if ($request->boolean('images_loaded')) {
            $payload['images'] = $images;
        }
        if ($request->boolean('variants_loaded')) {
            $payload['variants'] = $this->variantRows($request, $sku);
        }

        return $payload;
    }

    /**
     * Chuẩn hoá thư viện ảnh từ form. Bỏ ảnh trống; đảm bảo có đúng 1 ảnh chính
     * (nếu chưa đánh dấu ảnh nào thì lấy ảnh đầu).
     */
    protected function imageRows(Request $request): array
    {
        $rows = $request->input('images', []);
        if (! is_array($rows)) {
            return [];
        }

        $out = [];
        $i = 0;
        foreach ($rows as $r) {
            $url = trim((string) ($r['url'] ?? ''));
            if ($url === '') {
                continue;
            }
            $out[] = [
                'id' => filled($r['id'] ?? null) ? (int) $r['id'] : 0,
                'url' => $url,
                'alt' => (string) ($r['alt'] ?? ''),
                'sort_order' => (int) ($r['sort_order'] ?? $i),
                'is_primary' => filled($r['is_primary'] ?? null) ? (bool) $r['is_primary'] : false,
            ];
            $i++;
        }

        if (! empty($out) && ! collect($out)->contains('is_primary', true)) {
            $out[0]['is_primary'] = true;
        }

        return $out;
    }

    /**
     * Chuẩn hoá các dòng biến thể từ form. Bỏ dòng trống; tự sinh SKU biến thể
     * từ SKU sản phẩm + màu + size nếu người dùng để trống.
     */
    protected function variantRows(Request $request, string $productSku): array
    {
        $rows = $request->input('variants', []);
        if (! is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            $size = trim((string) ($r['size'] ?? ''));
            $color = trim((string) ($r['color'] ?? ''));

            $barcode = trim((string) ($r['barcode'] ?? ''));

            // Bỏ qua dòng trống hoàn toàn (không size, không màu, không mã, không giá).
            if ($size === '' && $color === '' && $barcode === ''
                && blank($r['price'] ?? null) && blank($r['cost_price'] ?? null)) {
                continue;
            }

            $sku = trim((string) ($r['sku'] ?? ''));
            if ($sku === '') {
                $sku = $this->variantSku($productSku, $size, $color);
            }
            $price = $r['price'] ?? null;
            $cost = $r['cost_price'] ?? null;

            // Cố ý không gửi stock_quantity: tồn kho chỉ do nghiệp vụ kho ghi.
            $out[] = [
                'id' => filled($r['id'] ?? null) ? (int) $r['id'] : 0,
                'sku' => $sku,
                // Mã vạch để trống gửi lên chuỗi rỗng; API tự quy về NULL, vì cột
                // UNIQUE mà chuỗi rỗng thì chỉ đúng một biến thể được bỏ trống.
                'barcode' => $barcode,
                'size' => $size,
                'color' => $color,
                'price' => filled($price) ? (float) $price : null,
                // null = theo giá vốn của sản phẩm cha, cùng quy ước với `price`.
                'cost_price' => filled($cost) ? (float) $cost : null,
                'weight_gram' => (int) ($r['weight_gram'] ?? 0),
                'image' => (string) ($r['image'] ?? ''),
                'is_active' => filled($r['is_active'] ?? null) ? (bool) $r['is_active'] : true,
            ];
        }

        return $out;
    }

    /** Tự sinh SKU sản phẩm: đội bóng (hoặc tên) · loại áo · mùa giải. VD: RM-FAN-2425. */
    protected function productSku(array $v): string
    {
        $base = filled($v['team'] ?? '') ? $v['team'] : ($v['name'] ?? '');
        $words = array_values(array_filter(explode('-', $this->slugify($base))));
        if (count($words) >= 2) {
            $teamPart = implode('', array_map(fn ($w) => substr($w, 0, 1), $words));
        } else {
            $teamPart = substr($words[0] ?? '', 0, 4);
        }

        $kitPart = self::KIT_SKU[$v['kit_type'] ?? ''] ?? '';

        preg_match_all('/\d+/', (string) ($v['season'] ?? ''), $m);
        $seasonPart = implode('', array_map(fn ($g) => strlen($g) === 4 ? substr($g, 2) : $g, $m[0]));

        $sku = strtoupper(implode('-', array_filter([$teamPart, $kitPart, $seasonPart])));

        return $sku !== '' ? $sku : 'SP-'.strtoupper(Str::random(6));
    }

    /**
     * Tự sinh SKU biến thể: SKU sản phẩm + màu + size. VD: RM-FAN-2425-TRANG-M.
     * FAN / PLAYER là hai sản phẩm riêng nên SKU sản phẩm đã đủ tách
     * chúng ra, biến thể không cần mang phiên bản nữa.
     */
    protected function variantSku(string $productSku, string $size, string $color): string
    {
        // Mã cha để trống = máy chủ sắp đặt mã theo quy tắc đánh số. Ghép ở đây
        // thì ra "DO-M" — một mã không dính gì tới sản phẩm; để trống cho máy chủ
        // ghép lại sau khi nó biết mã cha.
        if (trim($productSku) === '') {
            return '';
        }

        $parts = array_filter([$productSku, $color, $size], fn ($p) => trim((string) $p) !== '');
        $sku = Str::upper($this->slugify(implode('-', $parts)));

        return $sku !== '' ? $sku : $productSku;
    }

    /**
     * Tạo slug hỗ trợ tiếng Việt (Str::slug bỏ dấu tiếng Việt trên một số môi
     * trường, nên chuyển sang ASCII trước). Đồng bộ với CategoryController.
     */
    protected function slugify(string $text): string
    {
        $map = [
            'a' => 'áàạảãâấầậẩẫăắằặẳẵ', 'e' => 'éèẹẻẽêếềệểễ',
            'i' => 'íìịỉĩ', 'o' => 'óòọỏõôốồộổỗơớờợởỡ',
            'u' => 'úùụủũưứừựửữ', 'y' => 'ýỳỵỷỹ', 'd' => 'đ',
        ];

        $text = mb_strtolower($text, 'UTF-8');
        foreach ($map as $ascii => $chars) {
            $text = preg_replace('/['.$chars.']/u', $ascii, $text);
        }

        return Str::slug($text);
    }

    /** Chuẩn hoá bộ lọc từ query string. */
    protected function filters(Request $request): array
    {
        $status = $request->query('status', 'all');
        // 'inactive' của bản cũ = mọi thứ không bán được; nay tách làm hai nên
        // quy về 'hidden' để đường dẫn cũ vẫn ra kết quả gần nhất.
        if ($status === 'inactive') {
            $status = 'hidden';
        }
        if (! in_array($status, array_merge(['all'], array_keys(self::STATUSES)), true)) {
            $status = 'all';
        }

        $sort = $request->query('sort', 'newest');
        if (! array_key_exists($sort, self::SORTS)) {
            $sort = 'newest';
        }

        $kitType = (string) $request->query('kit_type', '');
        if ($kitType !== '' && ! array_key_exists($kitType, self::KIT_TYPES)) {
            $kitType = '';
        }

        $perPage = (int) $request->query('per_page', 20);
        if (! in_array($perPage, self::PER_PAGE_OPTIONS, true)) {
            $perPage = 20;
        }

        $featured = (string) $request->query('featured', '');
        if (! in_array($featured, ['1', '0'], true)) {
            $featured = '';
        }

        return [
            'keyword' => trim((string) $request->query('keyword', '')),
            'category_id' => (int) $request->query('category_id', 0),
            'brand_id' => (int) $request->query('brand_id', 0),
            'kit_type' => $kitType,
            'status' => $status,
            'featured' => $featured,
            'sort' => $sort,
            'per_page' => $perPage,
            'page' => max(1, (int) $request->query('page', 1)),
        ];
    }

    /** Chuyển bộ lọc UI sang query gửi cho API (bỏ giá trị rỗng). */
    protected function apiQuery(array $f): array
    {
        $q = [
            'page' => $f['page'],
            'page_size' => $f['per_page'],
            'sort' => $f['sort'],
        ];

        if ($f['keyword'] !== '') {
            $q['keyword'] = $f['keyword'];
        }
        if ($f['category_id'] > 0) {
            $q['category_id'] = $f['category_id'];
        }
        if ($f['brand_id'] > 0) {
            $q['brand_id'] = $f['brand_id'];
        }
        if ($f['kit_type'] !== '') {
            $q['kit_type'] = $f['kit_type'];
        }
        if ($f['featured'] !== '') {
            $q['featured'] = $f['featured'] === '1' ? 'true' : 'false';
        }

        // Trạng thái: 'all' -> lấy cả sản phẩm không hiện; còn lại lọc chính xác
        // theo cột status. Vẫn phải kèm all=true, nếu không API mặc định chỉ trả
        // sản phẩm đang bán và lọc "tạm ẩn" sẽ luôn ra rỗng.
        $q['all'] = 'true';
        if ($f['status'] !== 'all') {
            $q['status'] = $f['status'];
        }

        return $q;
    }

    /** Nạp danh mục + thương hiệu cho bộ lọc rồi trả về view. */
    protected function render(array $products, array $meta, array $filters)
    {
        return view('products.index', [
            'products' => json_decode(json_encode($products), true) ?? [],
            'meta' => $meta,
            'filters' => $filters,
            'categories' => $this->loadCategories(),
            'brands' => $this->loadBrands(),
            'kitTypes' => self::KIT_TYPES,
            'statuses' => self::STATUSES,
            'statusHints' => self::STATUS_HINTS,
            'sorts' => self::SORTS,
            'perPageOptions' => self::PER_PAGE_OPTIONS,
            'maTuSinh' => $this->maTuSinh(),
        ]);
    }

    /**
     * Cửa hàng đã bật quy tắc mã hàng hoá chưa (Cài đặt → Thông số chung).
     *
     * Bật rồi thì ô SKU khoá lại và để API đặt mã; chưa bật thì màn hình giữ
     * cách cũ — tự ghép từ đội bóng · loại áo · mùa giải.
     */
    protected function maTuSinh(): bool
    {
        try {
            $res = $this->api->quyTacMa();
            if (! $res->successful()) {
                return false;
            }
        } catch (\Throwable $e) {
            Log::warning('Read quy tac ma failed', ['msg' => $e->getMessage()]);

            return false;
        }

        foreach ($res->json('data.quy_tac') ?? [] as $q) {
            if (($q['doc_type'] ?? '') === 'hang-hoa' && ($q['is_active'] ?? false)) {
                return true;
            }
        }

        return false;
    }

    /** Danh mục (phẳng) cho dropdown lọc — im lặng nếu API lỗi. */
    protected function loadCategories(): array
    {
        try {
            $res = $this->api->categories(all: true);
            if ($res->successful()) {
                return $res->json('data') ?? [];
            }
        } catch (\Throwable $e) {
            Log::warning('Load categories for product filter failed', ['msg' => $e->getMessage()]);
        }

        return [];
    }

    /** Thương hiệu cho dropdown lọc — im lặng nếu API lỗi. */
    protected function loadBrands(): array
    {
        try {
            $res = $this->api->brands(all: true);
            if ($res->successful()) {
                return $res->json('data') ?? [];
            }
        } catch (\Throwable $e) {
            Log::warning('Load brands for product filter failed', ['msg' => $e->getMessage()]);
        }

        return [];
    }

    /**
     * Sao chép sản phẩm (tạo bản sao với trạng thái tạm ẩn).
     */
    public function duplicate($id, Request $request)
    {
        return $this->send(
            fn () => $this->api->duplicateProduct((int) $id),
            'Sao chép sản phẩm thành công!',
            $request
        );
    }

    /** Gọi API rồi quay lại danh sách (giữ nguyên bộ lọc) kèm thông báo. */
    protected function send(\Closure $call, string $successMsg, Request $request)
    {
        try {
            $res = $call();
        } catch (\Throwable $e) {
            Log::error('Product API call failed', ['msg' => $e->getMessage()]);

            return $this->backToList($request)->with('error', 'Không kết nối được máy chủ API.');
        }

        if ($res->successful()) {
            return $this->backToList($request)->with('success', $successMsg);
        }

        return $this->backToList($request)->with('error', $res->json('message') ?: 'Thao tác thất bại.');
    }

    /** Chuyển hướng về danh sách, giữ lại bộ lọc hiện tại (từ input 'return'). */
    protected function backToList(Request $request)
    {
        $return = $request->input('return');
        if (is_string($return) && str_starts_with($return, '/')) {
            return redirect($return);
        }

        return redirect()->route('admin.products.index');
    }
}
