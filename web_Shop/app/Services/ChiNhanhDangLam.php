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

        // Đọc qua ApiClient chứ không thẳng session: chi nhánh của TAB (tham số
        // `chi_nhanh`) phải thắng giá trị dùng chung của phiên, không thì thanh
        // trên cùng vẽ ra một chi nhánh còn request lại gửi đi chi nhánh khác.
        $dangChon = ApiClient::chiNhanhDangLam() ?: null;
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

        // PHIÊN PHẢI LUÔN CÓ MỘT CHI NHÁNH. Trạng thái "chưa chọn" từng là mặc
        // định sau mỗi lượt đăng nhập, và nó nguy hiểm ở chỗ hai nửa hệ thống
        // hiểu nó ngược nhau: màn hình kho cộng gộp cả cửa hàng, còn lượt GHI
        // thì rơi vào chi nhánh có id nhỏ nhất. Mở chi nhánh mới rồi nhập hàng
        // cho nó, hàng lại chui vào chi nhánh cũ — mà không dấu hiệu nào trên
        // màn hình nói ra điều đó.
        //
        // Bắt lại ở đây (không chỉ ở AuthController) vì còn những phiên mở từ
        // trước bản sửa, và vì chi nhánh đang chọn có thể vừa bị đóng ngay phía
        // trên.
        if ($dangChon === null && $ds !== []) {
            $dangChon = (int) ($ds[0]['id'] ?? 0);
            if ($dangChon > 0) {
                session([ApiClient::KHOA_CHI_NHANH => $dangChon]);
            } else {
                $dangChon = null;
            }
        }

        return self::$nho = ['ds' => $ds, 'dangChon' => $dangChon === null ? null : (int) $dangChon];
    }

    /**
     * Ghim chi nhánh làm việc vào phiên ngay sau khi đăng nhập.
     *
     * $cuaToi là `chi_nhanh_id` API trả về lúc đăng nhập — chi nhánh mà hồ sơ
     * nhân sự của người này được phân về. Có thì lấy đúng cái đó: nhân viên của
     * chi nhánh 3 mà ghim nhầm chi nhánh 1 là mọi request sau đó ăn 403 "bạn
     * không làm việc tại chi nhánh này".
     *
     * Không có (chủ tiệm, hoặc chưa phân công) thì lấy chi nhánh đang mở đầu
     * tiên — họ đổi được ở thanh trên cùng.
     *
     * Hỏng thì im lặng: đây là bước tiện lợi, không phải điều kiện để đăng nhập.
     * Phiên vẫn mở, và danhSach() ở lượt vẽ trang đầu tiên sẽ ghim lại.
     */
    public static function datLucDangNhap(?int $cuaToi): void
    {
        self::$nho = null;

        if ($cuaToi !== null && $cuaToi > 0) {
            session([ApiClient::KHOA_CHI_NHANH => $cuaToi]);

            return;
        }

        session()->forget(ApiClient::KHOA_CHI_NHANH);
        self::danhSach();
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
