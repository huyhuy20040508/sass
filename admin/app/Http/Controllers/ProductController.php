<?php

namespace App\Http\Controllers;

use App\Services\ApiClient;
use App\Services\ImageStore;
use App\Support\MucThue;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Hàng hóa → Danh sách hàng hóa.
 *
 * Dựng theo màn "Danh sách hàng hóa" của bản cũ v2 (menu/menu) nhưng theo khuôn
 * BÁN LẺ CHUNG, không phải khuôn áo bóng đá như trước: ba cột đội bóng / mùa
 * giải / loại áo đã bỏ hẳn (migration 0027), biến thể không còn là size + màu
 * nướng cứng mà là TỔ HỢP THUỘC TÍNH do cửa hàng tự khai ở màn Thuộc tính.
 *
 * Bốn danh mục dựng trước đó tới đây mới có chỗ dùng:
 *   Đơn vị tính -> ô ĐVT · Thuế -> ô VAT · Thuộc tính -> bảng biến thể ·
 *   Vị trí -> ô chỗ để hàng.
 *
 * Khác bản v2 bốn chỗ, đều là chỗ bên đó gây phiền:
 *
 *  1. Lọc theo nhóm hàng chạy đúng. v2 lưu nhiều nhóm trong MỘT cột CSV rồi lọc
 *     bằng `whereIn` — mặt hàng thuộc từ hai nhóm trở lên biến mất khỏi bộ lọc.
 *  2. Không có ô lọc "loại hàng hoá" (hàng bán / nguyên vật liệu). v2 giấu nó
 *     bằng `d-none` ở chế độ shop nhưng vẫn gửi lên, nên danh sách của tiệm bán
 *     lẻ luôn bị trộn nguyên vật liệu vào mà không tắt được.
 *  3. Bảng có cột Tồn kho và cột Ảnh. v2 không có cột nào trong hai cột đó —
 *     đúng hai thứ người bán lẻ nhìn đầu tiên.
 *  4. Giá bán sỉ khai được ngay trên màn. Bên v2 cột này có trong DB và trong
 *     tệp xuất Excel nhưng KHÔNG có ô nhập, nên không ai đặt được giá sỉ.
 *
 * Toàn bộ dữ liệu đọc/ghi qua Go API. Lọc, tìm kiếm và phân trang chạy phía
 * server; mỗi thay đổi bộ lọc là một GET mới. Các thao tác xoá / đổi trạng thái
 * đi qua POST form (chuẩn CSRF của Laravel).
 */
class ProductController extends Controller
{
    /** Nhãn NGẮN cho thanh điều hướng. */
    public const TITLE = 'Hàng hóa';

    /** Tiêu đề trang. */
    public const TITLE_PAGE = 'Danh sách hàng hóa';

    /** Số mặt hàng mỗi trang cho phép chọn — đúng bộ của bản cũ. */
    public const PER_PAGE_OPTIONS = [10, 20, 30, 40, 50];

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

    /**
     * Các kiểu sắp xếp hợp lệ.
     *
     * Bản cũ v2 KHÔNG có ô chọn "sắp xếp theo": người dùng bấm thẳng vào tiêu đề
     * cột. Danh sách này vì thế là bảng tra nội bộ cho ba cột bấm được, không bày
     * ra thành dropdown.
     */
    public const SORTS = [
        'newest' => 'Mới nhất',
        'name_asc' => 'Tên A→Z',
        'name_desc' => 'Tên Z→A',
        'group_asc' => 'Nhóm hàng hóa A→Z',
        'group_desc' => 'Nhóm hàng hóa Z→A',
        'price_asc' => 'Giá tăng dần',
        'price_desc' => 'Giá giảm dần',
        'best_selling' => 'Bán chạy',
    ];

    /**
     * Cột nào bấm tiêu đề sắp xếp được, và hai kiểu sắp của nó.
     *
     * Đúng ba cột của v2 còn dùng được ở khuôn bán lẻ (v2 còn cột "Loại hàng
     * hóa" nữa, khuôn này đã bỏ).
     */
    public const SORTABLE = [
        'name' => ['name_asc', 'name_desc'],
        'group' => ['group_asc', 'group_desc'],
        'price' => ['price_asc', 'price_desc'],
    ];

    /** Nhãn hai mã KCT / KKKNT — xem App\Support\MucThue. */
    public const VAT_LABELS = MucThue::NHAN;

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
                    ->with('error', $res->json('message') ?: 'Không tải được danh sách hàng hóa.');
            }
        } catch (\Throwable $e) {
            Log::error('Load products failed', ['msg' => $e->getMessage()]);

            return $this->render($products, $meta, $filters)
                ->with('error', 'Không tải được danh sách hàng hóa. Kiểm tra kết nối API.');
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
            // Công tắc ngoài bảng chỉ gửi cờ bật/tắt. Đẩy nguyên CỜ sang API chứ
            // không tự quy ra "tạm ẩn": mặt hàng đang ngừng kinh doanh mà tắt tiếp
            // thì phải giữ nguyên mức ấy, không bị hạ cấp.
            $bat = $request->boolean('is_active');

            return $this->send(
                fn () => $this->api->setProductActive($id, $bat),
                $bat ? 'Đã cho mặt hàng bán trở lại.' : 'Đã ngừng bán mặt hàng.',
                $request
            );
        }

        $messages = [
            'active' => 'Đã cho mặt hàng bán trở lại.',
            'hidden' => 'Đã tạm ẩn mặt hàng.',
            'discontinued' => 'Đã chuyển mặt hàng sang ngừng kinh doanh.',
        ];

        return $this->send(
            fn () => $this->api->setProductStatus($id, $status),
            $messages[$status],
            $request
        );
    }

    /**
     * Đổi thứ tự mặt hàng trên bảng — hai mũi tên lên/xuống ở cột Thao tác.
     *
     * Chỉ gửi đúng hướng di chuyển tới endpoint chuyên biệt, không gửi lại cả
     * mặt hàng: API ghi cả dòng khi PUT, thiếu một trường là bấm mũi tên một cái
     * mất luôn dữ liệu.
     */
    public function moveSort(Request $request, int $id)
    {
        $huong = $request->input('huong') === 'down' ? 'down' : 'up';

        return $this->send(
            fn () => $this->api->moveProductSort($id, $huong),
            $huong === 'up' ? 'Đã đưa mặt hàng lên trên.' : 'Đã đưa mặt hàng xuống dưới.',
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
                ['message' => $res->json('message') ?: 'Không tải được mặt hàng.'],
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
            'Đã xoá mặt hàng.',
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
            return $this->backToList($request)->with('error', 'Chưa chọn mặt hàng nào để xoá.');
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
                ? "Đã xoá {$deleted} mặt hàng; {$missing} mặt hàng không còn tồn tại."
                : "Đã xoá {$deleted} mặt hàng."
        );
    }

    /** Tạo mặt hàng mới. */
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
            return redirect()->route('admin.products.index')->with('success', 'Đã thêm mặt hàng.');
        }

        return back()->withInput()->with('error', $res->json('message') ?: 'Tạo mặt hàng thất bại.');
    }

    /** Cập nhật mặt hàng (form modal). */
    public function update(Request $request, int $id)
    {
        $data = $this->productValidated($request);

        return $this->send(
            fn () => $this->api->updateProduct($id, $data),
            'Đã cập nhật mặt hàng.',
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

    /** Xuất danh sách hàng hóa (theo bộ lọc hiện tại) ra file CSV (mở tốt bằng Excel). */
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
                    ? 'Máy chủ API ngắt giữa chừng nên chưa xuất được đầy đủ. Đã tải '.count($all).'/'.($total ?: '?').' mặt hàng — vui lòng thử lại.'
                    : 'Bộ lọc hiện có '.$total.' mặt hàng, vượt mức '.(self::EXPORT_MAX_PAGES * 200).' mặt hàng mỗi lần xuất. Vui lòng lọc hẹp lại rồi xuất thành nhiều đợt.'
            );
        }

        $statuses = self::STATUSES;
        $filename = 'hang-hoa-'.date('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($all, $statuses) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM để Excel đọc đúng tiếng Việt
            fputcsv($out, [
                'Mã SP', 'Mã hàng', 'Tên hàng hóa', 'Nhóm hàng hóa', 'ĐVT', 'Vị trí',
                'VAT', 'Giá bán', 'Giá vốn',
                'Tồn kho', 'Chi nhánh', 'Thẻ', 'Số biến thể', 'Trạng thái',
            ]);
            foreach ($all as $p) {
                $variants = array_values($p['variants'] ?? []);
                $stock = collect($variants)->sum('stock_quantity');
                fputcsv($out, [
                    'SP'.str_pad((string) ($p['id'] ?? ''), 6, '0', STR_PAD_LEFT),
                    $p['sku'] ?? '',
                    $p['name'] ?? '',
                    data_get($p, 'category.name', ''),
                    data_get($p, 'unit.name', ''),
                    // Mã + tên: người cầm tệp đi soạn hàng đọc mã trên kệ, còn tên là
                    // để đối chiếu khi mã dán bị mờ.
                    trim(data_get($p, 'location.code', '').' '.data_get($p, 'location.name', '')),
                    self::vatText($p['vat'] ?? 0),
                    (int) ($p['base_price'] ?? 0),
                    // Ô trống = chưa khai giá vốn. Không đổi thành 0, người đọc file sẽ
                    // tưởng hàng này giá vốn bằng không.
                    isset($p['cost_price']) && $p['cost_price'] !== null ? (int) $p['cost_price'] : '',
                    $stock,
                    // Ô trống = mọi chi nhánh, đúng như trên bảng.
                    self::chiNhanhText($p),
                    collect($p['tags'] ?? [])->pluck('name')->implode(', '),
                    // Hàng đơn có đúng một dòng mặc định — đếm ra 1 thì ghi 0 cho khỏi
                    // hiểu nhầm là "có một biến thể".
                    empty($p['is_multi_variant']) ? 0 : count($variants),
                    $statuses[$p['status'] ?? ''] ?? (! empty($p['is_active']) ? 'Đang bán' : 'Tạm ẩn'),
                ]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Tải file CSV mẫu để nhập hàng hóa. */
    public function importTemplate()
    {
        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            // Không có cột tồn kho: mặt hàng nhập vào luôn ở tồn 0, phải qua kho.
            //
            // unit_code / location_code nhận MÃ chứ không phải id — khác category_id
            // ở trên là cố ý: mã đơn vị và mã vị trí là thứ người ta đọc thấy trên
            // màn hình và trên kệ, còn id thì phải đi tra. Bỏ trống = chưa khai.
            //
            // `the` là danh sách TÊN thẻ cách nhau dấu phẩy; tên chưa có thì máy chủ
            // mở thẻ mới. KHÔNG có cột chi nhánh: mặt hàng nhập qua tệp thuộc MỌI chi
            // nhánh, gán riêng thì mở hộp thoại sửa.
            //
            // bien_the là danh sách TÊN biến thể cách nhau dấu phẩy. Tổ hợp thuộc
            // tính (chiều nào ứng với giá trị nào) KHÔNG khai được qua tệp — khai
            // trong hộp thoại sửa mặt hàng, ở đó có ô chọn đúng bộ giá trị đã dựng
            // ở màn Thuộc tính.
            fputcsv($out, ['name', 'category_id', 'unit_code', 'location_code', 'vat', 'base_price', 'cost_price', 'the', 'bien_the']);
            fputcsv($out, ['iPhone 15 128GB', '4', 'CAI', 'VT001', '10', '22000000', '19500000', 'Bán chạy nhất', '']);
            fputcsv($out, ['Ốp lưng silicon', '3', 'CAI', '', '8', '120000', '60000', 'Món mới', 'Đen,Trắng,Xanh']);
            fclose($out);
        }, 'mau-nhap-hang-hoa.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Nhập hàng hóa từ file CSV (theo mẫu). Mỗi dòng 1 mặt hàng. */
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

        // Hai bảng tra MÃ -> id, dựng MỘT lần trước vòng lặp. Tra từng dòng là mỗi
        // dòng một lượt gọi API cho hai bảng chỉ vài chục dòng.
        //
        // Lấy cả dòng đã tắt: tệp nhập có thể nói tới một kệ hay một đơn vị vừa
        // tạm ngừng bày ra ở ô chọn, mà từ chối dòng ấy thì người dùng không hiểu
        // vì sao mã họ đang nhìn thấy trên màn hình lại bị coi là sai.
        $viTriTheoMa = $this->bangTraMa(fn () => $this->api->viTri(), 'locations for product import');
        $donViTheoMa = $this->bangTraMa(fn () => $this->api->donViTinh(), 'units for product import');

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
            if ($categoryId <= 0) {
                $errors[] = 'Dòng '.$lineNo($i).' ('.$name.'): thiếu hoặc sai category_id.';

                continue;
            }

            // Mã lạ thì BÁO LỖI dòng đó chứ không lặng lẽ nhập vào với ô trống:
            // người dùng khai một chỗ để hàng / một đơn vị cụ thể, nhập xong mà mất
            // là họ không biết để đi sửa.
            $maViTri = mb_strtoupper($get($row, 'location_code'));
            $locationId = 0;
            if ($maViTri !== '') {
                if (! isset($viTriTheoMa[$maViTri])) {
                    $errors[] = 'Dòng '.$lineNo($i).' ('.$name.'): không có vị trí mã "'.$maViTri.'".';

                    continue;
                }
                $locationId = $viTriTheoMa[$maViTri];
            }

            $maDonVi = mb_strtoupper($get($row, 'unit_code'));
            $unitId = 0;
            if ($maDonVi !== '') {
                if (! isset($donViTheoMa[$maDonVi])) {
                    $errors[] = 'Dòng '.$lineNo($i).' ('.$name.'): không có đơn vị tính mã "'.$maDonVi.'".';

                    continue;
                }
                $unitId = $donViTheoMa[$maDonVi];
            }

            $sku = $this->productSku(['name' => $name]);
            $cost = $get($row, 'cost_price');
            $vatRaw = $get($row, 'vat');

            // Danh sách tên biến thể; để trống = hàng đơn, API tự dựng dòng mặc định.
            $tenBienThe = array_values(array_filter(array_map('trim', preg_split('/[,;]+/', $get($row, 'bien_the')))));
            $variants = [];
            foreach ($tenBienThe as $pos => $ten) {
                // Biến thể để null cả giá bán lẫn giá vốn: cùng lấy theo mặt hàng cha.
                // Không khai tồn kho — mặt hàng nhập vào đứng ở tồn 0 cho tới khi có
                // phiếu nhập hàng hoặc điều chỉnh kho.
                $variants[] = [
                    'id' => 0, 'sku' => $this->variantSku($sku, $ten), 'barcode' => '',
                    'name' => $ten, 'attributes' => [], 'pos' => $pos,
                    'price' => null, 'cost_price' => null,
                    'weight_gram' => 0, 'image' => '', 'is_active' => true,
                ];
            }

            $payload = [
                'category_id' => $categoryId,
                'location_id' => $locationId,
                'unit_id' => $unitId,
                'name' => $name,
                'slug' => $this->slugify($name),
                'sku' => $sku,
                'short_description' => '', 'description' => '',
                // Bỏ trống cột thuế = lấy mức mặc định của nhóm hàng (API tự điền).
            'vat' => $vatRaw !== '' ? (int) $num($vatRaw) : null,
                'base_price' => $num($get($row, 'base_price')),
                'cost_price' => $cost !== '' ? $num($cost) : null,
                // Thẻ khai bằng TÊN, cách nhau dấu phẩy. Không gửi khoá shop_ids:
                // mặt hàng nhập qua tệp thuộc mọi chi nhánh.
                'tags' => array_values(array_filter(array_map('trim', preg_split('/[,;]+/', $get($row, 'the'))))),
                'thumbnail' => '', 'status' => 'hidden',
                'is_multi_variant' => ! empty($variants),
                'meta_title' => '', 'meta_description' => '',
                'variants' => $variants,
            ];

            try {
                $res = $this->api->createProduct($payload);
                if ($res->successful()) {
                    $ok++;
                } else {
                    // Câu lỗi của API đã nói rõ trùng mã hàng / trùng slug / sai nhóm.
                    $errors[] = 'Dòng '.$lineNo($i).' ('.$name.'): '.($res->json('message') ?: 'API từ chối dòng này.');
                }
            } catch (\Throwable $e) {
                Log::warning('Import product row failed', ['name' => $name, 'msg' => $e->getMessage()]);
                $errors[] = 'Dòng '.$lineNo($i).' ('.$name.'): không gọi được API.';
            }
        }

        $fail = count($errors);
        $msg = "Đã nhập {$ok} mặt hàng".($fail > 0 ? "; {$fail} dòng chưa nhập được." : '.');
        if ($ok > 0) {
            $msg .= ' Mặt hàng nhập vào đang ở trạng thái tạm ẩn và tồn kho 0 — kiểm tra lại rồi mới cho bán.';
        }

        return redirect()->route('admin.products.index')
            ->with($ok === 0 && $fail > 0 ? 'error' : 'success', $msg)
            // Danh sách lỗi hiện thành bảng riêng dưới thông báo, cắt ở 20 dòng
            // cho khỏi tràn màn hình.
            ->with('importErrors', array_slice($errors, 0, 20))
            ->with('importErrorsMore', max(0, $fail - 20));
    }

    // ---------- Helpers ----------

    /** Bảng tra MÃ (viết hoa) -> id cho một danh mục; API lỗi thì trả mảng rỗng. */
    protected function bangTraMa(\Closure $call, string $what): array
    {
        $map = [];
        try {
            $res = $call();
            if ($res->successful()) {
                foreach ($res->json('data') ?? [] as $row) {
                    $map[mb_strtoupper((string) ($row['code'] ?? ''))] = (int) ($row['id'] ?? 0);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Load '.$what.' failed', ['msg' => $e->getMessage()]);
        }

        return $map;
    }

    /** Chuẩn hoá & validate dữ liệu mặt hàng từ form modal. */
    protected function productValidated(Request $request): array
    {
        $v = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:200'],
            'sku' => ['nullable', 'string', 'max:64'],
            'category_id' => ['required', 'integer', 'min:1'],
            // 0 = gỡ vị trí / gỡ đơn vị (ô chọn để trống). Không có 'min:1' vì thế.
            'location_id' => ['nullable', 'integer', 'min:0'],
            'unit_id' => ['nullable', 'integer', 'min:0'],
            'unit_conversions' => ['nullable', 'array'],
            'unit_conversions.*.unit_id' => ['required', 'integer', 'min:1'],
            'unit_conversions.*.quantity' => ['required', 'numeric', 'gt:0'],
            'slug' => ['nullable', 'string', 'max:191'],
            'short_description' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string'],
            'status' => ['nullable', Rule::in(array_keys(self::STATUSES))],
            // Bốn công tắc ở cột trái hộp thoại.
            'is_active' => ['nullable'],
            'print_label' => ['nullable'],
            'is_stock_deducted' => ['nullable'],
            'is_serial' => ['nullable'],
            // -2 và -1 là mã KKKNT / KCT, không phải phần trăm âm.
            'vat' => ['nullable', 'integer', 'min:-2', 'max:100'],
            'base_price' => ['required', 'numeric', 'min:0'],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            // Chi nhánh quản lý mặt hàng. Không tick gì = mọi chi nhánh.
            'shops_loaded' => ['nullable'],
            'shop_ids' => ['nullable', 'array'],
            'shop_ids.*' => ['integer', 'min:1'],
            // Thẻ gửi lên bằng TÊN, không phải id: ô thẻ cho gõ thẻ mới tại chỗ.
            'tags_loaded' => ['nullable'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:50'],
            'thumbnail' => ['nullable', 'string', 'max:255'],
            'meta_title' => ['nullable', 'string', 'max:255'],
            'meta_description' => ['nullable', 'string', 'max:320'],
            'variants' => ['nullable', 'array'],
            'variants.*.id' => ['nullable', 'integer'],
            'variants.*.sku' => ['nullable', 'string', 'max:64'],
            'variants.*.barcode' => ['nullable', 'string', 'max:64'],
            'variants.*.name' => ['nullable', 'string', 'max:255'],
            'variants.*.price' => ['nullable', 'numeric', 'min:0'],
            'variants.*.cost_price' => ['nullable', 'numeric', 'min:0'],
            'variants.*.attributes' => ['nullable', 'array'],
            'variants.*.attributes.*.attribute_id' => ['required', 'integer', 'min:1'],
            'variants.*.attributes.*.value_id' => ['required', 'integer', 'min:1'],
        ], [
            'name.required' => 'Vui lòng nhập tên hàng hóa.',
            'name.max' => 'Tên hàng hóa tối đa 200 ký tự.',
            'sku.max' => 'Mã hàng tối đa 64 ký tự.',
            'category_id.required' => 'Vui lòng chọn nhóm hàng hóa.',
            'category_id.min' => 'Vui lòng chọn nhóm hàng hóa.',
            'location_id.integer' => 'Vị trí không hợp lệ.',
            'unit_id.integer' => 'Đơn vị tính không hợp lệ.',
            'unit_conversions.*.unit_id.required' => 'Mỗi dòng quy đổi phải chọn đơn vị.',
            'unit_conversions.*.quantity.required' => 'Mỗi dòng quy đổi phải nhập số lượng.',
            'unit_conversions.*.quantity.gt' => 'Số lượng quy đổi phải lớn hơn 0.',
            'vat.integer' => 'Mức thuế không hợp lệ.',
            'base_price.required' => 'Vui lòng nhập giá bán.',
            'base_price.numeric' => 'Giá bán không hợp lệ.',
            'base_price.min' => 'Giá bán không được âm.',
            'cost_price.numeric' => 'Giá vốn không hợp lệ.',
            'cost_price.min' => 'Giá vốn không được âm.',
            'shop_ids.*.integer' => 'Chi nhánh không hợp lệ.',
            'tags.*.max' => 'Tên thẻ tối đa 50 ký tự.',
            'variants.*.cost_price.numeric' => 'Giá vốn biến thể không hợp lệ.',
            'variants.*.price.numeric' => 'Giá biến thể không hợp lệ.',
            'variants.*.attributes.*.attribute_id.required' => 'Mỗi dòng biến thể phải chọn đủ thuộc tính.',
            'variants.*.attributes.*.value_id.required' => 'Mỗi dòng biến thể phải chọn đủ giá trị thuộc tính.',
        ])->stopOnFirstFailure()->validate();

        $costPrice = $v['cost_price'] ?? null;
        // Mã hàng: người dùng gõ thì tôn trọng. Bỏ trống thì hoặc để API đặt theo
        // quy tắc mã hàng hoá của cửa hàng, hoặc — khi chưa bật quy tắc — ghép từ
        // tên hàng.
        $sku = filled($v['sku'] ?? null) ? trim($v['sku']) : '';
        if ($sku === '' && ! $this->maTuSinh()) {
            $sku = $this->productSku($v);
        }

        // Mặt hàng chỉ có MỘT ảnh — ô "Ảnh đại diện", đi theo trường `thumbnail`
        // đúng như bản cũ v2. Khoá `images` (thư viện nhiều ảnh) KHÔNG bao giờ được
        // gửi: bên API nó nghĩa là "ghi đè cả thư viện", mà màn hình này không quản
        // lý thư viện nên nó không có quyền nói câu ấy.
        $thumbnail = $v['thumbnail'] ?? '';

        $payload = [
            'category_id' => (int) $v['category_id'],
            // Luôn gửi, kể cả 0: modal bày ô Vị trí và ô ĐVT ở mọi lượt sửa nên "để
            // trống" là ý muốn thật sự của người dùng, không phải màn hình dựng hụt.
            'location_id' => (int) ($v['location_id'] ?? 0),
            'unit_id' => (int) ($v['unit_id'] ?? 0),
            'name' => $v['name'],
            'slug' => filled($v['slug'] ?? null) ? $this->slugify($v['slug']) : $this->slugify($v['name']),
            'sku' => $sku,
            'short_description' => $v['short_description'] ?? '',
            'description' => $v['description'] ?? '',
            'vat' => filled($v['vat'] ?? null) ? (int) $v['vat'] : null,
            'base_price' => (float) $v['base_price'],
            // KHÔNG gửi sale_price: giảm giá là việc của màn Khuyến mãi, hộp thoại
            // này không còn ô ấy. Gửi null ở đây là mỗi lượt sửa tên hàng lại gỡ
            // mất giá khuyến mãi đang chạy.
            //
            // null = chưa khai giá vốn; API hiểu đó là "không tính vào giá trị kho",
            // khác hẳn với giá vốn bằng 0.
            'cost_price' => filled($costPrice) ? (float) $costPrice : null,
            'thumbnail' => $thumbnail,
            // Hộp thoại gửi CỜ BẬT/TẮT chứ không gửi status: công tắc chỉ có hai
            // nấc mà trạng thái có ba mức. API nhận cờ thì giữ nguyên mức "ngừng
            // kinh doanh" thay vì hạ xuống "tạm ẩn" (resolveProductStatus).
            //
            // Vẫn nhận `status` cho đường gọi thẳng API và cho lượt nhập Excel.
            'is_active' => $request->boolean('is_active'),
            'print_label' => $request->boolean('print_label'),
            'is_stock_deducted' => $request->boolean('is_stock_deducted'),
            'is_serial' => $request->boolean('is_serial'),
            'meta_title' => $v['meta_title'] ?? '',
            'meta_description' => $v['meta_description'] ?? '',
        ];

        // KHÔNG gửi is_featured: bản cũ không có ô này và màn hàng hoá cũng đã bỏ.
        // Gửi false ở đây là mỗi lượt sửa giá lại gỡ mặt hàng khỏi khối "Xu hướng"
        // ngoài trang chủ mà không ai biết.

        // Quy đổi đơn vị: cùng quy ước — hộp thoại khai là đã nắm được thì gửi,
        // kể cả mảng rỗng ("xoá hết dòng quy đổi").
        if ($request->boolean('conversions_loaded')) {
            $payload['unit_conversions'] = collect($request->input('unit_conversions', []))
                ->map(fn ($r) => [
                    'unit_id' => (int) ($r['unit_id'] ?? 0),
                    'quantity' => (float) ($r['quantity'] ?? 0),
                ])
                ->filter(fn ($r) => $r['unit_id'] > 0 && $r['quantity'] > 0)
                ->values()
                ->all();
        }

        // Chi nhánh và thẻ: cùng quy ước với ảnh và biến thể — mảng rỗng là ý
        // muốn thật ("gỡ hết"), còn vắng cờ *_loaded là màn hình không nắm được
        // cụm ấy nên đừng đụng vào.
        //
        // Với chi nhánh, "gỡ hết" đọc là MỌI CHI NHÁNH chứ không phải không chi
        // nhánh nào — xem bảng product_shops.
        if ($request->boolean('shops_loaded')) {
            $payload['shop_ids'] = collect($request->input('shop_ids', []))
                ->map(fn ($id) => (int) $id)
                ->filter(fn ($id) => $id > 0)
                ->unique()
                ->values()
                ->all();
        }
        if ($request->boolean('tags_loaded')) {
            $payload['tags'] = collect($request->input('tags', []))
                ->map(fn ($t) => trim((string) $t))
                ->filter(fn ($t) => $t !== '')
                ->unique(fn ($t) => mb_strtolower($t))
                ->values()
                ->all();
        }

        // Mảng rỗng vẫn gửi — đó là cách nói "xoá hết" hợp lệ. Chỉ khi màn hình
        // KHÔNG khai là đã nắm được dữ liệu thì mới bỏ khoá đi.
        if ($request->boolean('variants_loaded')) {
            $rows = $this->variantRows($request, $sku);
            $payload['variants'] = $rows;
            // Cờ nhiều biến thể suy từ chính bảng vừa gửi: có dòng nào mang tổ hợp
            // thuộc tính thì đây là hàng nhiều biến thể.
            $payload['is_multi_variant'] = collect($rows)->contains(fn ($r) => ! empty($r['attributes']));
        }

        return $payload;
    }

    /**
     * Chuẩn hoá các dòng biến thể từ form.
     *
     * Bỏ dòng trống hoàn toàn; tự sinh mã biến thể từ mã hàng + tên biến thể nếu
     * người dùng để trống. Tên biến thể để trống cũng không sao — API tự ghép từ
     * tổ hợp thuộc tính, và ghép ở MỘT chỗ thì hàng thêm qua màn hình với hàng
     * thêm qua API không thành hai kiểu.
     */
    protected function variantRows(Request $request, string $productSku): array
    {
        $rows = $request->input('variants', []);
        if (! is_array($rows)) {
            return [];
        }

        // Có dòng nào mang tổ hợp thuộc tính không? Có thì đây là hàng nhiều biến
        // thể, và dòng KHÔNG tổ hợp lọt vào là rác của một lượt dựng lại bảng dở
        // dang — bỏ đi. Không có dòng tổ hợp nào thì dòng trống chính là dòng mặc
        // định của hàng đơn, phải GIỮ: bất biến "mọi mặt hàng luôn có ít nhất một
        // biến thể" nằm ở đó.
        $coToHop = false;
        foreach ($rows as $r) {
            foreach ((array) ($r['attributes'] ?? []) as $a) {
                if ((int) ($a['attribute_id'] ?? 0) > 0 && (int) ($a['value_id'] ?? 0) > 0) {
                    $coToHop = true;
                    break 2;
                }
            }
        }

        $out = [];
        $pos = 0;
        foreach ($rows as $r) {
            $ten = trim((string) ($r['name'] ?? ''));
            $barcode = trim((string) ($r['barcode'] ?? ''));

            // Tổ hợp thuộc tính: bỏ cặp thiếu vế, giữ nguyên thứ tự người khai bày.
            $tohop = [];
            foreach ((array) ($r['attributes'] ?? []) as $a) {
                $attrId = (int) ($a['attribute_id'] ?? 0);
                $valueId = (int) ($a['value_id'] ?? 0);
                if ($attrId > 0 && $valueId > 0) {
                    $tohop[] = ['attribute_id' => $attrId, 'value_id' => $valueId];
                }
            }

            if ($coToHop && empty($tohop)) {
                continue;
            }

            $sku = trim((string) ($r['sku'] ?? ''));
            if ($sku === '') {
                $sku = $this->variantSku($productSku, $ten);
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
                'name' => $ten,
                'attributes' => $tohop,
                'pos' => $pos++,
                'price' => filled($price) ? (float) $price : null,
                // null = theo giá vốn của mặt hàng cha, cùng quy ước với `price`.
                'cost_price' => filled($cost) ? (float) $cost : null,
                'weight_gram' => (int) ($r['weight_gram'] ?? 0),
                'image' => (string) ($r['image'] ?? ''),
                'is_active' => filled($r['is_active'] ?? null) ? (bool) $r['is_active'] : true,
            ];
        }

        return $out;
    }

    /**
     * Tự sinh mã hàng từ TÊN: bốn chữ cái đầu của các từ + 4 số ngẫu nhiên.
     * VD "iPhone 15 Pro Max" -> IPM-4821.
     *
     * Chỉ dùng khi cửa hàng CHƯA bật quy tắc đánh số (Cài đặt → Thông số chung);
     * bật rồi thì để API đặt mã theo bộ đếm, không bịa ở đây.
     */
    protected function productSku(array $v): string
    {
        $words = array_values(array_filter(explode('-', $this->slugify((string) ($v['name'] ?? '')))));
        if (count($words) >= 2) {
            $dau = implode('', array_map(fn ($w) => substr($w, 0, 1), array_slice($words, 0, 4)));
        } else {
            $dau = substr($words[0] ?? '', 0, 4);
        }
        $dau = strtoupper($dau);

        // Bốn số đuôi: tên hàng bán lẻ trùng nhau rất dễ ("Ốp lưng", "Cáp sạc"),
        // mà mã hàng thì UNIQUE — không có đuôi là lượt Lưu thứ hai ăn lỗi trùng.
        return $dau !== ''
            ? $dau.'-'.str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT)
            : 'SP-'.strtoupper(Str::random(6));
    }

    /**
     * Tự sinh mã biến thể: mã hàng + tên biến thể. VD IPM-4821-128GB-DEN.
     */
    protected function variantSku(string $productSku, string $variantName): string
    {
        // Mã cha để trống = máy chủ sắp đặt mã theo quy tắc đánh số. Ghép ở đây
        // thì ra "128GB-DEN" — một mã không dính gì tới mặt hàng; để trống cho
        // máy chủ ghép lại sau khi nó biết mã cha.
        if (trim($productSku) === '') {
            return '';
        }
        if (trim($variantName) === '') {
            return $productSku;
        }

        $sku = Str::upper($this->slugify($productSku.'-'.$variantName));

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

    /** Nhãn đọc được của một mức thuế: "10%", "KCT", "KKKNT". */
    public static function vatText($vat): string
    {
        return MucThue::chu($vat);
    }

    /** Tên các chi nhánh quản lý mặt hàng; rỗng = mọi chi nhánh. */
    public static function chiNhanhText(array $p): string
    {
        return collect($p['shops'] ?? [])
            ->pluck('name')
            ->filter()
            ->implode(', ');
    }

    /** Chuẩn hoá bộ lọc từ query string. */
    protected function filters(Request $request): array
    {
        // Trạng thái chọn được NHIỀU (bản cũ bày thành các ô tick). Không tick gì
        // = xem tất cả, đúng như bỏ hết tick bên bản cũ.
        $statuses = $request->query('statuses', []);
        if (is_string($statuses)) {
            $statuses = array_filter(explode(',', $statuses));
        }
        $statuses = array_values(array_intersect((array) $statuses, array_keys(self::STATUSES)));

        // Đường dẫn cũ dùng ?status=hidden — vẫn đọc được.
        $status = (string) $request->query('status', '');
        if ($status === 'inactive') {
            $status = 'hidden';
        }
        if ($statuses === [] && array_key_exists($status, self::STATUSES)) {
            $statuses = [$status];
        }

        $sort = $request->query('sort', 'newest');
        if (! array_key_exists($sort, self::SORTS)) {
            $sort = 'newest';
        }

        $perPage = (int) $request->query('per_page', 20);
        if (! in_array($perPage, self::PER_PAGE_OPTIONS, true)) {
            $perPage = 20;
        }

        $multi = (string) $request->query('multi_variant', '');
        if (! in_array($multi, ['1', '0'], true)) {
            $multi = '';
        }

        // Nhóm hàng hoá chọn được NHIỀU — bản cũ dùng ô chọn nhiều.
        $cats = $request->query('category_ids', []);
        if (is_string($cats)) {
            $cats = array_filter(explode(',', $cats));
        }
        $cats = array_values(array_filter(array_map('intval', (array) $cats), fn ($v) => $v > 0));

        // Vị trí nhận ba dạng: '' (không lọc), 'none' (chưa gán vị trí), hoặc id.
        // Giữ nguyên dạng CHUỖI tới tận query gửi API: ép sang int là 'none' hoá 0
        // và bộ lọc "chưa gán" biến mất trong im lặng.
        $location = (string) $request->query('location_id', '');
        if ($location !== 'none' && (int) $location <= 0) {
            $location = '';
        }

        return [
            'keyword' => trim((string) $request->query('keyword', '')),
            'category_ids' => $cats,
            'location_id' => $location,
            'unit_id' => (int) $request->query('unit_id', 0),
            'statuses' => $statuses,
            'multi_variant' => $multi,
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
        if (! empty($f['category_ids'])) {
            $q['category_ids'] = implode(',', $f['category_ids']);
        }
        if ($f['location_id'] !== '') {
            $q['location_id'] = $f['location_id'];
        }
        if ($f['unit_id'] > 0) {
            $q['unit_id'] = $f['unit_id'];
        }
        if ($f['multi_variant'] !== '') {
            $q['multi_variant'] = $f['multi_variant'] === '1' ? 'true' : 'false';
        }

        // Vẫn phải kèm all=true, nếu không API mặc định chỉ trả mặt hàng đang bán
        // và lọc "tạm ẩn" sẽ luôn ra rỗng.
        $q['all'] = 'true';
        if (! empty($f['statuses'])) {
            $q['statuses'] = implode(',', $f['statuses']);
        }

        return $q;
    }

    /** Nạp các danh mục cho bộ lọc + hộp thoại rồi trả về view. */
    protected function render(array $products, array $meta, array $filters)
    {
        return view('products.index', [
            'products' => json_decode(json_encode($products), true) ?? [],
            'meta' => $meta,
            'filters' => $filters,
            'categories' => $this->loadCategories(),
            'locations' => $this->loadLocations(),
            'units' => $this->loadUnits(),
            'attributes' => $this->loadAttributes(),
            'branches' => $this->loadBranches(),
            'tags' => $this->loadTags(),
            'vatRates' => MucThue::boMuc($this->api),
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
     * Bật rồi thì ô Mã hàng khoá lại và để API đặt mã; chưa bật thì màn hình giữ
     * cách cũ — tự ghép từ tên hàng.
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

    /** Nhóm hàng hóa (phẳng) cho dropdown lọc — im lặng nếu API lỗi. */
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

    /**
     * Vị trí để hàng cho ô chọn trong modal và bộ lọc.
     *
     * Chỉ lấy vị trí ĐANG BẬT: tắt một vị trí nghĩa là thôi bày nó ra lúc khai
     * mặt hàng. Mặt hàng đã gắn vị trí đã tắt vẫn giữ nguyên — modal tự chèn lại
     * dòng ấy vào ô chọn để lượt Lưu sau không gỡ mất (xem view).
     */
    protected function loadLocations(): array
    {
        try {
            $res = $this->api->viTri(onlyActive: true);
            if ($res->successful()) {
                return $res->json('data') ?? [];
            }
        } catch (\Throwable $e) {
            Log::warning('Load locations for product form failed', ['msg' => $e->getMessage()]);
        }

        return [];
    }

    /** Đơn vị tính ĐANG BẬT — cùng quy ước với vị trí. */
    protected function loadUnits(): array
    {
        try {
            $res = $this->api->donViTinh(onlyActive: true);
            if ($res->successful()) {
                return $res->json('data') ?? [];
            }
        } catch (\Throwable $e) {
            Log::warning('Load units for product form failed', ['msg' => $e->getMessage()]);
        }

        return [];
    }

    /**
     * Chi nhánh ĐANG HOẠT ĐỘNG — ô "Chi nhánh" trong hộp thoại khai mặt hàng.
     *
     * Chi nhánh đã ngừng hoạt động không bày ra để tick thêm; mặt hàng đã gắn nó
     * thì vẫn giữ nguyên (hộp thoại tự chèn lại dòng ấy — xem view).
     */
    protected function loadBranches(): array
    {
        try {
            $res = $this->api->chiNhanh(onlyActive: true);
            if ($res->successful()) {
                return $res->json('data') ?? [];
            }
        } catch (\Throwable $e) {
            Log::warning('Load branches for product form failed', ['msg' => $e->getMessage()]);
        }

        return [];
    }

    /** Thẻ hàng hóa đang có — gợi ý cho ô "Thẻ" (gõ tên mới vẫn được). */
    protected function loadTags(): array
    {
        try {
            $res = $this->api->theHangHoa();
            if ($res->successful()) {
                return $res->json('data') ?? [];
            }
        } catch (\Throwable $e) {
            Log::warning('Load product tags failed', ['msg' => $e->getMessage()]);
        }

        return [];
    }

    /**
     * Thuộc tính ĐANG BẬT kèm toàn bộ giá trị con — nguồn của bảng biến thể.
     *
     * Đây là chỗ màn Thuộc tính (Hàng hóa → Thuộc tính) thật sự được dùng: chọn
     * "Dung lượng" + "Màu" rồi tick giá trị là ra bảng tổ hợp.
     */
    protected function loadAttributes(): array
    {
        try {
            $res = $this->api->thuocTinh(onlyActive: true);
            if ($res->successful()) {
                return $res->json('data') ?? [];
            }
        } catch (\Throwable $e) {
            Log::warning('Load attributes for product form failed', ['msg' => $e->getMessage()]);
        }

        return [];
    }

    /**
     * Sao chép mặt hàng (tạo bản sao với trạng thái tạm ẩn).
     */
    public function duplicate($id, Request $request)
    {
        return $this->send(
            fn () => $this->api->duplicateProduct((int) $id),
            'Đã sao chép mặt hàng.',
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
