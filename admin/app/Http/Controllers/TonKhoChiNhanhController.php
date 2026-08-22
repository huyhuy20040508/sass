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

    /** Số bút toán mỗi lượt nạp trong hộp thoại sổ kho. */
    public const LEDGER_PAGE_SIZE = 20;

    /** Nguồn phát sinh bút toán — cho biết ai/việc gì làm kho đổi. */
    public const TX_SOURCES = [
        'manual' => 'Thủ công',
        'order' => 'Đơn hàng',
        'order_return' => 'Phiếu trả hàng',
    ];

    /** Loại bút toán trong sổ kho — hộp thoại sổ kho dịch mã sang chữ bằng bảng này. */
    public const TX_TYPES = [
        'import' => 'Nhập kho',
        'export' => 'Xuất kho',
        'adjustment' => 'Kiểm kê',
        'return' => 'Hàng trả về',
    ];

    /** Số dòng tối đa nhận trong một file kiểm kê. Đủ cho một lần đếm cả kho;
     *  file lớn hơn thường là xuất nhầm dữ liệu chứ không phải kiểm kê thật. */
    public const IMPORT_MAX_ROWS = 5000;

    /** Số dòng gửi mỗi lần gọi API — đúng mức tối đa endpoint chỉnh hàng loạt nhận. */
    protected const IMPORT_CHUNK = 200;

    /** Số trang tối đa quét để dựng bảng tra SKU (100 dòng/trang). */
    protected const IMPORT_MAX_SKU_PAGES = 100;

    /** Số dòng tối đa trên một phiếu kiểm kê in ra — khoảng 60 trang giấy A4. */
    public const STOCKTAKE_MAX_ROWS = 1500;

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
        $rows = $this->locTheoDongDaChon($this->fetchAll($filters), $request);
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

    public function adjust(Request $request, int $id)
    {
        // shop_id BẮT BUỘC: bảng bày nhiều kho cùng lúc, mỗi dòng một kho. Để trống
        // rồi rơi về "chi nhánh đang làm việc" thì người bấm ở dòng kho A có thể
        // đẩy hàng vào kho B — không lỗi, không cảnh báo, chỉ có hai kho cùng sai.
        $data = $request->validate([
            'shop_id' => 'required|integer|min:1',
            'mode' => 'required|in:set,delta',
            'quantity' => 'required|integer|between:-1000000,1000000',
            'type' => 'nullable|in:import,export,adjustment',
            'unit_cost' => 'nullable|numeric|min:0',
            'note' => 'nullable|string|max:255',
        ]);

        $qty = (int) $data['quantity'];
        if ($data['mode'] === 'set' && $qty < 0) {
            return $this->backToList($request)->with('error', 'Tồn kho không được là số âm.');
        }
        if ($data['mode'] === 'delta' && $qty === 0) {
            return $this->backToList($request)->with('error', 'Vui lòng nhập số lượng cần nhập hoặc xuất.');
        }

        $payload = [
            'shop_id' => (int) $data['shop_id'],
            'mode' => $data['mode'],
            'quantity' => $qty,
            'note' => (string) ($data['note'] ?? ''),
        ];
        if (! empty($data['type'])) {
            $payload['type'] = $data['type'];
        }
        // Giá vốn chỉ gửi kèm khi thực sự là nhập hàng; gửi ở thao tác xuất sẽ làm
        // sổ kho hiểu nhầm đó là giá nhập của lô hàng vừa xuất đi. Không có `type`
        // thì suy theo thao tác, đúng cách API tự suy — nếu chỉ xét `type` thì một
        // lệnh nhập không kèm type sẽ bị rơi mất giá vốn mà không báo gì.
        $isImport = ($data['type'] ?? '') === 'import'
            || (empty($data['type']) && $data['mode'] === 'delta' && $qty > 0);
        if (isset($data['unit_cost']) && $isImport) {
            $payload['unit_cost'] = (float) $data['unit_cost'];
        }

        return $this->send(
            fn () => $this->api->adjustInventory($id, $payload),
            $data['mode'] === 'set'
                ? 'Đã cập nhật tồn kho theo số kiểm kê.'
                : ($qty > 0 ? "Đã nhập thêm {$qty} sản phẩm vào kho." : 'Đã xuất '.abs($qty).' sản phẩm khỏi kho.'),
            $request
        );
    }

    /**
     * Chỉnh tồn kho hàng loạt: áp CÙNG một thao tác cho mọi biến thể đã chọn.
     *
     * API xử lý tất-cả-hoặc-không, nên ở đây không đếm thành công/thất bại từng
     * dòng như các trang khác — một dòng sai là cả lô bị từ chối và kho giữ nguyên.
     */
    public function bulkAdjust(Request $request)
    {
        // Mỗi dòng đã chọn là một CẶP (chi nhánh, biến thể) — bảng gom nhóm theo kho
        // nên người dùng tick vài dòng của kho này rồi vài dòng của kho kia rồi bấm
        // một lần. Gửi riêng danh sách biến thể là mất mất nửa thông tin.
        $data = $request->validate([
            'mode' => 'required|in:set,delta',
            'quantity' => 'required|integer|between:-1000000,1000000',
            'note' => 'nullable|string|max:255',
            'rows' => 'required|array|min:1|max:200',
            'rows.*' => ['regex:/^\d+:\d+$/'],
        ], [
            'rows.required' => 'Chưa chọn dòng nào để chỉnh kho.',
            'rows.*.regex' => 'Danh sách dòng đã chọn không hợp lệ.',
        ]);

        $qty = (int) $data['quantity'];
        if ($data['mode'] === 'set' && $qty < 0) {
            return $this->backToList($request)->with('error', 'Tồn kho không được là số âm.');
        }
        if ($data['mode'] === 'delta' && $qty === 0) {
            return $this->backToList($request)->with('error', 'Vui lòng nhập số lượng cần nhập hoặc xuất.');
        }

        $items = [];
        foreach (array_unique($data['rows']) as $row) {
            [$shopID, $variantID] = array_map('intval', explode(':', $row));
            if ($shopID <= 0 || $variantID <= 0) {
                continue;
            }
            $items[] = [
                'shop_id' => $shopID,
                'variant_id' => $variantID,
                'mode' => $data['mode'],
                'quantity' => $qty,
            ];
        }
        if ($items === []) {
            return $this->backToList($request)->with('error', 'Chưa chọn dòng nào để chỉnh kho.');
        }

        $count = count($items);
        $success = $data['mode'] === 'set'
            ? "Đã đặt tồn kho của {$count} biến thể về {$qty}."
            : ($qty > 0
                ? "Đã nhập thêm {$qty} sản phẩm cho {$count} biến thể."
                : 'Đã xuất '.abs($qty)." sản phẩm khỏi {$count} biến thể.");

        return $this->send(
            fn () => $this->api->bulkAdjustInventory([
                'items' => $items,
                'note' => (string) ($data['note'] ?? ''),
            ]),
            $success,
            $request
        );
    }

    /** Tải file CSV mẫu để nhập số kiểm kê. */
    public function importTemplate()
    {
        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['sku', 'quantity', 'mode']);
            fputcsv($out, ['AO-RM-26-HOME-M-TRANG', 42, 'set']);
            fputcsv($out, ['AO-RM-26-HOME-L-TRANG', 7, 'set']);
            fputcsv($out, ['AO-BC-26-AWAY-M-XANH', -3, 'delta']);
            fclose($out);
        }, 'mau-nhap-ton-kho.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Nhập số kiểm kê từ file CSV.
     *
     * Kiểm kê thật là cầm máy đếm cả kho rồi đổ số vào một lượt, nên file chỉ cần
     * hai cột: `sku` và `quantity`. Cột `mode` không bắt buộc, để riêng vài dòng
     * cộng/trừ lẫn trong một file đếm.
     *
     * File khoá theo SKU chứ không phải id biến thể: người cầm máy quét có mã trên
     * tem hàng, không có id trong database.
     *
     * Toàn bộ file được kiểm tra TRƯỚC khi gọi API — dòng sai bị loại ra và báo lại
     * theo số dòng, phần còn lại vẫn vào kho. Gửi thẳng rồi để API từ chối thì một
     * SKU gõ sai kéo đổ cả lần kiểm kê.
     */
    public function import(Request $request)
    {
        // Một lần kiểm kê là đếm MỘT kho: người cầm máy đi giữa các kệ của một điểm
        // bán. File chỉ có cột sku + số lượng nên không tự nói được kho nào, phải
        // chọn ở hộp thoại — đoán bằng chi nhánh đang làm việc là đổ cả lần đếm vào
        // nhầm kho mà không có dấu hiệu nào.
        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
            'shop_id' => ['required', 'integer', 'min:1'],
            'mode' => ['required', 'in:set,delta'],
            'note' => ['nullable', 'string', 'max:255'],
        ], [
            'file.required' => 'Vui lòng chọn file CSV.',
            'file.mimes' => 'Chỉ chấp nhận file CSV.',
            'file.max' => 'File tối đa 5MB.',
            'mode.required' => 'Vui lòng chọn cách áp số liệu.',
        ]);

        $lines = file($request->file('file')->getRealPath(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (! $lines) {
            return $this->backToList($request)->with('error', 'File rỗng hoặc không đọc được.');
        }

        $lines[0] = preg_replace('/^\xEF\xBB\xBF/', '', $lines[0]);  // bỏ BOM
        $header = array_map(fn ($h) => strtolower(trim($h)), str_getcsv(array_shift($lines)));
        $idx = array_flip($header);

        // Thiếu cột thì báo một lần ở đây. Để rơi xuống vòng lặp sẽ thành hàng nghìn
        // dòng lỗi giống hệt nhau mà không nói được nguyên nhân thật.
        if (! isset($idx['sku']) || ! isset($idx['quantity'])) {
            return $this->backToList($request)->with('error',
                'File thiếu cột bắt buộc: sku và quantity. Vui lòng tải file mẫu để đối chiếu.');
        }
        if (count($lines) > self::IMPORT_MAX_ROWS) {
            return $this->backToList($request)->with('error',
                'File có '.count($lines).' dòng, vượt mức '.self::IMPORT_MAX_ROWS.' dòng mỗi lần nhập. Vui lòng tách nhỏ file.');
        }

        $skus = $this->variantIdsBySku();
        if (! $skus['map']) {
            return $this->backToList($request)->with('error',
                'Không đọc được danh sách biến thể để đối chiếu SKU. Vui lòng thử lại.');
        }

        $get = fn (array $row, string $key) => isset($idx[$key]) ? trim((string) ($row[$idx[$key]] ?? '')) : '';

        $items = [];       // dòng hợp lệ, chờ gửi cho API
        $countedSet = [];  // sku đã có một dòng kiểm kê — dòng thứ hai là mâu thuẫn
        $errors = [];
        $skipped = 0;
        $fail = 0;
        $unknown = 0;

        foreach ($lines as $i => $line) {
            $row = str_getcsv($line);
            $at = 'Dòng '.($i + 2);   // +2: đã bỏ dòng tiêu đề, Excel đếm từ 1

            $sku = strtoupper($get($row, 'sku'));
            $qtyText = $get($row, 'quantity');
            if ($sku === '' && $qtyText === '') {
                $skipped++;
                continue;
            }
            if ($sku === '') {
                $fail++;
                $errors[] = $at.': thiếu SKU.';
                continue;
            }

            // Cách hiểu số lượng: mặc định theo lựa chọn trong modal. Giá trị lạ bị
            // báo lỗi chứ không tự đoán — nhầm giữa "đặt lại" và "cộng thêm" là sai
            // hẳn con số tồn, không phải sai một nhãn hiển thị.
            $mode = strtolower($get($row, 'mode')) ?: $data['mode'];
            if (! in_array($mode, ['set', 'delta'], true)) {
                $fail++;
                $errors[] = $at.' ('.$sku.'): cột mode phải là set hoặc delta.';
                continue;
            }

            // Bỏ dấu phân cách nghìn kiểu Việt/Excel ("1.250", "1,250") rồi mới ép số.
            $clean = preg_replace('/(?!^-)[^\d]/', '', $qtyText);
            if ($clean === '' || $clean === '-') {
                $fail++;
                $errors[] = $at.' ('.$sku.'): số lượng "'.$qtyText.'" không đọc được.';
                continue;
            }
            $qty = (int) $clean;

            if (! isset($skus['map'][$sku])) {
                $fail++;
                $unknown++;
                $errors[] = $at.': SKU '.$sku.' không có trong kho.';
                continue;
            }
            if ($mode === 'set' && $qty < 0) {
                $fail++;
                $errors[] = $at.' ('.$sku.'): số kiểm kê không được âm.';
                continue;
            }
            if ($mode === 'delta' && $qty === 0) {
                $skipped++;   // cộng 0 là không đổi gì, mà API từ chối cả lô vì dòng này
                continue;
            }
            if ($mode === 'set') {
                if (isset($countedSet[$sku])) {
                    $fail++;
                    $errors[] = $at.': SKU '.$sku.' đã có số kiểm kê ở dòng '.$countedSet[$sku].'.';
                    continue;
                }
                $countedSet[$sku] = $i + 2;
            }

            $items[] = [
                'line' => $i + 2,
                'payload' => [
                    'shop_id' => (int) $data['shop_id'],
                    'variant_id' => $skus['map'][$sku],
                    'mode' => $mode,
                    'quantity' => $qty,
                ],
            ];
        }

        $note = trim((string) ($data['note'] ?? '')) ?: 'Nhập số liệu từ file CSV';
        $ok = 0;

        // Gửi theo lô: API chỉnh hàng loạt nhận tối đa 200 dòng và xử lý
        // tất-cả-hoặc-không, nên một lô hỏng chỉ mất đúng lô đó.
        foreach (array_chunk($items, self::IMPORT_CHUNK) as $chunk) {
            $reason = '';
            try {
                $res = $this->api->bulkAdjustInventory([
                    'items' => array_column($chunk, 'payload'),
                    'note' => $note,
                ]);
                if ($res->successful()) {
                    $ok += count($chunk);
                    continue;
                }
                $reason = $res->json('message') ?: 'API từ chối lô này.';
            } catch (\Throwable $e) {
                Log::error('Import inventory chunk failed', ['rows' => count($chunk), 'msg' => $e->getMessage()]);
                $reason = 'không kết nối được API.';
            }
            $fail += count($chunk);
            $errors[] = 'Dòng '.$chunk[0]['line'].'–'.end($chunk)['line'].': '.$reason.' Cả lô này không dòng nào được ghi.';
        }

        $msg = "Đã cập nhật tồn kho {$ok} biến thể";
        if ($fail > 0) {
            $msg .= "; {$fail} dòng lỗi";
        }
        if ($skipped > 0) {
            $msg .= "; bỏ qua {$skipped} dòng trống hoặc không thay đổi";
        }
        $msg .= '.';

        // Kho lớn hơn số trang quét được: SKU ở phần đuôi bị báo "không có trong kho"
        // dù nó vẫn tồn tại — phải nói rõ, nếu không người dùng đi sửa nhầm file.
        if ($unknown > 0 && ! $skus['complete']) {
            $msg .= ' Lưu ý: kho vượt quá số dòng đối chiếu được nên vài SKU có thật vẫn bị báo là không tìm thấy.';
        }

        if ($fail === 0) {
            return $this->backToList($request)->with('success', $msg);
        }

        $detail = implode(' ', array_slice($errors, 0, 5));
        if (count($errors) > 5) {
            $detail .= ' (…và '.(count($errors) - 5).' dòng khác)';
        }

        return $this->backToList($request)->with($ok === 0 ? 'error' : 'success', $msg.' '.$detail);
    }

    /** Tải file CSV mẫu để khai giá vốn. */
    public function importCostTemplate()
    {
        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['sku', 'cost_price']);
            fputcsv($out, ['AO-RM-26-HOME-M-TRANG', 520000]);
            fputcsv($out, ['AO-RM-26-HOME-L-TRANG', 520000]);
            fputcsv($out, ['AO-BC-26-AWAY-M-XANH', 495000]);
            fclose($out);
        }, 'mau-khai-gia-von.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Khai giá vốn hàng loạt từ file CSV.
     *
     * Kho vài nghìn biến thể mà bắt mở form từng sản phẩm gõ tay thì không ai khai
     * nổi, và giá trị tồn kho sẽ mãi thiếu. File chỉ hai cột: `sku` và `cost_price`.
     *
     * Ô `cost_price` để TRỐNG nghĩa là xoá giá vốn riêng của biến thể, cho nó quay
     * về lấy theo sản phẩm cha — khác hẳn với ghi số 0. Muốn khai giá vốn bằng 0
     * thì phải gõ đúng số 0.
     *
     * Cùng cách kiểm tra với nhập kiểm kê: soát hết file trước, dòng sai bị loại và
     * báo theo số dòng, phần còn lại vẫn được ghi.
     */
    public function importCost(Request $request)
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
            return $this->backToList($request)->with('error', 'File rỗng hoặc không đọc được.');
        }

        $lines[0] = preg_replace('/^\xEF\xBB\xBF/', '', $lines[0]);  // bỏ BOM
        $header = array_map(fn ($h) => strtolower(trim($h)), str_getcsv(array_shift($lines)));
        $idx = array_flip($header);

        if (! isset($idx['sku']) || ! isset($idx['cost_price'])) {
            return $this->backToList($request)->with('error',
                'File thiếu cột bắt buộc: sku và cost_price. Vui lòng tải file mẫu để đối chiếu.');
        }
        if (count($lines) > self::IMPORT_MAX_ROWS) {
            return $this->backToList($request)->with('error',
                'File có '.count($lines).' dòng, vượt mức '.self::IMPORT_MAX_ROWS.' dòng mỗi lần nhập. Vui lòng tách nhỏ file.');
        }

        $skus = $this->variantIdsBySku();
        if (! $skus['map']) {
            return $this->backToList($request)->with('error',
                'Không đọc được danh sách biến thể để đối chiếu SKU. Vui lòng thử lại.');
        }

        $get = fn (array $row, string $key) => isset($idx[$key]) ? trim((string) ($row[$idx[$key]] ?? '')) : '';

        $items = [];
        $seen = [];
        $errors = [];
        $skipped = 0;
        $fail = 0;
        $unknown = 0;

        foreach ($lines as $i => $line) {
            $row = str_getcsv($line);
            $at = 'Dòng '.($i + 2);

            $sku = strtoupper($get($row, 'sku'));
            $costText = $get($row, 'cost_price');
            if ($sku === '' && $costText === '') {
                $skipped++;
                continue;
            }
            if ($sku === '') {
                $fail++;
                $errors[] = $at.': thiếu SKU.';
                continue;
            }
            if (! isset($skus['map'][$sku])) {
                $fail++;
                $unknown++;
                $errors[] = $at.': SKU '.$sku.' không có trong kho.';
                continue;
            }
            if (isset($seen[$sku])) {
                $fail++;
                $errors[] = $at.': SKU '.$sku.' đã có giá vốn ở dòng '.$seen[$sku].'.';
                continue;
            }

            $cost = null;
            if ($costText !== '') {
                // Bỏ dấu phân cách nghìn kiểu Việt/Excel trước khi ép số.
                $clean = preg_replace('/[^\d]/', '', $costText);
                if ($clean === '') {
                    $fail++;
                    $errors[] = $at.' ('.$sku.'): giá vốn "'.$costText.'" không đọc được.';
                    continue;
                }
                $cost = (float) $clean;
            }

            $seen[$sku] = $i + 2;
            $items[] = [
                'line' => $i + 2,
                'payload' => ['variant_id' => $skus['map'][$sku], 'cost_price' => $cost],
            ];
        }

        $ok = 0;
        foreach (array_chunk($items, self::IMPORT_CHUNK) as $chunk) {
            $reason = '';
            try {
                $res = $this->api->setInventoryCosts(['items' => array_column($chunk, 'payload')]);
                if ($res->successful()) {
                    $ok += count($chunk);
                    continue;
                }
                $reason = $res->json('message') ?: 'API từ chối lô này.';
            } catch (\Throwable $e) {
                Log::error('Import cost chunk failed', ['rows' => count($chunk), 'msg' => $e->getMessage()]);
                $reason = 'không kết nối được API.';
            }
            $fail += count($chunk);
            $errors[] = 'Dòng '.$chunk[0]['line'].'–'.end($chunk)['line'].': '.$reason.' Cả lô này không dòng nào được ghi.';
        }

        $msg = "Đã khai giá vốn cho {$ok} biến thể";
        if ($fail > 0) {
            $msg .= "; {$fail} dòng lỗi";
        }
        if ($skipped > 0) {
            $msg .= "; bỏ qua {$skipped} dòng trống";
        }
        $msg .= '.';

        if ($unknown > 0 && ! $skus['complete']) {
            $msg .= ' Lưu ý: kho vượt quá số dòng đối chiếu được nên vài SKU có thật vẫn bị báo là không tìm thấy.';
        }

        if ($fail === 0) {
            return $this->backToList($request)->with('success', $msg);
        }

        $detail = implode(' ', array_slice($errors, 0, 5));
        if (count($errors) > 5) {
            $detail .= ' (…và '.(count($errors) - 5).' dòng khác)';
        }

        return $this->backToList($request)->with($ok === 0 ? 'error' : 'success', $msg.' '.$detail);
    }

    /**
     * Bảng tra SKU → id biến thể, dùng cho việc nhập file.
     *
     * Nạp cả kho một lần rồi tra trong bộ nhớ thay vì hỏi API theo từng dòng: một
     * lần kiểm kê có thể vài nghìn dòng, hỏi lẻ là vài nghìn request.
     *
     * `complete` = false nghĩa là kho dài hơn số trang quét được — lúc đó "không tìm
     * thấy SKU" chưa chắc là do file sai.
     */
    protected function variantIdsBySku(): array
    {
        $map = [];
        $page = 1;
        $totalPages = 1;

        try {
            do {
                $res = $this->api->inventory(['page' => $page, 'page_size' => 100]);
                if (! $res->successful()) {
                    Log::warning('Load SKU map failed', ['page' => $page, 'status' => $res->status()]);
                    break;
                }
                foreach ($res->json('data') ?? [] as $r) {
                    // SKU so không phân biệt hoa thường: máy quét và Excel hay tự đổi.
                    $sku = strtoupper(trim((string) ($r['sku'] ?? '')));
                    if ($sku !== '' && ! isset($map[$sku])) {
                        $map[$sku] = (int) ($r['variant_id'] ?? 0);
                    }
                }
                $totalPages = (int) ($res->json('meta.total_pages') ?? 1);
                $page++;
            } while ($page <= $totalPages && $page <= self::IMPORT_MAX_SKU_PAGES);
        } catch (\Throwable $e) {
            Log::error('Load SKU map failed', ['msg' => $e->getMessage()]);
        }

        return ['map' => $map, 'complete' => $page > $totalPages];
    }

    /**
     * Phiếu kiểm kê để in — danh sách biến thể kèm cột trống điền tay.
     *
     * Đi đếm kho là cầm giấy đi giữa các kệ, không cầm laptop. Phiếu in ra theo
     * đúng bộ lọc đang xem (hoặc các dòng đã chọn), giữ nguyên thứ tự sắp xếp trên
     * màn hình để đi tới đâu đánh dấu tới đó.
     *
     * Số đếm được điền tay lên giấy rồi gõ lại — hoặc nhập một lượt bằng file CSV
     * qua `import`, phiếu in sẵn cột SKU chính là để đối chiếu với file đó.
     */
    public function stocktake(Request $request)
    {
        $chiNhanh = $this->chiNhanh();
        $filters = $this->filters($request, $chiNhanh);
        $rows = $this->fetchAll($filters);

        $chon = array_filter(explode(',', (string) $request->query('rows', '')));
        $rows = $this->locTheoDongDaChon($rows, $request);

        abort_if(empty($rows), 404, 'Không tải được dữ liệu tồn kho để in phiếu.');

        // Cắt bớt cho khỏi in nhầm cả nghìn trang giấy; phiếu tự nói rõ đã bị cắt.
        $total = count($rows);
        $rows = array_slice($rows, 0, self::STOCKTAKE_MAX_ROWS);

        // Gom theo kho để mỗi kho là một phần riêng trên giấy: người đi đếm cầm tờ
        // này đi giữa các kệ của MỘT kho, trộn hai kho vào một danh sách là mời họ
        // đếm nhầm sang hàng của nơi khác.
        $theoKho = [];
        foreach ($rows as $r) {
            $theoKho[(int) ($r['shop_id'] ?? 0)]['ten'] = (string) ($r['shop_name'] ?? '');
            $theoKho[(int) ($r['shop_id'] ?? 0)]['dong'][] = $r;
        }

        return view('ton-kho-chi-nhanh.stocktake', [
            'theoKho' => $theoKho,
            'total' => $total,
            'daIn' => count($rows),
            'filters' => $filters,
            'low' => $filters['low_stock'],
            'selected' => $chon !== [],
            'SORTS' => self::SORTS,
            'STOCK_STATES' => self::STOCK_STATES,
        ]);
    }

    protected function send(callable $call, string $success, Request $request)
    {
        try {
            $res = $call();
        } catch (\Throwable $e) {
            Log::error('Inventory API call failed', ['msg' => $e->getMessage()]);

            return $this->backToList($request)->with('error', 'Không kết nối được API. Vui lòng thử lại.');
        }

        if ($res->successful()) {
            return $this->backToList($request)->with('success', $success);
        }

        return $this->backToList($request)->with('error', $res->json('message') ?: 'Thao tác không thành công.');
    }

    protected function backToList(Request $request)
    {
        $return = $request->input('return');
        if (is_string($return) && str_starts_with($return, '/')) {
            return redirect($return);
        }

        return redirect()->route('admin.ton-kho-chi-nhanh.index', $request->query());
    }
    // ---------- Helpers ----------

    /**
     * Giữ lại đúng những dòng người dùng đã tick trên bảng (?rows=2:15,2:16).
     *
     * Khoá là CẶP (chi nhánh, biến thể) chứ không phải id biến thể: cùng một mặt
     * hàng đứng ở nhiều dòng, mỗi kho một dòng. Không tick gì thì giữ nguyên cả
     * danh sách — nút xuất file / in phiếu ở thanh lọc chạy theo bộ lọc đang áp.
     */
    protected function locTheoDongDaChon(array $rows, Request $request): array
    {
        $chon = array_filter(explode(',', (string) $request->query('rows', '')));
        if ($chon === []) {
            return $rows;
        }

        $muon = array_flip($chon);

        return array_values(array_filter(
            $rows,
            fn ($r) => isset($muon[((int) ($r['shop_id'] ?? 0)).':'.((int) ($r['variant_id'] ?? 0))])
        ));
    }

    /**
     * mucTon phân loại một con số tồn về nhóm hiển thị.
     *
     * Để ở đây (public static) cho bảng, file CSV và phiếu in dùng chung một hàm:
     * ba nơi tự phân loại lại là ba cơ hội lệch nhau khi ngưỡng đổi.
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
