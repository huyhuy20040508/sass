<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * CuaVao — những KHU người đang đăng nhập mở được: `quan_ly`, `thu_ngan`, hoặc cả hai.
 *
 * VÌ SAO CẦN LỚP NÀY thay vì đọc thẳng session: cửa nằm ở `users.access_areas`
 * dưới database, và Go API chặn theo ĐÚNG cột đó. Còn Shop Admin thì chỉ có bản
 * chụp lúc đăng nhập. Hai bên lệch nhau ngay khi chủ tiệm bỏ tích một cửa của ai
 * đó: session vẫn nói "có", nên nút đổi module vẫn mời họ bấm sang quầy — bấm
 * xong thì API từ chối và màn hình đứng im. Người dùng thấy đúng cái đã gặp:
 * "vẫn hiện Thu ngân mà bấm vào không đổi được".
 *
 * Nên cửa phải được LÀM MỚI, không phải nhớ một lần. Cùng lối với HanSuDung:
 * hỏi API nhiều nhất một lần mỗi phút, cất vào session, mọi lượt kiểm trong phút
 * đó đọc tại chỗ.
 *
 * Đây KHÔNG phải chốt bảo mật. Chốt thật nằm ở Go API (middleware KiemQuyen.Cua,
 * đọc thẳng database). Lớp này quyết định giao diện hiện gì và chặn sớm ở đâu —
 * để người dùng gặp một câu giải thích thay vì một trang không phản hồi.
 */
class CuaVao
{
    /** Cửa đọc được gần nhất (mảng), null = chưa hỏi lần nào. */
    public const KHOA_CUA = 'phien.cua_vao';

    /** Lần cuối hỏi API (timestamp), để không hỏi lại quá dày. */
    public const KHOA_LUC = 'phien.cua_vao_luc';

    /**
     * Khoảng cách tối thiểu giữa hai lượt hỏi API.
     *
     * 60 giây, cùng con số với HanSuDung và cùng lý do: đủ thưa để không thành một
     * lượt gọi kèm mỗi trang, đủ dày để chủ tiệm vừa bỏ tích một cửa thì người kia
     * mất khu đó gần như ngay.
     */
    public const NHIP_HOI = 60;

    /** Cửa hợp lệ — khớp SET `users.access_areas` bên API. */
    public const QUAN_LY = 'quan_ly';

    public const THU_NGAN = 'thu_ngan';

    /**
     * Cửa của người đang đăng nhập.
     *
     * Thứ tự tra: bản vừa làm mới trong session -> hỏi API -> bản chụp lúc đăng
     * nhập -> suy từ vai trò. Bốn nấc nghe nhiều, nhưng mỗi nấc đóng một khoảng
     * mà nấc trên bỏ trống, và nấc cuối là thứ giữ cho người đang đăng nhập dở
     * không bị đá ra ngoài giữa ca lúc triển khai.
     */
    public static function cua(): array
    {
        $cu = session(self::KHOA_CUA);
        $luc = (int) session(self::KHOA_LUC, 0);

        // Bản vừa hỏi API chỉ dùng trong NHIP_HOI giây rồi thôi.
        //
        // Không có hạn thì nó sống tới lúc đăng xuất, và đó là một cái bẫy: chủ
        // tiệm bỏ tích một cửa của ai đó, người kia vẫn cầm bản cũ tới hết ca. Mà
        // bản cũ ấy chỉ tồn tại ở đúng những phiên đã từng gọi lamMoi() — nên lỗi
        // xảy ra với vài người và không xảy ra với những người khác, kiểu khó truy
        // nhất.
        //
        // Hết hạn thì rơi về bản chụp trong phiên, thứ ApiClient tự làm mới ở mỗi
        // lượt gia hạn token.
        if (is_array($cu) && $cu !== [] && (time() - $luc) < self::NHIP_HOI) {
            return $cu;
        }

        return self::tuPhien();
    }

    /**
     * Làm mới cửa từ API — gọi ở những chỗ ĐÁNG hỏi, không phải mỗi request.
     *
     * CỐ Ý không nhét vào cua(): chốt chặn chạy ở mọi đường của khu quản trị, và
     * một lượt gọi API nằm trong đó thì mỗi trang tải chậm thêm một vòng mạng —
     * tệ hơn, lượt gọi ấy hỏng (API sập, token vừa hết hạn) là ApiClient dọn phiên
     * ngay giữa chừng, biến một trục trặc mạng thành lượt đăng xuất.
     *
     * Chỗ đáng hỏi là chỗ người dùng vừa làm gì đó có thể đã đổi cửa của chính họ.
     */
    public static function lamMoi(): void
    {
        if ($moi = self::hoiApi()) {
            session([self::KHOA_CUA => $moi, self::KHOA_LUC => time()]);

            return;
        }

        self::quen();
    }

    /** Xoá bản đã nhớ — gọi sau khi chính người này vừa được đổi cửa. */
    public static function quen(): void
    {
        session()->forget([self::KHOA_CUA, self::KHOA_LUC]);
    }

    /**
     * Hỏi API cửa hiện tại. Trả null nếu không hỏi được — bên gọi lùi về bản chụp.
     *
     * Hỏng thì LÙI chứ không chặn, ngược hẳn với chốt bên API: ở đó một ranh giới
     * mở ra khi database trục trặc thì không phải ranh giới, còn ở đây chặn nhầm
     * chỉ khoá người ta khỏi chính khu họ đang làm việc, mà chẳng bảo vệ được gì —
     * API vẫn từ chối như thường nếu họ thật sự không có cửa.
     */
    protected static function hoiApi(): ?array
    {
        if (! session('api.access_token')) {
            return null;
        }

        try {
            $res = app(ApiClient::class)->me();
            if (! $res->successful()) {
                return null;
            }

            $cua = self::loc((array) ($res->json('data.quyen') ?? []));

            return $cua === [] ? null : $cua;
        } catch (\Throwable $e) {
            Log::warning('Khong doc duoc cua vao tu API', ['msg' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Cửa theo bản chụp lúc đăng nhập.
     *
     * Đọc CẢ HAI hình dạng, và đó không phải phòng xa: `/auth/login` trả nguyên
     * entity nên cửa nằm ở `access_areas` (chuỗi của cột SET), còn `/admin/me`
     * trả DTO nên nó là `quyen` (mảng). Session mang hình dạng nào là tuỳ đường
     * nào ghi vào gần nhất — chỉ đọc một hình dạng thì nửa số phiên rơi xuống
     * nhánh suy-từ-vai-trò, và người chỉ có cửa quầy lại được tính là có cả hai.
     */
    protected static function tuPhien(): array
    {
        $nguoi = session('api.user');

        // CÓ khai cửa thì lấy đúng những gì khai, KỂ CẢ khi lọc xong còn rỗng.
        //
        // Đừng đổi thành "lọc rỗng thì suy từ vai trò": hôm nào thêm cửa thứ ba
        // (kho chẳng hạn), người CHỈ có cửa ấy sẽ khai đầy đủ mà lọc ra rỗng — và
        // nhánh suy-từ-vai-trò lặng lẽ cấp cho họ cả hai khu vì `role_id` của họ
        // tình cờ là admin. Khai rồi mà không có cửa nào hợp lệ nghĩa là KHÔNG có
        // cửa nào, không phải "chưa biết".
        $mang = data_get($nguoi, 'quyen');
        if (is_array($mang) && $mang !== []) {
            return self::loc($mang);
        }

        $chuoi = data_get($nguoi, 'access_areas');
        if (is_string($chuoi) && trim($chuoi) !== '') {
            return self::loc(explode(',', $chuoi));
        }

        // Phiên có TRƯỚC migration 0015: suy từ vai trò đúng như hệ thống vẫn hành
        // xử — admin đi cả hai cửa, staff chỉ có quầy. Suy chứ không trả rỗng, vì
        // trả rỗng là khoá cứng mọi người đang đăng nhập dở ra ngoài ngay lượt
        // triển khai, và họ không có cách nào hiểu vì sao.
        return match (self::vaiTro($nguoi)) {
            'super_admin', 'admin' => [self::QUAN_LY, self::THU_NGAN],
            'staff' => [self::THU_NGAN],
            default => [],
        };
    }

    /**
     * Tên vai trò trong phiên — BA hình dạng, và cần cả ba.
     *
     * `/auth/login` trả entity nên vai là một khối con: `role.name`. `/admin/me`
     * trả DTO nên nó phẳng ra thành `role_name`. Vài chỗ cũ còn nhét thẳng chuỗi
     * vào `role`. Cùng một khoá phiên, ba đường ghi khác nhau — chỉ đọc một hình
     * dạng thì hai hình dạng kia rơi xuống "không biết vai nào".
     */
    protected static function vaiTro(mixed $nguoi): ?string
    {
        foreach (['role.name', 'role_name', 'role'] as $khoa) {
            $vai = data_get($nguoi, $khoa);
            if (is_string($vai) && $vai !== '') {
                return $vai;
            }
        }

        return null;
    }

    /** Chỉ giữ tên cửa có thật; giá trị lạ rơi ra ngoài. */
    protected static function loc(array $cua): array
    {
        return array_values(array_filter(
            array_map('trim', $cua),
            static fn ($c) => in_array($c, [self::QUAN_LY, self::THU_NGAN], true)
        ));
    }
}
