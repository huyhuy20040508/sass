<?php

namespace App\Http\Controllers;

use App\Services\ApiClient;
use App\Services\ImageStore;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Quản lý banner trang chủ — dựng theo đúng khuôn trang "Danh sách thương hiệu":
 * [ header ] + [ thanh lọc realtime ] + [ bảng compact ] + [ chân trang ] + modal CRUD.
 *
 * Hai điểm riêng của module này:
 *
 * 1. THỨ TỰ LÀ NỘI DUNG. Banner nằm trong một dải chạy nối tiếp nhau nên vị trí
 *    trên/dưới có nghĩa thật (slide nào lên trước). Vì vậy trang này sắp xếp
 *    CỐ ĐỊNH theo vị trí → sort_order, không cho đổi kiểu sắp xếp như trang khác;
 *    bù lại mỗi dòng có nút lên/xuống ghi thẳng thứ tự về API.
 *
 * 2. TRẠNG THÁI KHÔNG CHỈ LÀ BẬT/TẮT. Banner còn có lịch chạy, nên một banner
 *    đang bật vẫn có thể chưa hiện (chưa tới ngày) hoặc thôi hiện (đã qua ngày).
 *    Bảng hiện đúng 4 trạng thái đó thay vì chỉ nói "đang bật" rồi để người bán
 *    tự hỏi vì sao ngoài cửa hàng không thấy gì.
 */
class BannerController extends Controller
{
    public const TITLE = 'Banner trang chủ';

    /**
     * Vị trí hiển thị — danh sách ĐÓNG, phải khớp `domain.BannerPositions` bên API
     * và các khối đang dựng trong storefront/resources/views/home.blade.php.
     * Thêm mã mới ở đây mà không dựng khối bên storefront thì người bán tải ảnh
     * lên xong không thấy nó xuất hiện ở đâu cả.
     */
    public const POSITIONS = [
        'home_slider' => [
            'label' => 'Slideshow trang chủ',
            'hint' => 'Ảnh lớn chạy tự động ở đầu trang chủ. Khuyến nghị ảnh ngang 1920×700px.',
            'ratio' => '1920 × 700',
        ],
        'home_poster' => [
            'label' => 'Dải poster giữa trang',
            'hint' => 'Dải ảnh trượt ngang nằm dưới khối “Hàng mới về”. Khuyến nghị ảnh đứng 800×1000px.',
            'ratio' => '800 × 1000',
        ],
        'home_kids' => [
            'label' => 'Banner trẻ em',
            'hint' => 'Ảnh ngang full-width phía trên khối “Dành cho trẻ em”. Khuyến nghị 1920×500px.',
            'ratio' => '1920 × 500',
        ],
    ];

    /** Trạng thái hiển thị THỰC TẾ — tính từ công tắc bật/tắt cộng với lịch chạy. */
    public const STATUSES = [
        'live' => 'Đang hiện',
        'scheduled' => 'Chờ tới lịch',
        'expired' => 'Đã hết hạn',
        'hidden' => 'Đang tắt',
    ];

    public const PAGE_SIZES = [10, 20, 50, 100];

    public function __construct(protected ApiClient $api) {}

    /** Trang danh sách banner. */
    public function index(Request $request)
    {
        $filters = $this->filters($request);
        $error = null;
        $all = [];

        try {
            $res = $this->api->banners();
            if ($res->successful()) {
                $all = $res->json('data') ?? [];
            } else {
                Log::warning('Load banners failed', ['status' => $res->status()]);
                $error = $res->json('message') ?: 'Không tải được danh sách banner.';
            }
        } catch (\Throwable $e) {
            Log::error('Load banners failed', ['msg' => $e->getMessage()]);
            $error = 'Không tải được danh sách banner. Kiểm tra kết nối API.';
        }

        $all = $this->decorate($all);

        $stats = ['total' => count($all)] + array_fill_keys(array_keys(self::STATUSES), 0);
        foreach ($all as $b) {
            $stats[$b['state']]++;
        }

        $filtered = $this->applyFilters($all, $filters);
        $total = count($filtered);
        $totalPages = max(1, (int) ceil($total / $filters['page_size']));
        $page = min($filters['page'], $totalPages);

        $banners = array_slice($filtered, ($page - 1) * $filters['page_size'], $filters['page_size']);

        $view = view('banners.index', [
            'banners' => array_values($banners),
            'filters' => $filters + ['page' => $page],
            'stats' => $stats,
            // Đếm banner ĐANG HIỆN của từng vị trí: người bán cần biết ngay khối
            // nào trên trang chủ đang trống trước khi phải mở website ra xem.
            'byPosition' => $this->countByPosition($all),
            'meta' => [
                'page' => $page,
                'page_size' => $filters['page_size'],
                'total' => $total,
                'total_pages' => $totalPages,
            ],
        ]);

        return $error ? $view->with('error', $error) : $view;
    }

    /** Tạo banner. */
    public function store(Request $request)
    {
        $data = $this->validated($request);

        return $this->send(
            fn () => $this->api->createBanner($data),
            'Đã thêm banner vào “'.$this->positionLabel($data['position']).'”.',
            $request
        );
    }

    /** Cập nhật banner. */
    public function update(Request $request, int $id)
    {
        $data = $this->validated($request);

        return $this->send(
            fn () => $this->api->updateBanner($id, $data),
            'Đã cập nhật banner.',
            $request
        );
    }

    /** Bật/tắt hiển thị banner — giữ nguyên nội dung và lịch chạy. */
    public function toggleStatus(Request $request, int $id)
    {
        $validated = $request->validate([
            'is_active' => 'required|boolean',
        ], [
            'is_active.required' => 'Thiếu trạng thái cần đặt.',
        ]);

        $isActive = (bool) $validated['is_active'];

        return $this->send(
            fn () => $this->api->updateBannerStatus($id, $isActive),
            $isActive ? 'Đã bật hiển thị banner.' : 'Đã tắt hiển thị banner.',
            $request
        );
    }

    /**
     * Đổi chỗ một banner với banner liền kề trong CÙNG vị trí.
     *
     * Nhận id + hướng thay vì nhận sẵn thứ tự mới từ trình duyệt: danh sách bên
     * trình duyệt có thể đang bị cắt trang hoặc đã cũ, gửi nguyên mảng đó lên sẽ
     * ghi đè thứ tự của những banner không nằm trong trang đang xem.
     */
    public function move(Request $request, int $id)
    {
        $validated = $request->validate([
            'direction' => 'required|in:up,down',
        ], [
            'direction.required' => 'Thiếu hướng di chuyển.',
            'direction.in' => 'Hướng di chuyển không hợp lệ.',
        ]);

        $all = $this->fetchAll();
        $current = null;
        foreach ($all as $b) {
            if ((int) ($b['id'] ?? 0) === $id) {
                $current = $b;
                break;
            }
        }
        if ($current === null) {
            return $this->backToList($request)->with('error', 'Không tìm thấy banner cần di chuyển.');
        }

        // Chỉ những banner CÙNG vị trí mới xếp chung một dải với nhau.
        $group = array_values(array_filter(
            $all,
            fn ($b) => (string) ($b['position'] ?? '') === (string) ($current['position'] ?? '')
        ));
        $index = null;
        foreach ($group as $i => $b) {
            if ((int) ($b['id'] ?? 0) === $id) {
                $index = $i;
                break;
            }
        }

        if ($index === null) {
            return $this->backToList($request)->with('error', 'Không tìm thấy banner cần di chuyển.');
        }

        $target = $validated['direction'] === 'up' ? $index - 1 : $index + 1;
        if ($target < 0 || $target >= count($group)) {
            return $this->backToList($request)->with(
                'error',
                $validated['direction'] === 'up'
                    ? 'Banner này đã ở đầu dải, không lên được nữa.'
                    : 'Banner này đã ở cuối dải, không xuống được nữa.'
            );
        }

        [$group[$index], $group[$target]] = [$group[$target], $group[$index]];
        $ids = array_map(fn ($b) => (int) ($b['id'] ?? 0), $group);

        return $this->send(
            fn () => $this->api->sortBanners($ids),
            'Đã đổi thứ tự hiển thị.',
            $request
        );
    }

    /** Xoá banner. */
    public function destroy(Request $request, int $id)
    {
        return $this->send(
            fn () => $this->api->deleteBanner($id),
            'Đã xoá banner.',
            $request
        );
    }

    /** Xoá nhiều banner đã chọn. */
    public function bulkDestroy(Request $request)
    {
        $ids = collect($request->input('ids', []))
            ->map(fn ($v) => (int) $v)
            ->filter()
            ->unique()
            ->all();

        if (empty($ids)) {
            return $this->backToList($request)->with('error', 'Chưa chọn banner nào để xoá.');
        }

        $ok = 0;
        $fail = 0;
        foreach ($ids as $id) {
            try {
                $this->api->deleteBanner($id)->successful() ? $ok++ : $fail++;
            } catch (\Throwable $e) {
                Log::error('Bulk delete banner failed', ['id' => $id, 'msg' => $e->getMessage()]);
                $fail++;
            }
        }

        $redirect = $this->backToList($request);

        if ($fail === 0) {
            return $redirect->with('success', "Đã xoá {$ok} banner.");
        }

        return $redirect->with(
            $ok > 0 ? 'success' : 'error',
            $ok > 0 ? "Đã xoá {$ok} banner, {$fail} banner lỗi." : "Không xoá được banner nào ({$fail} lỗi)."
        );
    }

    /** Nhận ảnh banner từ modal, lưu vào public disk, trả URL tuyệt đối. */
    public function uploadImage(Request $request)
    {
        $request->validate([
            'image' => ImageStore::rules(),
        ], ImageStore::messages());

        return response()->json(['url' => ImageStore::put($request->file('image'), 'banners')]);
    }

    /** Xuất danh sách banner theo đúng bộ lọc đang xem. */
    public function export(Request $request)
    {
        $filters = $this->filters($request);
        $banners = $this->applyFilters($this->decorate($this->fetchAll()), $filters);
        $fileName = 'danh-sach-banner-'.date('Ymd-His').'.csv';

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$fileName}\"",
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0',
        ];

        $callback = function () use ($banners) {
            $file = fopen('php://output', 'w');
            fprintf($file, chr(0xEF).chr(0xBB).chr(0xBF));
            fputcsv($file, ['ID', 'Tiêu đề', 'Vị trí', 'Thứ tự', 'Ảnh', 'Liên kết', 'Bắt đầu', 'Kết thúc', 'Trạng thái', 'Ngày tạo']);

            foreach ($banners as $b) {
                fputcsv($file, [
                    $b['id'] ?? '',
                    $b['title'] ?? '',
                    $this->positionLabel((string) ($b['position'] ?? '')),
                    (int) ($b['sort_order'] ?? 0) + 1,
                    $b['image'] ?? '',
                    $b['link'] ?? '',
                    $this->formatDate($b['start_at'] ?? null),
                    $this->formatDate($b['end_at'] ?? null),
                    self::STATUSES[$b['state']] ?? '',
                    $this->formatDate($b['created_at'] ?? null),
                ]);
            }
            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    protected function filters(Request $request): array
    {
        $status = (string) $request->query('status', 'all');
        $position = (string) $request->query('position', 'all');
        $pageSize = (int) $request->query('page_size', 20);

        return [
            'keyword' => trim((string) $request->query('keyword', '')),
            'position' => isset(self::POSITIONS[$position]) ? $position : 'all',
            'status' => isset(self::STATUSES[$status]) ? $status : 'all',
            'page' => max(1, (int) $request->query('page', 1)),
            'page_size' => in_array($pageSize, self::PAGE_SIZES, true) ? $pageSize : 20,
        ];
    }

    /**
     * Gắn thêm các trường mà bảng cần nhưng API không trả:
     * - state: trạng thái hiển thị thực tế (live / scheduled / expired / hidden)
     * - rank:  số thứ tự trong dải của vị trí đó, đếm từ 1
     *
     * API đã trả danh sách sắp sẵn theo position → sort_order → id, nên chỉ cần
     * đếm tuần tự là ra đúng thứ hiện trên trang chủ.
     */
    protected function decorate(array $banners): array
    {
        $now = Carbon::now();
        $seen = [];

        foreach ($banners as &$b) {
            $position = (string) ($b['position'] ?? '');
            $seen[$position] = ($seen[$position] ?? 0) + 1;
            $b['rank'] = $seen[$position];
            $b['state'] = $this->state($b, $now);
        }
        unset($b);

        return $banners;
    }

    /** Trạng thái hiển thị thực tế của một banner tại thời điểm $now. */
    protected function state(array $banner, Carbon $now): string
    {
        if (! ($banner['is_active'] ?? false)) {
            return 'hidden';
        }

        $start = $this->parseDate($banner['start_at'] ?? null);
        $end = $this->parseDate($banner['end_at'] ?? null);

        if ($start !== null && $now->lt($start)) {
            return 'scheduled';
        }
        if ($end !== null && $now->gt($end)) {
            return 'expired';
        }

        return 'live';
    }

    /** Đếm số banner ĐANG HIỆN của từng vị trí (kể cả vị trí chưa có banner nào). */
    protected function countByPosition(array $banners): array
    {
        $counts = array_fill_keys(array_keys(self::POSITIONS), 0);
        foreach ($banners as $b) {
            $position = (string) ($b['position'] ?? '');
            if (isset($counts[$position]) && $b['state'] === 'live') {
                $counts[$position]++;
            }
        }

        return $counts;
    }

    /**
     * Tìm kiếm + lọc vị trí + lọc trạng thái (làm tại đây vì API trả nguyên danh sách).
     *
     * KHÔNG có tuỳ chọn sắp xếp: thứ tự của bảng chính là thứ tự chạy ngoài cửa
     * hàng — cho đổi kiểu sắp xếp thì nút lên/xuống trên mỗi dòng sẽ di chuyển
     * banner theo một thứ tự khác hẳn thứ người dùng đang nhìn.
     */
    protected function applyFilters(array $banners, array $filters): array
    {
        $list = collect($banners);

        if ($filters['keyword'] !== '') {
            $kw = mb_strtolower($filters['keyword'], 'UTF-8');
            $list = $list->filter(function ($b) use ($kw) {
                foreach (['title', 'link'] as $field) {
                    if (str_contains(mb_strtolower((string) ($b[$field] ?? ''), 'UTF-8'), $kw)) {
                        return true;
                    }
                }

                return false;
            });
        }

        if ($filters['position'] !== 'all') {
            $list = $list->filter(fn ($b) => (string) ($b['position'] ?? '') === $filters['position']);
        }

        if ($filters['status'] !== 'all') {
            $list = $list->filter(fn ($b) => $b['state'] === $filters['status']);
        }

        return $list->values()->all();
    }

    /** Dữ liệu banner từ modal thêm/sửa. */
    protected function validated(Request $request): array
    {
        // `after_or_equal:start_at` chỉ gắn khi mốc bắt đầu THỰC SỰ được gửi lên.
        // Tick "Vô thời hạn" ở ô bắt đầu làm trường đó vắng mặt, mà luật này gặp
        // trường vắng mặt thì quay ra đọc "start_at" như một chuỗi ngày, parse hỏng
        // rồi báo lỗi oan cho một cặp mốc hoàn toàn hợp lệ.
        $endRules = ['nullable', 'date_format:Y-m-d\TH:i'];
        if ($request->filled('start_at')) {
            $endRules[] = 'after_or_equal:start_at';
        }

        $validated = $request->validate([
            'title' => ['nullable', 'string', 'max:200'],
            'image' => ['required', 'string', 'max:255'],
            'link' => ['nullable', 'string', 'max:255'],
            'position' => ['required', 'string', 'in:'.implode(',', array_keys(self::POSITIONS))],
            'start_at' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'end_at' => $endRules,
            'is_active' => ['nullable'],
        ], [
            'image.required' => 'Vui lòng chọn ảnh banner.',
            'image.max' => 'Đường dẫn ảnh tối đa 255 ký tự.',
            'title.max' => 'Tiêu đề tối đa 200 ký tự.',
            'link.max' => 'Liên kết tối đa 255 ký tự.',
            'position.required' => 'Vui lòng chọn vị trí hiển thị.',
            'position.in' => 'Vị trí hiển thị không hợp lệ.',
            'start_at.date_format' => 'Ngày bắt đầu không hợp lệ.',
            'end_at.date_format' => 'Ngày kết thúc không hợp lệ.',
            'end_at.after_or_equal' => 'Ngày kết thúc phải sau ngày bắt đầu.',
        ]);

        return [
            'title' => (string) ($validated['title'] ?? ''),
            'image' => $validated['image'],
            'link' => (string) ($validated['link'] ?? ''),
            'position' => $validated['position'],
            'start_at' => (string) ($validated['start_at'] ?? ''),
            'end_at' => (string) ($validated['end_at'] ?? ''),
            'is_active' => $request->boolean('is_active'),
        ];
    }

    protected function positionLabel(string $code): string
    {
        return self::POSITIONS[$code]['label'] ?? $code;
    }

    /** Carbon::parse giữ nguyên offset API trả về; chuỗi rỗng/null trả null. */
    protected function parseDate(?string $value): ?Carbon
    {
        if (empty($value)) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected function formatDate(?string $value): string
    {
        return $this->parseDate($value)?->format('d/m/Y H:i') ?? '';
    }

    protected function fetchAll(): array
    {
        try {
            $res = $this->api->banners();
            if ($res->successful()) {
                return $res->json('data') ?? [];
            }
        } catch (\Throwable $e) {
            Log::error('Fetch all banners failed', ['msg' => $e->getMessage()]);
        }

        return [];
    }

    protected function send(callable $call, string $success, Request $request)
    {
        try {
            $res = $call();
        } catch (\Throwable $e) {
            Log::error('Banner API call failed', ['msg' => $e->getMessage()]);

            return $this->backToList($request)->with('error', 'Không kết nối được API. Vui lòng thử lại.');
        }

        if ($res->successful()) {
            return $this->backToList($request)->with('success', $success);
        }

        return $this->backToList($request)
            ->withInput()
            ->with('error', $res->json('message') ?: 'Thao tác không thành công.');
    }

    protected function backToList(Request $request)
    {
        $return = $request->input('return');
        if (is_string($return) && str_starts_with($return, '/')) {
            return redirect($return);
        }

        return redirect()->route('admin.banners.index', $request->query());
    }
}
