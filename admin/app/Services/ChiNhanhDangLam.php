<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * ChiNhanhDangLam — danh sách chi nhánh cho ô chọn ở thanh trên cùng.
 *
 * Đứng riêng thành service (thay vì nhét vào mọi controller) vì thanh trên cùng
 * nằm trong layout: nó hiện ở MỌI trang, mà không trang nào "sở hữu" nó. Bắt
 * từng controller truyền danh sách chi nhánh xuống view là hai mươi chỗ phải
 * nhớ, và chỗ quên sẽ làm ô chọn biến mất đúng ở trang đó — người dùng đổi kho
 * xong đi sang trang khác thì ô lại trống.
 *
 * CACHE TRONG MỘT REQUEST: layout dựng một lần nên thực tế chỉ gọi API một lượt,
 * nhưng biến static giữ cho chắc — thêm một khối nào đó cũng cần danh sách này
 * thì không thành lượt gọi thứ hai.
 */
class ChiNhanhDangLam
{
    /** @var array{ds: array, dangChon: ?int}|null */
    protected static ?array $nho = null;

    /**
     * Trả về ['ds' => danh sách chi nhánh ĐANG MỞ, 'dangChon' => id hoặc null].
     *
     * Hỏng thì trả danh sách rỗng chứ không ném lỗi: API trục trặc thì cùng lắm
     * mất ô chọn chi nhánh trong chốc lát, không được phép làm trắng cả trang
     * quản trị — thanh trên cùng có mặt ở mọi trang, nên một lỗi ở đây là lỗi ở
     * khắp nơi.
     */
    public static function danhSach(): array
    {
        if (self::$nho !== null) {
            return self::$nho;
        }

        $dangChon = session(ApiClient::KHOA_CHI_NHANH);
        $ds = [];

        try {
            // Chỉ chi nhánh ĐANG MỞ: mời người dùng chọn một kho đã đóng là mời họ
            // ghi hàng vào chỗ không còn bán.
            $res = app(ApiClient::class)->chiNhanh(onlyActive: true);
            if ($res->successful()) {
                $ds = $res->json('data') ?? [];
            }
        } catch (\Throwable $e) {
            Log::warning('Load chi nhanh cho topbar failed', ['msg' => $e->getMessage()]);
        }

        // Chi nhánh đang chọn vừa bị đóng/xoá: bỏ lựa chọn đi thay vì giữ một id
        // mà API sẽ từ chối ở mọi request sau đó.
        if ($dangChon !== null && ! collect($ds)->contains(fn ($cn) => (int) $cn['id'] === (int) $dangChon)) {
            session()->forget(ApiClient::KHOA_CHI_NHANH);
            $dangChon = null;
        }

        return self::$nho = ['ds' => $ds, 'dangChon' => $dangChon === null ? null : (int) $dangChon];
    }

    /**
     * Tên chi nhánh đang làm việc, null nghĩa là đang xem GỘP mọi chi nhánh.
     *
     * Có riêng hàm này vì các trang kho phải NÓI RA mình đang hiển thị kho nào.
     * Con số tồn đổi hẳn ý nghĩa theo lựa chọn ở thanh trên cùng — tồn của một
     * điểm bán hay bản cộng của cả cửa hàng — mà ô chọn đó lại chỉ hiện khi cửa
     * hàng có từ hai chi nhánh, nên không thể trông vào nó để người dùng tự hiểu.
     */
    public static function ten(): ?string
    {
        $d = self::danhSach();
        if ($d['dangChon'] === null) {
            return null;
        }

        foreach ($d['ds'] as $cn) {
            if ((int) $cn['id'] === $d['dangChon']) {
                return (string) ($cn['name'] ?? '');
            }
        }

        return null;
    }
}
