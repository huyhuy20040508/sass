<?php

namespace App\Http\Controllers;

use App\Services\ApiClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * CRM → Thẻ thành viên — khuôn crm/membership-rank của bản v2 ("Giảm giá theo
 * xếp hạng"): bảng hạng, hai quy đổi điểm (tiền → điểm để tích, điểm → tiền để
 * đổi ở quầy) và trang chi tiết một hạng (chính sách + khách đang ở hạng).
 *
 * Mọi lượt sửa bảng hạng đều xếp hạng lại toàn bộ khách ở API.
 */
class MembershipController extends Controller
{
    use \App\Http\Controllers\Concerns\DialogReply;

    public const PAGE_SIZES = [10, 20, 30, 40, 50];

    public function __construct(protected ApiClient $api) {}

    public function index(Request $request)
    {
        $pageSize = (int) $request->query('page_size', 10);
        $pageSize = in_array($pageSize, self::PAGE_SIZES, true) ? $pageSize : 10;
        $ranks = [];
        $conversion = [];
        $error = null;
        try {
            $res = $this->api->theThanhVien();
            if ($res->successful()) {
                $ranks = $res->json('data.ranks') ?? [];
                $conversion = $res->json('data.conversion') ?? [];
            } else {
                $error = $res->json('message') ?: 'Không tải được danh sách hạng thành viên.';
            }
        } catch (\Throwable $e) {
            Log::error('Load membership ranks failed', ['msg' => $e->getMessage()]);
            $error = 'Không tải được danh sách hạng thành viên. Kiểm tra kết nối API.';
        }

        // Hạng ít (vài dòng) — API trả trọn, trang cắt ở đây như ô "Hiển thị 10" của v2.
        $page = max(1, (int) $request->query('page', 1));
        $total = count($ranks);
        $meta = ['page' => $page, 'page_size' => $pageSize, 'total' => $total, 'total_pages' => max(1, (int) ceil($total / $pageSize))];

        $view = view('v2::crm.membership.index', [
            'ranks' => array_slice($ranks, ($page - 1) * $pageSize, $pageSize),
            'conversion' => $conversion,
            'meta' => $meta,
            'pageSize' => $pageSize,
        ]);

        return $error ? $view->with('error', $error) : $view;
    }

    public function detail(Request $request, int $id)
    {
        $keyword = trim((string) $request->query('keyword', ''));
        $pageSize = (int) $request->query('page_size', 10);
        $pageSize = in_array($pageSize, self::PAGE_SIZES, true) ? $pageSize : 10;
        $page = max(1, (int) $request->query('page', 1));

        try {
            $rank = $this->api->hangThanhVien($id);
            if ($rank->status() === 404) {
                abort(404);
            }
            $members = $this->api->khachCuaHang($id, array_filter(['keyword' => $keyword, 'page' => $page, 'page_size' => $pageSize], fn ($v) => $v !== ''));
            $all = $this->api->theThanhVien();
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Load membership rank detail failed', ['msg' => $e->getMessage()]);

            return redirect()->route('admin.crm.membership.index')->with('error', 'Không tải được hạng thành viên. Kiểm tra kết nối API.');
        }

        return view('v2::crm.membership.detail', [
            'rank' => $rank->json('data') ?? [],
            'members' => $members->json('data') ?? [],
            'meta' => array_merge(['page' => $page, 'page_size' => $pageSize, 'total' => 0, 'total_pages' => 1], $members->json('meta') ?? []),
            'conversion' => $all->json('data.conversion') ?? [],
            'keyword' => $keyword,
            'pageSize' => $pageSize,
        ]);
    }

    public function store(Request $request)
    {
        return $this->luu($request, fn ($d) => $this->api->taoHangThanhVien($d), 'Thêm hạng thành viên thành công.');
    }

    public function update(Request $request, int $id)
    {
        return $this->luu($request, fn ($d) => $this->api->suaHangThanhVien($id, $d), 'Cập nhật hạng thành viên thành công.');
    }

    public function destroy(Request $request)
    {
        $ids = array_values(array_filter(array_map('intval', (array) $request->input('ids', []))));
        if ($ids === []) {
            return $this->traLoiHopThoai($request, false, 'Vui lòng chọn hạng cần xoá.');
        }

        return $this->traLoi($request, fn () => $this->api->xoaHangThanhVien($ids), 'Xoá thành công.');
    }

    /** Một trong hai quy đổi: earn (tiền → điểm) hoặc redeem (điểm → tiền). */
    public function conversion(Request $request)
    {
        $so = fn ($v) => (float) preg_replace('/[^\d.]/', '', (string) $v);
        $v = $request->validate([
            'kind' => ['required', 'in:earn,redeem'],
            'money' => ['required'],
            'point' => ['required', 'integer', 'min:0'],
            'enabled' => ['nullable', 'boolean'],
        ], ['money.required' => 'Nhập số tiền.', 'point.required' => 'Nhập số điểm.']);

        return $this->traLoi($request, fn () => $this->api->luuQuyDoiDiem([
            'kind' => $v['kind'], 'money' => $so($v['money']), 'point' => (int) $v['point'], 'enabled' => $request->boolean('enabled'),
        ]), 'Lưu quy đổi điểm thành công.');
    }

    protected function luu(Request $request, callable $gui, string $xong)
    {
        $so = fn ($v) => $v === null || $v === '' ? null : (float) preg_replace('/[^\d.]/', '', (string) $v);
        $request->merge(['discount_value' => $so($request->input('discount_value')),
            'min_order_value' => $so($request->input('min_order_value')), 'max_order_value' => $so($request->input('max_order_value'))]);
        $v = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'point' => ['required', 'integer', 'min:1'],
            'discount_type' => ['required', 'in:money,percent'],
            'discount_value' => ['required', 'numeric', 'min:0'],
            'status' => ['nullable', 'boolean'],
            'apply_all_order_values' => ['nullable', 'boolean'],
            'min_order_value' => ['nullable', 'numeric', 'min:0'],
            'max_order_value' => ['nullable', 'numeric', 'min:0'],
        ], [
            'name.required' => 'Nhập tên hạng.',
            'point.required' => 'Nhập số điểm.',
            'point.min' => 'Số điểm phải lớn hơn 0.',
            'discount_value.required' => 'Nhập giá trị giảm.',
        ]);
        $tatCa = $request->boolean('apply_all_order_values');

        return $this->traLoi($request, fn () => $gui([
            'name' => $v['name'],
            'point' => (int) $v['point'],
            'discount_type' => $v['discount_type'],
            'discount_value' => (float) $v['discount_value'],
            'status' => $request->boolean('status'),
            'apply_all_order_values' => $tatCa,
            'min_order_value' => $tatCa ? null : ($v['min_order_value'] ?? null),
            'max_order_value' => $tatCa ? null : ($v['max_order_value'] ?? null),
        ]), $xong);
    }

    protected function traLoi(Request $request, callable $goi, string $xong)
    {
        try {
            $res = $goi();
        } catch (\Throwable $e) {
            Log::error('Membership API call failed', ['msg' => $e->getMessage()]);

            return $this->traLoiHopThoai($request, false, 'Không kết nối được API. Vui lòng thử lại.');
        }

        return $res->successful()
            ? $this->traLoiHopThoai($request, true, $xong, fn () => redirect()->route('admin.crm.membership.index'))
            : $this->traLoiHopThoai($request, false, $this->cauLoiApi($res, 'Thao tác không thành công.'), null, $res->status());
    }
}
