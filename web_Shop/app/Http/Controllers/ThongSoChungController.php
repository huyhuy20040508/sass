<?php

namespace App\Http\Controllers;

use App\Services\ApiClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Thông số chung — khu cấu hình bộ khung của tiệm, dựng lại theo bản ERP cũ
 * (order v2: Hệ thống → Thông số chung).
 *
 * Mới có MỘT trang: Quy tắc đánh số chứng từ. Các trang còn lại của bản cũ
 * (Quản trị, Thu ngân, Bếp, QR bàn, Kho, Thiết bị) làm sau — thêm một dòng vào
 * PAGES là có mục mới trong thanh bên.
 */
class ThongSoChungController extends Controller
{
    public const TITLE = 'Thông số chung';

    /**
     * Mỗi trang con là một mục trong thanh bên trái, khoá mảng cũng là đuôi URL.
     * `sub` nói điều người sửa CHƯA biết, không diễn giải lại tiêu đề.
     */
    public const PAGES = [
        'quy-tac-danh-so' => [
            'title' => 'Quy tắc đánh số chứng từ',
            'sub' => 'Đặt hình dạng mã cho từng loại chứng từ và danh mục. Chỉ áp cho phiếu lập SAU khi lưu — mã cũ giữ nguyên.',
        ],
    ];

    /**
     * Ba kiểu phần giá trị, mã khớp `domain.Phan*` bên Go.
     * Nhãn nói ra mã trông thế nào, vì tên kiểu không tự nói được điều đó.
     */
    public const PHAN_GIA_TRI = [
        'so-thu-tu' => 'Số thứ tự',
        'ngay-thang-nam' => 'Ngày tháng năm',
        'thang-nam' => 'Tháng năm',
    ];

    /** Khớp `domain.DoDaiMa*` — API từ chối nếu vượt, form chặn trước cho đỡ mất công. */
    public const DO_DAI_MIN = 3;

    public const DO_DAI_MAX = 20;

    /** Độ dài phần giá trị của một loại vừa được bật. */
    public const DO_DAI_MAC_DINH = 6;

    public function __construct(protected ApiClient $api) {}

    /** Vào khu này mà không nói trang nào thì mở trang đầu. */
    public function index()
    {
        return redirect()->route('admin.thong-so-chung.quy-tac-danh-so');
    }

    /**
     * Bảng quy tắc đánh số: chọn chi nhánh ở trên, sửa bảng ở dưới.
     *
     * Đọc MỘT lượt toàn bộ quy tắc của cửa hàng rồi để trang tự đổi chi nhánh —
     * bản cũ gọi lại máy chủ sau mỗi lần bấm, cho một bảng chưa tới chục dòng.
     */
    public function quyTacDanhSo()
    {
        $error = null;
        $chiNhanh = [];
        $loai = [];
        $quyTac = [];

        try {
            $res = $this->api->chiNhanh();
            if ($res->successful()) {
                $chiNhanh = $res->json('data') ?? [];
            } else {
                $error = $res->json('message') ?: 'Không tải được danh sách chi nhánh.';
            }

            $res = $this->api->quyTacMa();
            if ($res->successful()) {
                $loai = $res->json('data.loai') ?? [];
                $quyTac = $res->json('data.quy_tac') ?? [];
            } else {
                $error ??= $res->json('message') ?: 'Không tải được quy tắc đánh số.';
            }
        } catch (\Throwable $e) {
            Log::error('Load quy tac danh so failed', ['msg' => $e->getMessage()]);
            $error = 'Không tải được quy tắc đánh số. Kiểm tra kết nối API.';
        }

        $view = view('thong-so-chung.quy-tac-danh-so', [
            'page' => 'quy-tac-danh-so',
            'meta' => self::PAGES['quy-tac-danh-so'],
            'chiNhanh' => $chiNhanh,
            'loai' => $loai,
            'dangLuu' => $this->xepTheoPhamVi($quyTac),
        ]);

        return $error ? $view->with('error', $error) : $view;
    }

    /**
     * Lưu bảng quy tắc của chi nhánh đang chọn.
     *
     * Ô nào bị bỏ tick thì trình duyệt không gửi lên (input đã disabled), và API
     * hiểu đó là tắt — đúng một cách nói cho một chuyện.
     */
    public function luuQuyTacDanhSo(Request $request)
    {
        $du = $request->validate([
            'shop_id' => ['required', 'integer', 'min:1'],
            'rules' => ['nullable', 'array'],
            'rules.*.prefix' => ['nullable', 'string', 'max:20'],
            'rules.*.value_part' => ['required', 'string', 'in:'.implode(',', array_keys(self::PHAN_GIA_TRI))],
            'rules.*.length' => ['required', 'integer', 'min:'.self::DO_DAI_MIN, 'max:'.self::DO_DAI_MAX],
            'rules.*.suffix' => ['nullable', 'string', 'max:20'],
        ], [], $this->nhanO());

        $quyTac = [];
        foreach ($du['rules'] ?? [] as $docType => $dong) {
            $quyTac[] = [
                'doc_type' => (string) $docType,
                'prefix' => trim((string) ($dong['prefix'] ?? '')),
                'value_part' => (string) $dong['value_part'],
                'length' => (int) $dong['length'],
                'suffix' => trim((string) ($dong['suffix'] ?? '')),
            ];
        }

        try {
            $res = $this->api->luuQuyTacMa((int) $du['shop_id'], $quyTac);
        } catch (\Throwable $e) {
            Log::error('Save quy tac danh so failed', ['msg' => $e->getMessage()]);

            return back()->withInput()->with('error', 'Không kết nối được API. Vui lòng thử lại.');
        }

        if ($res->successful()) {
            return redirect()
                ->route('admin.thong-so-chung.quy-tac-danh-so', ['cn' => $du['shop_id']])
                ->with('success', 'Đã lưu quy tắc đánh số chứng từ.');
        }

        // API trả lỗi theo tên ô (prefix, length…) nhưng không nói dòng nào, nên
        // in thành một câu chung thay vì gắn bừa vào một ô.
        $loi = $res->json('errors');

        return back()->withInput()->with('error', is_array($loi) && $loi
            ? implode(' ', $loi)
            : ($res->json('message') ?: 'Lưu quy tắc đánh số không thành công.'));
    }

    /**
     * Xếp quy tắc đã lưu thành `[phạm vi][loại] => quy tắc`.
     *
     * Phạm vi 0 là bộ dùng chung toàn cửa hàng (hàng hoá, nhà cung cấp, nhân
     * viên); các số còn lại là id chi nhánh.
     */
    protected function xepTheoPhamVi(array $quyTac): array
    {
        $out = [];
        foreach ($quyTac as $q) {
            $out[(int) ($q['shop_id'] ?? 0)][(string) ($q['doc_type'] ?? '')] = $q;
        }

        return $out;
    }

    /** Tên ô trong câu báo lỗi: `rules.don-hang.length` đọc không ra nghĩa gì. */
    protected function nhanO(): array
    {
        return [
            'rules.*.prefix' => 'tiền tố',
            'rules.*.value_part' => 'phần giá trị',
            'rules.*.length' => 'số ký tự',
            'rules.*.suffix' => 'hậu tố',
        ];
    }
}
