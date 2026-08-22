<?php

namespace App\Services;

/**
 * Phần mềm có HAI module, và người dùng đứng trong đúng một module tại một lúc.
 *
 *  - THU NGÂN (`/cashier`): việc của người đứng quầy cả ngày — bán hàng, mở /
 *    đóng ca và ghi sổ quỹ, tra lại đơn vừa bán. Màn hình tối, không sidebar,
 *    mỗi thao tác một phím.
 *  - QUẢN TRỊ (`/admin`): việc của chủ tiệm — hàng hoá, kho, khách, báo cáo,
 *    cấu hình. Màn hình sáng, có sidebar và chuông thông báo.
 *
 * VÌ SAO TÁCH: hai nhóm việc này có nhịp khác hẳn nhau. Ở quầy có người đứng
 * đợi nên màn hình phải bỏ hết những gì không phục vụ việc tính tiền; còn khu
 * quản trị là chỗ ngồi đọc và sửa, cần menu đầy đủ. Trước đây quầy nằm lẫn
 * trong menu quản trị nên người trực phải đi qua một thanh điều hướng chứa
 * mười thứ họ không được phép mở.
 *
 * Lớp này là chỗ DUY NHẤT biết "có những module nào" và "vai trò nào vào đâu".
 * Nút đổi module trên hai thanh trên cùng và lượt chuyển hướng sau khi đăng
 * nhập đều đọc từ đây, nên thêm một module thứ ba chỉ phải sửa một chỗ.
 */
class ModuleLamViec
{
    public const THU_NGAN = 'thu-ngan';

    public const QUAN_TRI = 'quan-tri';

    /** Cửa vào (`users.access_areas`) mà mỗi module đòi — xem migration 0015. */
    protected const CUA = [
        self::THU_NGAN => 'thu_ngan',
        self::QUAN_TRI => 'quan_ly',
    ];

    /**
     * Vai trò MẶC ĐỊNH đứng ở module thu ngân.
     *
     * `staff` là thu ngân (xem vai trò 3 trong sổ), và họ chỉ mở được Tổng quan,
     * Đơn hàng cùng cụm quầy — đưa họ vào khu quản trị là mở ra một thanh trái
     * gần như trống rỗng.
     */
    protected const VAI_TRO_THU_NGAN = ['staff'];

    /** Module người này vào ngay sau khi đăng nhập. */
    public static function macDinh(?string $vaiTro): string
    {
        return in_array((string) $vaiTro, self::VAI_TRO_THU_NGAN, true)
            ? self::THU_NGAN
            : self::QUAN_TRI;
    }

    /**
     * Trang tới ngay sau khi đăng nhập: MÀN CHỌN CỬA VÀO, cho mọi người.
     *
     * Kể cả người chỉ có một khu. Màn ấy không chỉ hỏi khu — nó còn hỏi chi
     * nhánh, và nói người này đang đứng ở tiệm nào; thu ngân bỏ qua là bỏ qua cả
     * ba, rồi bán cả ca vào nhầm kho mà không có chỗ nào kịp nhận ra.
     *
     * Không có khu nào thì đi thẳng — không còn gì để hỏi.
     */
    public static function sauKhiDangNhap(): string
    {
        return self::danhSach() !== []
            ? route('chon-cua')
            : self::trangChuCuaPhien();
    }

    /**
     * Một module theo mã, CHỈ khi người đang đăng nhập có cửa vào đó.
     *
     * Đọc qua danhSach() (đã lọc theo cửa) chứ không qua tatCa(): đây là thứ màn
     * chọn cửa dùng để kiểm ô người dùng vừa bấm, nên nó phải trả lời "anh có
     * được vào đây không", không phải "chỗ này có tồn tại không".
     */
    public static function timTheoMa(?string $ma): ?array
    {
        return collect(self::danhSach())->firstWhere('ma', (string) $ma);
    }

    /** Trang đầu của một module — cũng là đích của nút đổi module. */
    public static function trangChu(string $module): string
    {
        return $module === self::THU_NGAN
            ? route('thu-ngan.ban-hang.index')
            : route('admin.dashboard');
    }

    /**
     * Trang đầu của người đang đăng nhập.
     *
     * Đọc CỬA trước, vai trò sau: một người vai admin nhưng chỉ được tích "Thu
     * ngân" phải về quầy, không phải về khu quản trị rồi bị chốt chặn đá ngược
     * lại. Không có cửa nào thì rơi về luật cũ theo vai trò.
     */
    public static function trangChuCuaPhien(): string
    {
        $cua = \App\Http\Middleware\EnsureCuaVao::cuaCuaPhien();

        if ($cua !== [] && ! in_array('quan_ly', $cua, true)) {
            return self::trangChu(self::THU_NGAN);
        }

        return self::trangChu(self::macDinh(data_get(session('api.user'), 'role.name')));
    }

    /**
     * Module đang mở, suy từ tên route.
     *
     * Đọc route chứ không đọc URL: tên route là thứ khai báo trong web.php, còn
     * đường dẫn thì có thể đổi mà không ai nhớ sửa chỗ này.
     */
    public static function hienTai(): string
    {
        return str_starts_with((string) request()->route()?->getName(), 'thu-ngan.')
            ? self::THU_NGAN
            : self::QUAN_TRI;
    }

    /**
     * Hai module cho nút đổi qua lại.
     *
     * Mỗi mục kèm MỘT CÂU nói module đó dùng để làm gì. Chỉ có hai tên gọi thì
     * người mới vào vẫn phải đoán "quản trị" khác "thu ngân" ở chỗ nào, mà đoán
     * sai thì họ bấm sang một khu không có việc của mình.
     */
    public static function danhSach(): array
    {
        // CHỈ những module người này có cửa. Liệt kê cả hai cho mọi người là mời
        // người trực quầy bấm sang khu quản trị rồi bị đá ngược về đúng chỗ cũ —
        // một cái nút không làm gì cả, đứng ở vị trí dễ thấy nhất của thanh.
        //
        // KHÔNG có nhánh "chưa biết cửa thì hiện hết". Nhánh đó nghe như phòng xa
        // nhưng chạy ngược chiều: đúng lúc không đọc được cửa là lúc màn hình mời
        // người ta bấm vào khu họ không vào được. Không biết thì KHÔNG mời — cùng
        // lối với PlatformRole bên API, quên gắn phải là đóng chứ không phải mở.
        //
        // CuaVao đã lo đường lui cho phiên cũ (suy từ vai trò), nên tới đây mà rỗng
        // thì nghĩa là người này thật sự không có khu nào để đổi sang.
        $cua = \App\Services\CuaVao::cua();

        return array_values(array_filter(
            self::tatCa(),
            static fn (array $m) => in_array(self::CUA[$m['ma']] ?? '', $cua, true)
        ));
    }

    /**
     * Hai module của phần mềm, chưa lọc theo cửa.
     *
     * Mỗi mục mang hai hình cho hai cỡ: `icon` (nét SVG) cho nút đổi module trên
     * hai thanh trên cùng, nơi hình chỉ còn 17px; `anh` (ảnh chụp) cho hai ô to ở
     * màn chọn cửa. Ảnh chụp thu về 17px chỉ còn một vệt màu, nét SVG phóng to
     * bằng nửa ô thì trống trải.
     */
    protected static function tatCa(): array
    {
        return [
            [
                'ma' => self::THU_NGAN,
                'ten' => 'Thu ngân',
                'mo_ta' => 'Bán hàng, điều phối ca, sổ quỹ',
                'href' => self::trangChu(self::THU_NGAN),
                'anh' => 'images/cua-thu-ngan.jpg',
                'icon' => '<rect x="2.5" y="7" width="19" height="12" rx="2"/><path d="M2.5 11h19"/><circle cx="12" cy="15" r="1.6"/><path d="M6 4.5h12"/>',
            ],
            [
                'ma' => self::QUAN_TRI,
                'ten' => 'Quản trị',
                'mo_ta' => 'Hàng hoá, kho, khách hàng, báo cáo',
                'href' => self::trangChu(self::QUAN_TRI),
                'anh' => 'images/cua-quan-tri.jpg',
                'icon' => '<rect x="3" y="3" width="7.5" height="7.5" rx="1.5"/><rect x="13.5" y="3" width="7.5" height="7.5" rx="1.5"/><rect x="3" y="13.5" width="7.5" height="7.5" rx="1.5"/><rect x="13.5" y="13.5" width="7.5" height="7.5" rx="1.5"/>',
            ],
        ];
    }
}
