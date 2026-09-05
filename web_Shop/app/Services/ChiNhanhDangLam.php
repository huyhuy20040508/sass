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
    /** @var array{ds: array, dangChon: ?int, tatCa: bool}|null */
    protected static ?array $nho = null;

    /**
     * Quên bản nhớ — gọi ở ĐẦU MỖI REQUEST (ChiNhanhTheoTab).
     *
     * "Cache trong một request" chỉ đúng khi mỗi request là một tiến trình mới.
     * Bài kiểm chạy hàng chục request trong cùng tiến trình (Octane cũng vậy),
     * và biến static sống qua tất cả: request sau đọc danh sách + chi nhánh
     * đang chọn của request TRƯỚC — của một phiên khác.
     */
    public static function quenCache(): void
    {
        self::$nho = null;
    }

    /**
     * Trả về ['ds' => chi nhánh ĐANG MỞ, 'dangChon' => id hoặc null, 'tatCa' => đang xem gộp].
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
                // Trụ sở đứng đầu mọi ô chọn; phần còn lại giữ thứ tự theo mã của API.
                $chinh = self::chiNhanhChinh($ds);
                usort($ds, fn ($a, $b) => ((int) ($b['id'] ?? 0) === $chinh) <=> ((int) ($a['id'] ?? 0) === $chinh));
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

        // "CHƯA CHỌN" thì ghim chi nhánh đầu tiên; "TẤT CẢ" thì giữ nguyên.
        //
        // Hai trạng thái cùng làm chiNhanhDangLam() trả 0 nhưng là hai chuyện:
        //   - Chưa chọn (phiên không có khoá): trạng thái mặc định sau đăng nhập
        //     ngày xưa, nguy hiểm vì hai nửa hệ thống hiểu ngược nhau — màn kho
        //     cộng gộp cả cửa hàng, còn lượt GHI rơi vào chi nhánh id nhỏ nhất.
        //     Nên ghim lại ngay, kể cả phiên mở từ trước bản sửa hay chi nhánh
        //     đang chọn vừa bị đóng ở khối trên.
        //   - Tất cả (khoá = 0, chọn ở màn Cửa vào hoặc thanh trên cùng): là lựa
        //     chọn của người dùng để XEM số liệu cả cửa hàng. An toàn vì lượt ghi
        //     mơ hồ nay bị API từ chối (409 "Chưa chọn chi nhánh làm việc") chứ
        //     không còn đoán kho — trước đây ghi đè nó bằng $ds[0] nên mục "Tất
        //     cả chi nhánh" ở màn Cửa vào không bao giờ có tác dụng.
        if ($dangChon === null && $ds !== [] && ! ApiClient::daKhaiChiNhanh()) {
            $dangChon = self::chiNhanhChinh($ds);
            if ($dangChon > 0) {
                session([ApiClient::KHOA_CHI_NHANH => $dangChon]);
            } else {
                $dangChon = null;
            }
        }

        return self::$nho = [
            'ds' => $ds,
            'dangChon' => $dangChon === null ? null : (int) $dangChon,
            // Đang xem gộp cả cửa hàng — chỉ có nghĩa khi có từ hai chi nhánh.
            'tatCa' => $dangChon === null && count($ds) > 1,
        ];
    }

    /**
     * Chi nhánh CHÍNH của cửa hàng — nơi mọi mặc định rơi về.
     *
     * Ưu tiên dòng khai là "Công ty" (trụ sở, branch_type = 2); không có thì lấy
     * chi nhánh đang mở có id NHỎ NHẤT — dòng 'mac-dinh' dựng cùng lúc mở tài
     * khoản, cũng là thứ API lấy làm "nơi bán online" và kho mặc định (xem
     * BanOnline bên repository). Không lấy `$ds[0]`: API trả danh sách theo MÃ, mở
     * thêm chi nhánh mã "cn000002" là nó đứng trước "mac-dinh", và người vừa đăng
     * nhập bị đặt vào chi nhánh phụ thay vì trụ sở.
     */
    public static function chiNhanhChinh(array $ds): int
    {
        $congTy = \App\Http\Controllers\ChiNhanhController::LOAI_CONG_TY;
        $ids = fn (array $nhom) => array_filter(array_map(fn ($cn) => (int) ($cn['id'] ?? 0), $nhom), fn ($id) => $id > 0);

        $truSo = $ids(array_filter($ds, fn ($cn) => (int) ($cn['branch_type'] ?? 0) === $congTy));
        if ($truSo !== []) {
            return min($truSo);
        }
        $moi = $ids($ds);

        return $moi === [] ? 0 : min($moi);
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
