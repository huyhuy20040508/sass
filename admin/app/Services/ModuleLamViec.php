<?php

namespace App\Services;

/**
 * Phần mềm có HAI module, và người dùng đứng trong đúng một module tại một lúc.
 *
 *  - THU NGÂN (`/thu-ngan`): việc của người đứng quầy cả ngày — bán hàng, mở /
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

    /** Trang đầu của một module — cũng là đích của nút đổi module. */
    public static function trangChu(string $module): string
    {
        return $module === self::THU_NGAN
            ? route('thu-ngan.ban-hang.index')
            : route('admin.dashboard');
    }

    /** Trang đầu theo vai trò của người đang đăng nhập trong phiên. */
    public static function trangChuCuaPhien(): string
    {
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
        return [
            [
                'ma' => self::THU_NGAN,
                'ten' => 'Thu ngân',
                'mo_ta' => 'Bán tại quầy, ca làm việc, sổ quỹ',
                'href' => self::trangChu(self::THU_NGAN),
                'icon' => '<rect x="2.5" y="7" width="19" height="12" rx="2"/><path d="M2.5 11h19"/><circle cx="12" cy="15" r="1.6"/><path d="M6 4.5h12"/>',
            ],
            [
                'ma' => self::QUAN_TRI,
                'ten' => 'Quản trị',
                'mo_ta' => 'Hàng hoá, kho, khách hàng, báo cáo',
                'href' => self::trangChu(self::QUAN_TRI),
                'icon' => '<rect x="3" y="3" width="7.5" height="7.5" rx="1.5"/><rect x="13.5" y="3" width="7.5" height="7.5" rx="1.5"/><rect x="3" y="13.5" width="7.5" height="7.5" rx="1.5"/><rect x="13.5" y="13.5" width="7.5" height="7.5" rx="1.5"/>',
            ],
        ];
    }
}
