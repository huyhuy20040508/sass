<?php

namespace App\Services;

use Illuminate\Support\Carbon;

/**
 * HanSuDung — hạn hợp đồng phần mềm của cửa hàng đang đăng nhập, giữ trong session.
 *
 * VÌ SAO CẦN LỚP NÀY: trước nó, Shop Admin chỉ biết mình hết hạn khi Go API từ
 * chối — mà API lại chỉ từ chối SAU khi lượt quét nền (5 phút/lần) đặt
 * `tenants.status = suspended`. Nghĩa là có một khoảng tới năm phút sau thời
 * khắc hết hạn mà mọi thứ vẫn xanh: bấm F5 cũng không có cảnh báo nào, người
 * dùng vẫn bán hàng bình thường. Đúng cái đã gặp trên máy chạy thật.
 *
 * Hạn hợp đồng là một MỐC THỜI GIAN, không phải một trạng thái phải đi hỏi lại
 * liên tục: biết mốc rồi thì đồng hồ tự trả lời. Lớp này cất mốc đó vào session,
 * để mọi trang so sánh tại chỗ — không thêm lượt gọi API nào, và khoá đúng vào
 * giây hợp đồng chết chứ không phải năm phút sau.
 *
 * BA NGUỒN GHI, và cần cả ba:
 *   - trang Các gói dịch vụ (đọc hợp đồng thật);
 *   - middleware KhoaKhiHetHan (làm mới định kỳ khi người dùng đi lại trong app);
 *   - lượt 403 mang mã CUA_HANG_KHOA từ API (chốt chặn thật, ghi đè mọi phỏng đoán).
 *
 * Đây KHÔNG phải chốt bảo mật. Chốt thật nằm ở Go API — nó từ chối mọi đường trừ
 * một khi cửa hàng bị khoá. Lớp này chỉ quyết định giao diện nói gì và lúc nào.
 */
class HanSuDung
{
    /** Mốc hết hạn của hợp đồng (ISO), null = chưa biết. */
    public const KHOA_HAN = 'phien.han_hop_dong';

    /** Lần cuối hỏi API về hợp đồng (timestamp), để không hỏi lại quá dày. */
    public const KHOA_KIEM_LUC = 'phien.han_kiem_luc';

    /** Cờ khoá do API khẳng định (403 CUA_HANG_KHOA hoặc lúc đăng nhập). */
    public const KHOA_CO = 'phien.cua_hang_khoa';

    /**
     * Khoảng cách tối thiểu giữa hai lượt hỏi API về hợp đồng.
     *
     * 60 giây: đủ thưa để không thành một lượt gọi kèm mỗi trang, đủ dày để khách
     * vừa gia hạn xong không phải ngồi chờ lâu mới dùng lại được.
     */
    public const NHIP_HOI = 60;

    /** Ghi mốc hết hạn đọc được từ API. $hopDong = khối `hop_dong` của /admin/goi-dich-vu. */
    public static function ghiNhan(?array $hopDong): void
    {
        session([self::KHOA_KIEM_LUC => time()]);

        $hetHan = $hopDong['het_han'] ?? null;
        if (! $hetHan) {
            // Không có hợp đồng trong sổ nền tảng: KHÔNG suy ra là hết hạn. Cửa hàng
            // dựng tay từ trước khi có control plane rơi vào đây, và khoá họ lại vì
            // một dòng dữ liệu vắng mặt là tự cắt chính khách đang trả tiền.
            session()->forget(self::KHOA_HAN);

            return;
        }

        session([self::KHOA_HAN => $hetHan]);

        // API đã trả lời dứt khoát "hết hạn chưa" (so tới từng giây, theo đồng hồ
        // máy chủ). Chép luôn vào cờ khoá: không đợi tới lượt gọi bị 403 mới biết.
        //
        // Chỉ ghi đè theo chiều FALSE khi API nói còn hạn — để lượt gia hạn có hiệu
        // lực ngay, mà một câu trả lời thiếu trường cũng không mở khoá nhầm.
        if (array_key_exists('da_het_han', $hopDong)) {
            session([self::KHOA_CO => (bool) $hopDong['da_het_han']]);
        }
    }

    /** Mốc hết hạn, null nếu chưa biết. */
    public static function hetHan(): ?Carbon
    {
        $raw = session(self::KHOA_HAN);
        if (! $raw) {
            return null;
        }

        try {
            return Carbon::parse($raw);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Cửa hàng có đang bị khoá không.
     *
     * HAI vế, và vế thứ hai mới là vế chữa được lỗi: cờ do API đặt (chính xác
     * nhưng đến muộn — phải chờ lượt quét), HOẶC mốc hết hạn đã trôi qua theo
     * đồng hồ (biết ngay tại giây đó).
     */
    public static function daKhoa(): bool
    {
        if (session(self::KHOA_CO)) {
            return true;
        }

        $hetHan = self::hetHan();

        return $hetHan !== null && $hetHan->isPast();
    }

    /**
     * Số giây còn lại tới lúc hết hạn; null = chưa biết mốc, số âm = đã quá hạn.
     *
     * Trang dùng nó để hẹn giờ bật hộp thoại đúng lúc hợp đồng chết, thay vì bắt
     * người dùng F5 mới biết.
     */
    public static function giayConLai(): ?int
    {
        $hetHan = self::hetHan();

        return $hetHan === null ? null : (int) round(now()->diffInSeconds($hetHan, false));
    }

    /** Đã tới lúc hỏi lại API về hợp đồng chưa. */
    public static function nenHoiLai(): bool
    {
        return (time() - (int) session(self::KHOA_KIEM_LUC, 0)) >= self::NHIP_HOI;
    }

    /** Xoá sạch khi đăng xuất — người đăng nhập sau trên cùng máy phải bắt đầu lại. */
    public static function quen(): void
    {
        session()->forget([self::KHOA_HAN, self::KHOA_KIEM_LUC, self::KHOA_CO]);
    }
}
