<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Db;
use App\Core\HttpException;
use App\Core\Secret;

/**
 * Giao diện của shop (bảng shop_themes): màu/font, tuỳ chọn đầu/chân trang và danh
 * sách khối trang chủ. Màn Chỉnh sửa giao diện ghi vào bản NHÁP; Áp dụng mới chép
 * sang bản ĐANG CHẠY khách thấy.
 *
 * Khuôn (khối nào, ô nào, kiểu gì, mặc định) đọc từ storefront/themes/<theme>/theme.json
 * — cùng tệp Next dùng để dựng trang, nên hai bên không lệch nhau. Dữ liệu vào đều
 * kiểm theo khuôn: khối lạ, ô lạ, sai kiểu bị từ chối; trang khách không nhận rác.
 */
final class GiaoDien
{
    public const THEME_MAC_DINH = 'mac-dinh';
    private const TOI_DA_KHOI = 30;
    private const XEM_TRUOC_GIAY = 7200;
    // Ảnh mẫu đi kèm theme (storefront/public/themes/<theme>/): ô ảnh ghi "mau:<tệp>". Shop chưa tải
    // ảnh của mình thì trang vẫn có ảnh đẹp như bản thiết kế; bấm Bỏ là gỡ được.
    private const ANH_MAU_RE = '/^mau:[a-z0-9-]{1,40}\.(jpg|png|webp)$/';
    /** Theme của bộ dữ liệu đang kiểm — ô ảnh mẫu phải là ảnh của đúng theme đó. */
    private static string $themeDangKiem = self::THEME_MAC_DINH;

    /** @return array<string, mixed> khuôn của một theme; theme lạ → 422 */
    public static function khuon(string $theme): array
    {
        static $k = [];
        if (!isset($k[$theme])) {
            $tep = self::thuMucTheme() . "/$theme/theme.json";
            if (!preg_match('/^[a-z0-9-]{2,40}$/', $theme) || !is_file($tep)) {
                throw new HttpException(422, 'Mẫu giao diện không có');
            }
            $k[$theme] = json_decode((string) file_get_contents($tep), true, 32, JSON_THROW_ON_ERROR);
        }

        return $k[$theme];
    }

    /** @return list<array<string, mixed>> kho giao diện: mọi thư mục storefront/themes/<mã>/theme.json */
    public static function dsTheme(): array
    {
        $ds = [];
        foreach (glob(self::thuMucTheme() . '/*/theme.json') ?: [] as $tep) {
            $k = self::khuon(basename(dirname($tep)));
            $ds[] = ['ma' => $k['ma'], 'ten' => $k['ten'], 'nganh' => $k['nganh'] ?? '', 'mo_ta' => $k['mo_ta'] ?? '', 'mau' => $k['chung']['mau_chinh'], 'thu_tu' => $k['thu_tu'] ?? 99];
        }
        usort($ds, fn ($a, $b) => $a['thu_tu'] <=> $b['thu_tu']);

        return $ds;
    }

    /** @return array<string, mixed> giao diện mới tinh của theme: mọi ô lấy mặc định */
    public static function macDinh(string $theme = self::THEME_MAC_DINH): array
    {
        $k = self::khuon($theme);
        $trang = [];
        foreach ($k['trang_chu'] as $i => $loai) {
            $trang[] = ['id' => 'k' . ($i + 1), 'khoi' => $loai, 'an' => false, 'o' => self::giaTriMacDinh($k['khoi'][$loai]['o'])];
        }

        return [
            'theme' => $theme,
            'chung' => $k['chung'],
            'dau_trang' => self::giaTriMacDinh($k['dau_trang']['o']),
            'chan_trang' => self::giaTriMacDinh($k['chan_trang']['o']),
            'trang_chu' => $trang,
        ];
    }

    /**
     * @param 'nhap'|'dang_chay' $ban
     * @return array<string, mixed>
     */
    public static function doc(int $shopId, string $ban): array
    {
        $row = Db::one("SELECT $ban AS data FROM shop_themes WHERE shop_id = ?", [$shopId]);
        $data = $row && $row['data'] !== null ? json_decode((string) $row['data'], true) : null;

        return is_array($data) ? self::boSung($data) : self::macDinh();
    }

    /**
     * Theme thêm ô / bỏ khối sau khi shop đã lưu: ô thiếu nhận mặc định, khối không
     * còn trong theme thì bỏ — dữ liệu cũ vẫn dựng được trang. Dữ liệu trước khi có
     * nhiều theme không ghi theme → theme mặc định; theme đã bị gỡ khỏi kho cũng vậy.
     *
     * @param array<string, mixed> $d
     * @return array<string, mixed>
     */
    private static function boSung(array $d): array
    {
        $theme = (string) ($d['theme'] ?? self::THEME_MAC_DINH);
        if (!is_file(self::thuMucTheme() . "/$theme/theme.json")) {
            return self::macDinh();
        }
        $k = self::khuon($theme);
        $md = self::macDinh($theme);
        $kq = [
            'theme' => $theme,
            'chung' => ($d['chung'] ?? []) + $md['chung'],
            'dau_trang' => ($d['dau_trang'] ?? []) + $md['dau_trang'],
            'chan_trang' => ($d['chan_trang'] ?? []) + $md['chan_trang'],
            'trang_chu' => [],
        ];
        foreach ($d['trang_chu'] ?? [] as $b) {
            if (isset($k['khoi'][$b['khoi'] ?? ''])) {
                $b['o'] = ($b['o'] ?? []) + self::giaTriMacDinh($k['khoi'][$b['khoi']]['o']);
                $kq['trang_chu'][] = $b;
            }
        }

        return $kq;
    }

    /** @return array<string, mixed> thông tin cho màn chỉnh: nháp + đã áp dụng lúc nào + còn thay đổi chưa áp dụng không */
    public static function choManChinh(int $shopId): array
    {
        $row = Db::one('SELECT nhap, dang_chay, sua_luc, sua_boi, ap_dung_luc, ap_dung_boi FROM shop_themes WHERE shop_id = ?', [$shopId]);
        $nhap = $row ? self::boSung(json_decode((string) $row['nhap'], true) ?: []) : self::macDinh();

        $chay = $row && $row['dang_chay'] !== null ? self::boSung(json_decode((string) $row['dang_chay'], true) ?: []) : self::macDinh();

        return [
            'nhap' => $nhap,
            // Tóm tắt bản khách đang thấy — trang Website của tôi hiện mẫu đang CHẠY, có thể khác mẫu trong nháp.
            'dang_chay' => [
                'theme' => $chay['theme'],
                'so_khoi' => count($chay['trang_chu']),
                'so_an' => count(array_filter($chay['trang_chu'], fn ($b) => $b['an'])),
            ],
            'kho' => self::dsTheme(),
            'chua_ap_dung' => $row !== null && ($row['dang_chay'] === null || self::boSung(json_decode((string) $row['dang_chay'], true) ?: []) !== $nhap),
            'sua_luc' => $row['sua_luc'] ?? null,
            'ap_dung_luc' => $row['ap_dung_luc'] ?? null,
            'ap_dung_boi' => $row['ap_dung_boi'] ?? null,
            'ma_xem_truoc' => self::maXemTruoc($shopId),
        ];
    }

    /**
     * @param array<string, mixed> $vao
     * @return array<string, mixed> bản nháp đã chuẩn hoá
     */
    public static function luuNhap(int $shopId, array $vao, string $nguoi): array
    {
        $data = self::kiem($shopId, $vao);
        Db::exec(
            'INSERT INTO shop_themes (shop_id, theme, nhap, sua_luc, sua_boi) VALUES (?, ?, ?, NOW(), ?)
             ON DUPLICATE KEY UPDATE theme = VALUES(theme), nhap = VALUES(nhap), sua_luc = NOW(), sua_boi = VALUES(sua_boi)',
            [$shopId, $data['theme'], self::json($data), $nguoi],
        );

        return $data;
    }

    /**
     * Đổi mẫu giao diện (vào bản nháp — khách chưa thấy tới khi Áp dụng). Bố cục trang
     * chủ theo mẫu mới; chữ, ảnh, link đã nhập ở khối cùng loại được mang sang ô trùng
     * tên cùng kiểu, để shop không phải gõ lại. Đầu/chân trang giữ tuỳ chọn trùng tên.
     */
    public static function doiTheme(int $shopId, string $themeMoi, bool $dungMauMoi, string $nguoi): array
    {
        return self::luuNhap($shopId, self::tinhDoiTheme($shopId, $themeMoi, $dungMauMoi), $nguoi);
    }

    /**
     * Bản nháp sẽ ra sao nếu đổi sang $themeMoi — không lưu. Dùng cho Đổi mẫu và cho
     * "Xem thử" ở Kho giao diện (xem mẫu khác bằng nội dung của shop mà không đụng nháp).
     *
     * @return array<string, mixed>
     */
    public static function tinhDoiTheme(int $shopId, string $themeMoi, bool $dungMauMoi): array
    {
        $cu = self::doc($shopId, 'nhap');
        $kCu = self::khuon($cu['theme']);
        $kMoi = self::khuon($themeMoi);
        $moi = self::macDinh($themeMoi);

        $theoLoai = [];
        foreach ($cu['trang_chu'] as $b) {
            $theoLoai[$b['khoi']] ??= $b;
        }
        foreach ($moi['trang_chu'] as &$b) {
            $c = $theoLoai[$b['khoi']] ?? null;
            if ($c !== null) {
                $b['o'] = self::mangSang($kCu['khoi'][$c['khoi']]['o'], $c['o'], $kMoi['khoi'][$b['khoi']]['o'], $b['o']);
                $b['an'] = $c['an'];
            }
        }
        unset($b);
        foreach (['dau_trang', 'chan_trang'] as $phan) {
            $moi[$phan] = self::mangSang($kCu[$phan]['o'], $cu[$phan], $kMoi[$phan]['o'], $moi[$phan]);
        }
        if (!$dungMauMoi) {
            $moi['chung']['mau_chinh'] = $cu['chung']['mau_chinh'];
            if (in_array($cu['chung']['font'], array_column($kMoi['font'], 'khoa'), true)) {
                $moi['chung']['font'] = $cu['chung']['font'];
            }
        }

        return $moi;
    }

    /**
     * @param list<array<string, mixed>> $khaiCu
     * @param array<string, mixed> $gtCu
     * @param list<array<string, mixed>> $khaiMoi
     * @param array<string, mixed> $gtMoi
     * @return array<string, mixed>
     */
    private static function mangSang(array $khaiCu, array $gtCu, array $khaiMoi, array $gtMoi): array
    {
        $loaiCu = array_column($khaiCu, 'loai', 'khoa');
        $macDinhCu = self::giaTriMacDinh($khaiCu);
        foreach ($khaiMoi as $o) {
            $k = $o['khoa'];
            $v = $gtCu[$k] ?? null;
            // Chỉ mang thứ shop đã tự sửa: ô còn y mặc định / để trống của mẫu cũ mà mang sang
            // thì đè mất chữ, ảnh mẫu đẹp của mẫu mới. danh_sach / chon có thể khác ô con, khác
            // lựa chọn giữa hai theme — để mặc định của mẫu mới.
            if (($loaiCu[$k] ?? null) === $o['loai'] && !in_array($o['loai'], ['danh_sach', 'chon'], true)
                && $v !== null && $v !== '' && $v !== ($macDinhCu[$k] ?? null)) {
                $gtMoi[$k] = $v;
            }
        }

        return $gtMoi;
    }

    public static function apDung(int $shopId, string $nguoi): void
    {
        // Chưa sửa gì mà bấm Áp dụng: áp bản mặc định cho có dòng.
        if (Db::one('SELECT 1 FROM shop_themes WHERE shop_id = ?', [$shopId]) === null) {
            self::luuNhap($shopId, self::macDinh(), $nguoi);
        }
        Db::exec('UPDATE shop_themes SET dang_chay = nhap, ap_dung_luc = NOW(), ap_dung_boi = ? WHERE shop_id = ?', [$nguoi, $shopId]);
    }

    /** Bỏ mọi thay đổi chưa áp dụng: nháp quay về bản khách đang thấy. */
    public static function boNhap(int $shopId): void
    {
        Db::exec('UPDATE shop_themes SET nhap = COALESCE(dang_chay, ?), sua_luc = NOW() WHERE shop_id = ?', [self::json(self::macDinh()), $shopId]);
    }

    private static function thuMucTheme(): string
    {
        return dirname(__DIR__, 3) . '/storefront/themes';
    }

    // ---- Xem trước bản nháp trên trang khách -------------------------------

    /** Mã ngắn hạn để khung xem trước mở trang khách bằng bản NHÁP (iframe không gửi được token đăng nhập). */
    public static function maXemTruoc(int $shopId): string
    {
        $het = time() + self::XEM_TRUOC_GIAY;
        return "$shopId.$het." . substr(Secret::ky("xem-truoc|$shopId|$het"), 0, 32);
    }

    public static function maXemTruocHopLe(int $shopId, string $ma): bool
    {
        $p = explode('.', $ma);
        return count($p) === 3 && (int) $p[0] === $shopId && (int) $p[1] >= time()
            && hash_equals(substr(Secret::ky("xem-truoc|$shopId|{$p[1]}"), 0, 32), $p[2]);
    }

    // ---- Cho trang khách -------------------------------------------------------

    /**
     * Ô ảnh lưu tên tệp; trang khách cần link. Khối ẩn bỏ hẳn.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function congKhai(array $data): array
    {
        $k = self::khuon($data['theme']);
        $khoi = [];
        foreach ($data['trang_chu'] as $b) {
            if (!$b['an']) {
                $khoi[] = ['id' => $b['id'], 'khoi' => $b['khoi'], 'o' => self::kemUrl($k['khoi'][$b['khoi']]['o'], $b['o'], $data['theme'])];
            }
        }

        return ['theme' => $data['theme'], 'chung' => $data['chung'], 'dau_trang' => $data['dau_trang'], 'chan_trang' => $data['chan_trang'], 'trang_chu' => $khoi];
    }

    // ---- Kiểm dữ liệu theo khuôn -------------------------------------------

    /**
     * @param array<string, mixed> $vao
     * @return array<string, mixed>
     */
    private static function kiem(int $shopId, array $vao): array
    {
        $theme = (string) ($vao['theme'] ?? self::THEME_MAC_DINH);
        $k = self::khuon($theme);
        self::$themeDangKiem = $theme;
        $loi = [];

        $mau = (string) ($vao['chung']['mau_chinh'] ?? '');
        if (!preg_match('/^#[0-9a-f]{6}$/i', $mau)) {
            $loi['chung.mau_chinh'] = 'Màu dạng #RRGGBB';
        }
        $font = (string) ($vao['chung']['font'] ?? '');
        if (!in_array($font, array_column($k['font'], 'khoa'), true)) {
            $loi['chung.font'] = 'Font không có trong danh sách';
        }

        $data = ['theme' => $theme, 'chung' => ['mau_chinh' => strtolower($mau), 'font' => $font]];
        foreach (['dau_trang', 'chan_trang'] as $phan) {
            $data[$phan] = self::kiemO($shopId, $k[$phan]['o'], is_array($vao[$phan] ?? null) ? $vao[$phan] : [], $phan, $loi);
        }

        $ds = $vao['trang_chu'] ?? null;
        if (!is_array($ds) || !array_is_list($ds) || count($ds) > self::TOI_DA_KHOI) {
            throw new HttpException(422, 'Danh sách khối trang chủ không hợp lệ (tối đa ' . self::TOI_DA_KHOI . ' khối)');
        }
        $data['trang_chu'] = [];
        $daCo = [];
        foreach ($ds as $i => $b) {
            $loai = is_array($b) ? (string) ($b['khoi'] ?? '') : '';
            $id = is_array($b) ? (string) ($b['id'] ?? '') : '';
            if (!isset($k['khoi'][$loai])) {
                throw new HttpException(422, "Khối thứ " . ($i + 1) . " không có trong giao diện này");
            }
            if (!preg_match('/^[a-z0-9]{2,16}$/', $id) || isset($daCo[$id])) {
                throw new HttpException(422, "Mã khối thứ " . ($i + 1) . " không hợp lệ hoặc bị trùng");
            }
            $daCo[$id] = true;
            $data['trang_chu'][] = [
                'id' => $id,
                'khoi' => $loai,
                'an' => (bool) ($b['an'] ?? false),
                'o' => self::kiemO($shopId, $k['khoi'][$loai]['o'], is_array($b['o'] ?? null) ? $b['o'] : [], "trang_chu.$id", $loi),
            ];
        }

        if ($loi) {
            throw new HttpException(422, 'Có ô nhập chưa đúng', $loi);
        }

        return $data;
    }

    /**
     * Một bộ ô theo khai báo. Ô thiếu nhận mặc định; ô lạ bỏ qua. Lỗi ghi vào $loi theo
     * đường "<tiền tố>.<ô>" (danh sách: "<tiền tố>.<ô>.<thứ tự>.<ô con>").
     *
     * @param list<array<string, mixed>> $khai
     * @param array<string, mixed> $vao
     * @param array<string, string> $loi
     * @return array<string, mixed>
     */
    private static function kiemO(int $shopId, array $khai, array $vao, string $tienTo, array &$loi): array
    {
        $kq = [];
        foreach ($khai as $o) {
            $khoa = $o['khoa'];
            $v = $vao[$khoa] ?? $o['mac_dinh'];
            $duong = "$tienTo.$khoa";

            if ($o['loai'] === 'danh_sach') {
                if (!is_array($v) || !array_is_list($v) || count($v) > $o['toi_da']) {
                    $loi[$duong] = "Tối đa {$o['toi_da']} mục";
                    $kq[$khoa] = [];
                    continue;
                }
                $kq[$khoa] = [];
                foreach ($v as $i => $muc) {
                    $kq[$khoa][] = self::kiemO($shopId, $o['o'], is_array($muc) ? $muc : [], "$duong.$i", $loi);
                }
                continue;
            }

            try {
                $kq[$khoa] = self::kiemMot($shopId, $o, $v);
            } catch (\InvalidArgumentException $e) {
                $loi[$duong] = $e->getMessage();
                $kq[$khoa] = $o['mac_dinh'];
            }
        }

        return $kq;
    }

    /** @param array<string, mixed> $o */
    private static function kiemMot(int $shopId, array $o, mixed $v): string|int|bool
    {
        switch ($o['loai']) {
            case 'bat_tat':
                if (!is_bool($v)) {
                    throw new \InvalidArgumentException('Bật hoặc tắt');
                }
                return $v;
            case 'so':
            case 'nhom_hang':
                $so = filter_var($v, FILTER_VALIDATE_INT);
                $min = $o['min'] ?? 0;
                $max = $o['max'] ?? PHP_INT_MAX;
                if ($so === false || $so < $min || $so > $max) {
                    throw new \InvalidArgumentException($o['loai'] === 'so' ? "Nhập số từ $min đến $max" : 'Chọn nhóm hàng');
                }
                return $so;
        }

        if (!is_string($v)) {
            throw new \InvalidArgumentException('Giá trị không hợp lệ');
        }
        $v = $o['loai'] === 'doan'
            ? trim(str_replace("\r", '', $v))
            // Gộp khoảng trắng: chữ một dòng in thẳng lên trang, xuống dòng là vỡ khung.
            : trim((string) preg_replace('/\s+/u', ' ', $v));

        switch ($o['loai']) {
            case 'chu':
            case 'doan':
                if (mb_strlen($v) > $o['max']) {
                    throw new \InvalidArgumentException("Tối đa {$o['max']} ký tự");
                }
                return $v;
            case 'chon':
                if (!in_array($v, array_column($o['lua_chon'], 'gt'), true)) {
                    throw new \InvalidArgumentException('Lựa chọn không có trong danh sách');
                }
                return $v;
            case 'mau':
                if ($v !== '' && !preg_match('/^#[0-9a-f]{6}$/i', $v)) {
                    throw new \InvalidArgumentException('Màu dạng #RRGGBB');
                }
                return strtolower($v);
            case 'link':
                // Đường trong web (/…, #…) hoặc http(s). Chặn "javascript:" vì link in thẳng vào href.
                if ($v !== '' && (strlen($v) > 255 || !preg_match('~^(/(?!/)|#|https?://)[^\s<>"]*$~i', $v))) {
                    throw new \InvalidArgumentException('Link bắt đầu bằng /, # hoặc https://');
                }
                return $v;
            case 'ngay_gio':
                if ($v !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $v) || strtotime($v) === false)) {
                    throw new \InvalidArgumentException('Ngày giờ không hợp lệ');
                }
                return $v;
            case 'anh':
                if (preg_match(self::ANH_MAU_RE, $v)) {
                    if (!is_file(dirname(__DIR__, 3) . '/storefront/public/themes/' . self::$themeDangKiem . '/' . substr($v, 4))) {
                        throw new \InvalidArgumentException('Ảnh mẫu không thuộc giao diện này');
                    }
                    return $v;
                }
                if ($v !== '' && !KhoAnh::coAnh($shopId, $v)) {
                    throw new \InvalidArgumentException('Ảnh không còn, vui lòng tải lại');
                }
                return $v;
        }

        throw new \LogicException('Loại ô lạ: ' . $o['loai']);
    }

    /**
     * @param list<array<string, mixed>> $khai
     * @return array<string, mixed>
     */
    private static function giaTriMacDinh(array $khai): array
    {
        return array_column($khai, 'mac_dinh', 'khoa');
    }

    /**
     * @param list<array<string, mixed>> $khai
     * @param array<string, mixed> $gt
     * @return array<string, mixed>
     */
    private static function kemUrl(array $khai, array $gt, string $theme): array
    {
        foreach ($khai as $o) {
            if ($o['loai'] === 'anh') {
                $v = (string) $gt[$o['khoa']];
                $gt[$o['khoa']] = str_starts_with($v, 'mau:') ? "/themes/$theme/" . substr($v, 4) : KhoAnh::url($v);
            } elseif ($o['loai'] === 'danh_sach') {
                $gt[$o['khoa']] = array_map(fn ($m) => self::kemUrl($o['o'], $m + self::giaTriMacDinh($o['o']), $theme), $gt[$o['khoa']]);
            }
        }

        return $gt;
    }

    private static function json(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
