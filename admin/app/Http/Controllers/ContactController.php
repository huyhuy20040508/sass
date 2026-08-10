<?php

namespace App\Http\Controllers;

use App\Services\ApiClient;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * ContactController — trang "Yêu cầu của khách" trong khu quản trị.
 *
 * Đây là HỘP THƯ ĐẾN của cửa hàng: mọi thứ khách gửi từ storefront qua form Liên
 * hệ và form Thu mua áo đấu đều rơi về đây, kèm trạng thái xử lý để nhân viên
 * biết cái nào đã gọi lại rồi, cái nào còn bỏ ngỏ.
 *
 * Trước khi có trang này, hai form kia chỉ hiện một hộp thoại "cảm ơn" rồi vứt
 * sạch dữ liệu — không ai trong cửa hàng biết là có khách vừa nhắn.
 *
 * Trang "Đăng ký nhận tin" (danh sách email ở chân trang) cũng nằm trong lớp
 * này: cùng một nguồn dữ liệu — thứ khách chủ động để lại.
 */
class ContactController extends Controller
{
    public const TITLE = 'Yêu cầu của khách';

    public const TITLE_NEWSLETTER = 'Đăng ký nhận tin';

    /** Hai loại yêu cầu, khớp cột `type` bên CSDL. */
    public const TYPES = [
        'lien-he' => 'Liên hệ',
        'thu-mua' => 'Thu mua',
    ];

    /** Trạng thái xử lý, theo đúng thứ tự vòng đời. */
    public const STATUSES = [
        'moi' => 'Mới',
        'dang-xu-ly' => 'Đang xử lý',
        'da-xong' => 'Đã xong',
    ];

    /** Màu huy hiệu trạng thái — dùng lại bộ tone sẵn có của các trang khác. */
    public const STATUS_TONES = [
        'moi' => 'warn',
        'dang-xu-ly' => 'info',
        'da-xong' => 'ok',
    ];

    /**
     * Bước kế tiếp của từng trạng thái.
     *
     * Nút trên mỗi hàng hiện đúng MỘT việc — việc hợp lý tiếp theo — thay vì bày
     * cả ba trạng thái ra bắt người dùng tự chọn (khuôn chung của khu quản trị).
     */
    public const NEXT_STATUS = [
        'moi' => 'dang-xu-ly',
        'dang-xu-ly' => 'da-xong',
    ];

    public const PAGE_SIZES = [20, 50, 100];

    public const EMPTY_TEXT = 'Chưa có yêu cầu nào. Khách gửi form ở trang Liên hệ hoặc Thu mua thì sẽ hiện tại đây.';

    public const EMPTY_TEXT_NEWSLETTER = 'Chưa có ai đăng ký nhận tin. Ô đăng ký nằm ở chân mọi trang của website bán hàng.';

    public function __construct(protected ApiClient $api) {}

    /** Danh sách yêu cầu (liên hệ + thu mua). */
    public function index(Request $request)
    {
        $filters = $this->filters($request);
        $items = [];
        $meta = ['page' => $filters['page'], 'page_size' => $filters['page_size'], 'total' => 0, 'total_pages' => 1];
        $error = null;

        try {
            $res = $this->api->contactRequests($filters);
            if ($res->successful()) {
                $items = $res->json('data') ?? [];
                $meta = array_merge($meta, $res->json('meta') ?? []);
            } else {
                Log::warning('Load contact requests failed', ['status' => $res->status()]);
                $error = $res->json('message') ?: 'Không tải được danh sách yêu cầu.';
            }
        } catch (\Throwable $e) {
            Log::error('Load contact requests failed', ['msg' => $e->getMessage()]);
            $error = 'Không tải được danh sách yêu cầu. Kiểm tra kết nối API.';
        }

        $view = view('contacts.index', compact('items', 'filters', 'meta'))
            ->with('stats', $this->stats());

        return $error ? $view->with('error', $error) : $view;
    }

    /** Đổi trạng thái xử lý một yêu cầu. */
    public function updateStatus(Request $request, int $id)
    {
        $data = $request->validate([
            'status' => 'required|in:'.implode(',', array_keys(self::STATUSES)),
            'admin_note' => 'nullable|string|max:500',
        ]);

        try {
            $res = $this->api->updateContactStatus($id, [
                'status' => $data['status'],
                'admin_note' => (string) ($data['admin_note'] ?? ''),
            ]);
            if ($res->successful()) {
                return back()->with('success', 'Đã chuyển sang "'.self::STATUSES[$data['status']].'".');
            }

            return back()->with('error', $res->json('message') ?: 'Không đổi được trạng thái.');
        } catch (\Throwable $e) {
            Log::error('Update contact status failed', ['id' => $id, 'msg' => $e->getMessage()]);

            return back()->with('error', 'Không đổi được trạng thái. Kiểm tra kết nối API.');
        }
    }

    /** Xoá một yêu cầu (xoá mềm bên API). */
    public function destroy(int $id)
    {
        try {
            $res = $this->api->deleteContactRequest($id);
            if ($res->successful()) {
                return back()->with('success', 'Đã xoá yêu cầu.');
            }

            return back()->with('error', $res->json('message') ?: 'Không xoá được yêu cầu.');
        } catch (\Throwable $e) {
            Log::error('Delete contact request failed', ['id' => $id, 'msg' => $e->getMessage()]);

            return back()->with('error', 'Không xoá được yêu cầu. Kiểm tra kết nối API.');
        }
    }

    /** Danh sách email đăng ký nhận tin. */
    public function newsletter(Request $request)
    {
        $filters = [
            'keyword' => trim((string) $request->query('keyword', '')),
            'status' => (string) $request->query('status', 'all'),
            'page' => max(1, (int) $request->query('page', 1)),
            'page_size' => $this->pageSize($request),
        ];

        $items = [];
        $meta = ['page' => $filters['page'], 'page_size' => $filters['page_size'], 'total' => 0, 'total_pages' => 1];
        $error = null;

        try {
            $res = $this->api->newsletterSubscribers($filters);
            if ($res->successful()) {
                $items = $res->json('data') ?? [];
                $meta = array_merge($meta, $res->json('meta') ?? []);
            } else {
                $error = $res->json('message') ?: 'Không tải được danh sách đăng ký nhận tin.';
            }
        } catch (\Throwable $e) {
            Log::error('Load newsletter failed', ['msg' => $e->getMessage()]);
            $error = 'Không tải được danh sách đăng ký nhận tin. Kiểm tra kết nối API.';
        }

        $view = view('contacts.newsletter', compact('items', 'filters', 'meta'));

        return $error ? $view->with('error', $error) : $view;
    }

    /** Gỡ một email khỏi danh sách nhận tin. */
    public function unsubscribe(int $id)
    {
        try {
            $res = $this->api->unsubscribeNewsletter($id);
            if ($res->successful()) {
                return back()->with('success', 'Đã gỡ khỏi danh sách nhận tin.');
            }

            return back()->with('error', $res->json('message') ?: 'Không gỡ được.');
        } catch (\Throwable $e) {
            Log::error('Unsubscribe failed', ['id' => $id, 'msg' => $e->getMessage()]);

            return back()->with('error', 'Không gỡ được. Kiểm tra kết nối API.');
        }
    }

    /** Xuất danh sách yêu cầu theo đúng bộ lọc đang xem. */
    public function export(Request $request)
    {
        $filters = $this->filters($request);
        $filters['page'] = 1;
        $filters['page_size'] = 200;

        $rows = [];
        try {
            // Lấy tối đa 10 trang (2000 dòng): đủ cho mọi nhu cầu xuất file thật,
            // mà vẫn có trần để một bộ lọc quá rộng không kéo cả bảng về.
            for ($page = 1; $page <= 10; $page++) {
                $filters['page'] = $page;
                $res = $this->api->contactRequests($filters);
                if (! $res->successful()) {
                    break;
                }
                $data = $res->json('data') ?? [];
                $rows = array_merge($rows, $data);
                if (count($data) < $filters['page_size']) {
                    break;
                }
            }
        } catch (\Throwable $e) {
            Log::error('Export contact requests failed', ['msg' => $e->getMessage()]);
        }

        $fileName = 'yeu-cau-khach-'.date('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            // BOM để Excel trên Windows mở ra không vỡ dấu tiếng Việt.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Ngày gửi', 'Loại', 'Họ tên', 'Điện thoại', 'Email',
                'Địa chỉ', 'Chủ đề', 'Nội dung', 'Số ảnh', 'Trạng thái', 'Ghi chú', 'Người xử lý']);

            foreach ($rows as $r) {
                fputcsv($out, [
                    ! empty($r['created_at']) ? Carbon::parse($r['created_at'])->format('d/m/Y H:i') : '',
                    self::TYPES[$r['type'] ?? ''] ?? ($r['type'] ?? ''),
                    $r['full_name'] ?? '',
                    $r['phone'] ?? '',
                    $r['email'] ?? '',
                    $r['address'] ?? '',
                    $r['subject'] ?? '',
                    $r['content'] ?? '',
                    count($r['images'] ?? []),
                    self::STATUSES[$r['status'] ?? ''] ?? ($r['status'] ?? ''),
                    $r['admin_note'] ?? '',
                    $r['handler_name'] ?? '',
                ]);
            }
            fclose($out);
        }, $fileName, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    // ---------- Helpers ----------

    protected function filters(Request $request): array
    {
        return [
            'keyword' => trim((string) $request->query('keyword', '')),
            'type' => (string) $request->query('type', 'all'),
            'status' => (string) $request->query('status', 'all'),
            'from' => (string) $request->query('from', ''),
            'to' => (string) $request->query('to', ''),
            'page' => max(1, (int) $request->query('page', 1)),
            'page_size' => $this->pageSize($request),
        ];
    }

    protected function pageSize(Request $request): int
    {
        $size = (int) $request->query('page_size', 20);

        return in_array($size, self::PAGE_SIZES, true) ? $size : 20;
    }

    /** Số yêu cầu theo trạng thái; API hỏng thì trả 0 hết chứ không làm vỡ trang. */
    protected function stats(): array
    {
        $rong = ['moi' => 0, 'dang-xu-ly' => 0, 'da-xong' => 0];

        try {
            $res = $this->api->contactStats();
            if ($res->successful()) {
                return array_merge($rong, $res->json('data') ?? []);
            }
        } catch (\Throwable $e) {
            Log::warning('Load contact stats failed', ['msg' => $e->getMessage()]);
        }

        return $rong;
    }
}
