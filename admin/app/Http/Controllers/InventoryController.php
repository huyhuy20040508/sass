<?php

namespace App\Http\Controllers;

use App\Services\ApiClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * InventoryController — trang "Tồn kho" của khu quản trị.
 *
 * Đơn vị quản lý ở đây là BIẾN THỂ (size/màu/phiên bản), không phải sản phẩm:
 * tồn kho nằm ở từng biến thể nên một áo hết size M vẫn còn size L. Gộp về mức
 * sản phẩm sẽ giấu mất đúng thứ nhân viên kho cần thấy.
 *
 * Sổ kho (inventory_transactions) mới là nguồn sự thật; con số tồn hiển thị chỉ
 * là phần đã cộng dồn sẵn. Mọi thay đổi đều đi qua Go API để luôn có bút toán và
 * người thực hiện đi kèm — trang này không bao giờ ghi thẳng vào số tồn.
 */
class InventoryController extends Controller
{
    /** Nhóm mức tồn — cũng là các mục lọc nhanh trên đầu bảng. */
    public const STOCK_STATES = [
        'in' => 'Còn hàng',
        'low' => 'Sắp hết',
        'out' => 'Hết hàng',
    ];

    /** Tình trạng khai giá vốn — cũng là các mục của ô lọc "Giá vốn". */
    public const COST_STATES = [
        'missing' => 'Chưa khai giá vốn',
        'set' => 'Đã khai giá vốn',
    ];

    /** Tông màu badge, dùng chung bảng màu với các trang khác. */
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
        'newest' => 'Mới nhất',
    ];

    /** Trạng thái bán của biến thể. */
    public const ACTIVE_STATES = [
        '1' => 'Đang bán',
        '0' => 'Ngừng bán',
    ];

    /** Loại bút toán trong sổ kho. */
    public const TX_TYPES = [
        'import' => 'Nhập kho',
        'export' => 'Xuất kho',
        'adjustment' => 'Kiểm kê',
        'return' => 'Hàng trả về',
    ];

    /** Nguồn phát sinh bút toán — cho biết ai/việc gì làm kho đổi. */
    public const TX_SOURCES = [
        'manual' => 'Thủ công',
        'order' => 'Đơn hàng',
        'order_return' => 'Phiếu trả hàng',
        'supplier' => 'Nhà cung cấp',
    ];

    /**
     * Ngưỡng "sắp hết" dùng khi không đọc được cấu hình hệ thống.
     *
     * Mức thật lấy từ khoá `low_stock_threshold` bên API (trang Cài đặt) qua
     * lowStock(); hằng số này chỉ là lưới đỡ khi API hỏng.
     */
    public const LOW_STOCK = 5;

    /** Ngưỡng cho phép người dùng chọn, tránh nhập số tuỳ ý làm cảnh báo vô nghĩa. */
    public const LOW_STOCK_OPTIONS = [3, 5, 10, 20, 50];

    public const TITLE = 'Tồn kho';

    public const EMPTY_TEXT = 'Chưa có biến thể sản phẩm nào trong kho. Thêm sản phẩm kèm biến thể ở trang Sản phẩm để bắt đầu theo dõi tồn kho.';

    public const PAGE_SIZES = [20, 50, 100];

    /** Số bút toán mỗi lần nạp trong modal xem nhanh.
     *  Phải trùng số dòng mà API trả sẵn trong `detail`, nếu không trang thứ hai
     *  sẽ lệch và sổ kho hiện thiếu hoặc lặp dòng. */
    public const LEDGER_PAGE_SIZE = 20;

    /** Số dòng tối đa nhận trong một file kiểm kê. Đủ cho một lần đếm cả kho;
     *  file lớn hơn thường là xuất nhầm dữ liệu chứ không phải kiểm kê thật. */
    public const IMPORT_MAX_ROWS = 5000;

    /** Số biến thể gửi mỗi lần gọi API — đúng mức tối đa endpoint chỉnh hàng loạt nhận. */
    protected const IMPORT_CHUNK = 200;

    /** Số trang tối đa quét để dựng bảng tra SKU (100 dòng/trang). */
    protected const IMPORT_MAX_SKU_PAGES = 100;

    /** Số dòng tối đa trên một phiếu kiểm kê in ra — khoảng 60 trang giấy A4. */
    public const STOCKTAKE_MAX_ROWS = 1500;

    public function __construct(protected ApiClient $api) {}

    public function index(Request $request)
    {
        $filters = $this->filters($request);
        $items = [];
        $meta = ['page' => $filters['page'], 'page_size' => $filters['page_size'], 'total' => 0, 'total_pages' => 1];
        $error = null;

        try {
            $res = $this->api->inventory($this->toQuery($filters));
            if ($res->successful()) {
                $items = $res->json('data') ?? [];
                $meta = array_merge($meta, $res->json('meta') ?? []);
            } else {
                Log::warning('Load inventory failed', ['status' => $res->status()]);
                $error = $res->json('message') ?: 'Không tải được danh sách tồn kho.';
            }
        } catch (\Throwable $e) {
            Log::error('Load inventory failed', ['msg' => $e->getMessage()]);
            $error = 'Không tải được danh sách tồn kho. Kiểm tra kết nối API.';
        }

        $view = view('inventory.index', compact('items', 'filters', 'meta'))
            ->with('stats', $this->stats($filters['low_stock']))
            ->with('categories', $this->options('categories'));

        return $error ? $view->with('error', $error) : $view;
    }

    /** JSON: chi tiết một biến thể kèm sổ kho — modal xem nhanh dựng từ đây. */
    public function detail(int $id)
    {
        try {
            $res = $this->api->inventoryItem($id);
            if ($res->successful()) {
                return response()->json(['data' => $res->json('data')]);
            }
        } catch (\Throwable $e) {
            Log::error('Load inventory detail failed', ['id' => $id, 'msg' => $e->getMessage()]);
        }

        return response()->json(['message' => 'Không tải được chi tiết tồn kho.'], 404);
    }

    /**
     * JSON: một trang sổ kho của biến thể.
     *
     * Modal xem nhanh đã có sẵn trang đầu trong `detail`; nút "Xem thêm" gọi vào
     * đây để lấy các trang sau. Nạp dần thay vì đổ cả sổ ngay từ đầu: một biến thể
     * bán chạy có thể có hàng nghìn bút toán, mà người mở modal thường chỉ cần
     * xem vài lần gần nhất.
     */
    public function history(Request $request, int $id)
    {
        $page = max(1, (int) $request->query('page', 1));

        try {
            $res = $this->api->inventoryHistory($id, ['page' => $page, 'page_size' => self::LEDGER_PAGE_SIZE]);
            if ($res->successful()) {
                return response()->json([
                    'data' => $res->json('data') ?? [],
                    'meta' => $res->json('meta') ?? [],
                ]);
            }
            Log::warning('Load inventory history failed', ['id' => $id, 'page' => $page, 'status' => $res->status()]);
        } catch (\Throwable $e) {
            Log::error('Load inventory history failed', ['id' => $id, 'page' => $page, 'msg' => $e->getMessage()]);
        }

        return response()->json(['message' => 'Không tải được sổ kho.'], 404);
    }

    /** Chỉnh tồn kho một biến thể: đặt lại số (kiểm kê) hoặc cộng/trừ (nhập/xuất). */
    public function adjust(Request $request, int $id)
    {
        $data = $request->validate([
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
        $data = $request->validate([
            'mode' => 'required|in:set,delta',
            'quantity' => 'required|integer|between:-1000000,1000000',
            'note' => 'nullable|string|max:255',
            'ids' => 'required|array|min:1|max:200',
            'ids.*' => 'integer|min:1',
        ]);

        $qty = (int) $data['quantity'];
        if ($data['mode'] === 'set' && $qty < 0) {
            return $this->backToList($request)->with('error', 'Tồn kho không được là số âm.');
        }
        if ($data['mode'] === 'delta' && $qty === 0) {
            return $this->backToList($request)->with('error', 'Vui lòng nhập số lượng cần nhập hoặc xuất.');
        }

        $ids = array_values(array_unique(array_map('intval', $data['ids'])));
        $items = array_map(fn ($id) => [
            'variant_id' => $id,
            'mode' => $data['mode'],
            'quantity' => $qty,
        ], $ids);

        $count = count($ids);
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
        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
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
                'payload' => ['variant_id' => $skus['map'][$sku], 'mode' => $mode, 'quantity' => $qty],
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
        $filters = $this->filters($request);
        $rows = $request->query('ids', '') !== ''
            ? $this->fetchByIds($request)
            : $this->fetchAll($filters);

        abort_if(empty($rows), 404, 'Không tải được dữ liệu tồn kho để in phiếu.');

        // Cắt bớt cho khỏi in nhầm cả nghìn trang giấy; phiếu tự nói rõ đã bị cắt.
        $total = count($rows);
        $rows = array_slice($rows, 0, self::STOCKTAKE_MAX_ROWS);

        return view('inventory.stocktake', [
            'rows' => $rows,
            'total' => $total,
            'filters' => $filters,
            'low' => $filters['low_stock'],
            'selected' => $request->query('ids', '') !== '',
            'SORTS' => self::SORTS,
            'STOCK_STATES' => self::STOCK_STATES,
        ]);
    }

    public function export(Request $request)
    {
        // Có ?ids=... thì chỉ xuất các dòng được chọn trên bảng; không thì xuất
        // theo bộ lọc đang áp dụng.
        $filters = $this->filters($request);
        $rows = $request->query('ids', '') !== ''
            ? $this->fetchByIds($request)
            : $this->fetchAll($filters);
        $fileName = 'ton-kho-'.date('Ymd-His').'.csv';
        $low = $filters['low_stock'];

        // Cửa hàng nhiều chi nhánh: file phải nói con số này là của kho nào. Thiếu
        // cột đó thì hai lượt xuất của hai kho ra hai file trông y hệt nhau, và
        // người mở lại sau một tuần không có cách nào phân biệt.
        $nhieuChiNhanh = count(\App\Services\ChiNhanhDangLam::danhSach()['ds']) > 1;
        $khoText = \App\Services\ChiNhanhDangLam::ten() ?? 'Gộp mọi chi nhánh';

        return response()->streamDownload(function () use ($rows, $low, $nhieuChiNhanh, $khoText) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            $cot = ['Mã hàng', 'Tên hàng hóa', 'Biến thể', 'ĐVT', 'Nhóm hàng', 'Tồn kho', 'Mức tồn', 'Giá bán', 'Giá vốn', 'Giá trị vốn tồn kho', 'Trạng thái', 'Lần cuối phát sinh'];
            if ($nhieuChiNhanh) {
                array_unshift($cot, 'Chi nhánh');
            }
            fputcsv($out, $cot);
            foreach ($rows as $r) {
                $qty = (int) ($r['stock_quantity'] ?? 0);
                $dong = [
                    $r['sku'] ?? '',
                    $r['product_name'] ?? '',
                    $r['variant_name'] ?? '',
                    $r['unit_name'] ?? '',
                    $r['category_name'] ?? '',
                    $qty,
                    self::STOCK_STATES[self::stockState($qty, $low)] ?? '',
                    (float) ($r['price'] ?? 0),
                    // Ô trống = chưa khai giá vốn; điền 0 sẽ thành một con số sai đọc
                    // được, và người nhận file không có cách nào biết là nó sai.
                    isset($r['cost_price']) && $r['cost_price'] !== null ? (float) $r['cost_price'] : '',
                    (float) ($r['stock_value'] ?? 0),
                    ! empty($r['is_active']) ? 'Đang bán' : 'Ngừng bán',
                    ! empty($r['last_moved_at']) ? \Illuminate\Support\Carbon::parse($r['last_moved_at'])->format('d/m/Y H:i') : '',
                ];
                if ($nhieuChiNhanh) {
                    array_unshift($dong, $khoText);
                }
                fputcsv($out, $dong);
            }
            fclose($out);
        }, $fileName, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    // ---------- Helpers ----------

    /**
     * stockState phân loại một con số tồn về nhóm hiển thị.
     *
     * Giữ ở đây (public static) để bảng, CSV và modal đều gọi cùng một hàm — ba
     * nơi tự tính lại là ba cơ hội để lệch nhau khi ngưỡng đổi.
     */
    public static function stockState(int $qty, int $low): string
    {
        if ($qty <= 0) {
            return 'out';
        }

        return $qty <= $low ? 'low' : 'in';
    }

    /** Đọc các biến thể theo ?ids=1,2,3 — giữ đúng thứ tự người dùng đã chọn. */
    protected function fetchByIds(Request $request): array
    {
        $ids = [];
        foreach (explode(',', (string) $request->query('ids', '')) as $part) {
            $n = (int) trim($part);
            if ($n > 0 && ! in_array($n, $ids, true)) {
                $ids[] = $n;
            }
        }
        $ids = array_slice($ids, 0, 200); // chặn xuất quá nhiều một lúc

        $rows = [];
        foreach ($ids as $id) {
            try {
                $res = $this->api->inventoryItem($id);
                if ($res->successful() && ($item = $res->json('data.item'))) {
                    $rows[] = $item;
                }
            } catch (\Throwable $e) {
                Log::error('Load inventory row for export failed', ['id' => $id, 'msg' => $e->getMessage()]);
            }
        }

        return $rows;
    }

    /**
     * Ngưỡng "sắp hết" đang cấu hình (trang Cài đặt → Bán hàng & kho).
     *
     * Đọc qua bản cache 5 phút của ApiClient nên không thêm lượt gọi API cho mỗi
     * lần mở trang; API hỏng thì rơi về self::LOW_STOCK.
     */
    protected function lowStock(): int
    {
        return $this->api->settingInt('low_stock_threshold', self::LOW_STOCK);
    }

    protected function filters(Request $request): array
    {
        $stock = (string) $request->query('stock', 'all');
        $cost = (string) $request->query('cost', 'all');
        $sort = (string) $request->query('sort', 'stock_asc');
        $active = (string) $request->query('is_active', '');
        $psize = (int) $request->query('page_size', 20);
        $default = $this->lowStock();
        $low = (int) $request->query('low_stock', $default);

        return [
            'keyword' => trim((string) $request->query('keyword', '')),
            'category_id' => max(0, (int) $request->query('category_id', 0)),
            'stock' => isset(self::STOCK_STATES[$stock]) ? $stock : 'all',
            'cost' => isset(self::COST_STATES[$cost]) ? $cost : 'all',
            'is_active' => isset(self::ACTIVE_STATES[$active]) ? $active : '',
            // Chấp nhận cả ngưỡng đang cấu hình lẫn các mức trong danh sách chọn:
            // cấu hình có thể đặt số không nằm trong LOW_STOCK_OPTIONS (VD 7), lúc đó
            // vẫn phải dùng đúng số đó chứ không âm thầm nhảy về mức khác.
            'low_stock' => ($low === $default || in_array($low, self::LOW_STOCK_OPTIONS, true)) ? $low : $default,
            'sort' => isset(self::SORTS[$sort]) ? $sort : 'stock_asc',
            'page' => max(1, (int) $request->query('page', 1)),
            'page_size' => in_array($psize, self::PAGE_SIZES, true) ? $psize : 20,
        ];
    }

    /**
     * toQuery đổi mảng bộ lọc của trang sang query gửi cho API.
     *
     * Các giá trị "không lọc" bị bỏ hẳn khỏi query thay vì gửi chuỗi rỗng: API
     * hiểu tham số vắng mặt là không lọc, còn category_id=0 sẽ thành lọc theo
     * danh mục có id 0 và trả về bảng trống.
     */
    protected function toQuery(array $filters): array
    {
        $query = [
            'keyword' => $filters['keyword'],
            'stock' => $filters['stock'],
            'cost' => $filters['cost'],
            'low_stock' => $filters['low_stock'],
            'sort' => $filters['sort'],
            'page' => $filters['page'],
            'page_size' => $filters['page_size'],
        ];
        if ($filters['category_id'] > 0) {
            $query['category_id'] = $filters['category_id'];
        }
        if ($filters['is_active'] !== '') {
            $query['is_active'] = $filters['is_active'] === '1' ? 'true' : 'false';
        }

        return $query;
    }

    protected function stats(int $low): array
    {
        $stats = [
            'total_variants' => 0, 'total_quantity' => 0, 'in_stock' => 0,
            'low_stock' => 0, 'out_of_stock' => 0, 'stock_value' => 0,
            'missing_cost' => 0, 'missing_cost_in_stock' => 0,
        ];
        try {
            $res = $this->api->inventoryStats($low);
            if ($res->successful()) {
                $stats = array_merge($stats, $res->json('data') ?? []);
            }
        } catch (\Throwable $e) {
            Log::info('Load inventory stats failed', ['msg' => $e->getMessage()]);
        }

        return $stats;
    }

    /** Danh mục cho ô lọc. Hỏng thì trả mảng rỗng, ô lọc chỉ còn "Tất cả". */
    protected function options(string $kind): array
    {
        try {
            $res = $this->api->categories(true);
            if ($res->successful()) {
                return $res->json('data') ?? [];
            }
        } catch (\Throwable $e) {
            Log::info('Load inventory filter options failed', ['kind' => $kind, 'msg' => $e->getMessage()]);
        }

        return [];
    }

    protected function fetchAll(array $filters): array
    {
        $all = [];
        $query = array_merge($this->toQuery($filters), ['page' => 1, 'page_size' => 100]);
        $totalPages = 1;
        try {
            do {
                $res = $this->api->inventory($query);
                if (! $res->successful()) {
                    break;
                }
                $all = array_merge($all, $res->json('data') ?? []);
                $totalPages = (int) ($res->json('meta.total_pages') ?? 1);
                $query['page']++;
            } while ($query['page'] <= $totalPages && $query['page'] <= 100);
        } catch (\Throwable $e) {
            Log::error('Export inventory failed', ['msg' => $e->getMessage()]);
        }

        return $all;
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

        return redirect()->route('admin.inventory.index', $request->query());
    }
}
