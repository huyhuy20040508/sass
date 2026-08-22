<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Nhận ảnh tải lên, thu nhỏ rồi cất vào ổ đĩa công khai.
 *
 * Trước đây mỗi chỗ upload tự khai `max:2048` (2MB) và chặn thẳng file to hơn.
 * Con số đó nhỏ hơn hầu hết ảnh chụp bằng điện thoại (3–8MB), nên việc thường gặp
 * nhất — chụp cái áo rồi tải lên — lại là việc bị từ chối. Mà nới trần lên không
 * thôi thì đổi một vấn đề lấy vấn đề khác: ảnh 8MB nằm nguyên trong trang sản
 * phẩm làm website khách nặng gấp hàng chục lần.
 *
 * Nên ở đây làm cả hai: nhận tới 10MB, nhưng thu nhỏ và nén lại trước khi lưu, để
 * thứ nằm trên đĩa luôn nhẹ. Người bán không phải biết "resize" là gì.
 *
 * Dùng GD có sẵn trong PHP, không thêm thư viện ngoài.
 */
class ImageStore
{
    /** Trần dung lượng file NHẬN VÀO (KB) — dùng cho luật validate của Laravel. */
    public const MAX_UPLOAD_KB = 10240;

    /** Cạnh dài nhất sau khi thu nhỏ (px). Ảnh nhỏ hơn mức này được giữ nguyên. */
    public const MAX_EDGE = 1600;

    /** Chất lượng nén cho JPEG/WEBP. 82 là mức mắt thường không phân biệt được. */
    public const QUALITY = 82;

    /** Định dạng nhận vào. SVG chỉ mở cho logo (ảnh vector, GD không đụng tới). */
    public const MIMES = 'jpeg,jpg,png,webp,gif,avif';

    public const MIMES_WITH_SVG = self::MIMES . ',svg';

    /**
     * Luật validate cho ô tải ảnh.
     *
     * Cố ý KHÔNG dùng luật `image` của Laravel: tới bản 12 nó vẫn loại AVIF, nên
     * ảnh .avif bị báo "không phải ảnh hợp lệ" dù đó là định dạng ảnh bình thường
     * của web bây giờ. `mimes` cũng đọc nội dung file thật (đoán đuôi từ MIME thực
     * chứ không tin tên file), nên bỏ `image` đi không mất lớp bảo vệ nào.
     */
    public static function rules(bool $allowSvg = false): array
    {
        return [
            'required',
            'file',
            'mimes:' . ($allowSvg ? self::MIMES_WITH_SVG : self::MIMES),
            'max:' . self::MAX_UPLOAD_KB,
        ];
    }

    /**
     * Câu báo lỗi cho người dùng cuối.
     *
     * Nói rõ định dạng nào KHÔNG nhận thay vì chỉ liệt kê định dạng nhận: người
     * dùng iPhone tải file .heic lên và đọc câu "chỉ nhận JPG, PNG..." vẫn không
     * hiểu vì sao ảnh trong máy mình lại không phải ảnh.
     */
    public static function messages(bool $allowSvg = false): array
    {
        $list = $allowSvg ? 'JPG, PNG, WEBP, GIF, AVIF hoặc SVG' : 'JPG, PNG, WEBP, GIF hoặc AVIF';

        return [
            'image.required' => 'Chưa chọn ảnh.',
            'image.file' => 'File tải lên không hợp lệ.',
            'image.mimes' => 'Chỉ nhận ' . $list . '. Ảnh chụp bằng iPhone (.HEIC) thì mở ra và lưu lại thành JPG trước khi tải lên.',
            'image.max' => 'Ảnh tối đa ' . (int) (self::MAX_UPLOAD_KB / 1024) . 'MB.',
        ];
    }

    /**
     * Cất ảnh vào thư mục `$folder` của ổ đĩa công khai, trả về URL đầy đủ.
     *
     * Ảnh được thu nhỏ và nén lại; hỏng bước nào thì lưu nguyên file gốc — ảnh
     * nặng vẫn hơn là mất luôn thứ người dùng vừa tải lên.
     */
    public static function put(UploadedFile $file, string $folder): string
    {
        $path = self::shrink($file, $folder) ?? $file->store($folder, 'public');

        return url(Storage::url($path));
    }

    /**
     * Xoá một tệp ảnh khỏi ổ đĩa, nhận vào chính ĐƯỜNG DẪN mà put() đã trả về.
     *
     * Không có hàm này thì mỗi lượt đổi ảnh để lại một tệp mồ côi: không hồ sơ
     * nào trỏ tới, không màn hình nào hiện ra, và không ai biết để dọn. Một cửa
     * hàng sửa ảnh vài trăm lượt là ổ đĩa đầy những thứ như vậy.
     *
     * Tên tệp do put() sinh ngẫu nhiên nên hai hồ sơ không bao giờ dùng chung một
     * tệp — xoá ở đây không kéo mất ảnh của ai khác.
     *
     * Im lặng bỏ qua khi đường dẫn rỗng, không thuộc ổ đĩa này, hoặc tệp đã biến
     * mất: đây là việc DỌN DẸP đi kèm một thao tác khác, không phải thao tác
     * chính. Ném lỗi ở đây là làm hỏng lượt lưu hồ sơ vì một tệp rác.
     */
    public static function xoa(?string $url): void
    {
        $url = trim((string) $url);
        if ($url === '') {
            return;
        }

        // put() trả URL đầy đủ; cắt lấy phần đường dẫn rồi bỏ tiền tố /storage để
        // ra đúng khoá trên ổ đĩa. Đường dẫn của nơi khác (ảnh dán từ web) không
        // khớp tiền tố này nên rơi ra ngoài — đúng ý, ta chỉ dọn thứ mình tạo.
        $duong = parse_url($url, PHP_URL_PATH) ?: $url;
        $tien = rtrim(parse_url((string) config('filesystems.disks.public.url'), PHP_URL_PATH) ?: '/storage', '/');

        if ($tien === '' || ! str_starts_with($duong, $tien.'/')) {
            return;
        }

        $khoa = ltrim(substr($duong, strlen($tien)), '/');
        if ($khoa === '' || str_contains($khoa, '..')) {
            return;
        }

        try {
            Storage::disk('public')->delete($khoa);
        } catch (\Throwable $e) {
            Log::warning('Khong xoa duoc anh cu', ['khoa' => $khoa, 'msg' => $e->getMessage()]);
        }
    }

    /**
     * Thu nhỏ + nén, trả về đường dẫn tương đối trong ổ đĩa, hoặc null nếu không
     * xử lý được (định dạng GD không đọc nổi, ảnh hỏng, hết bộ nhớ...).
     */
    private static function shrink(UploadedFile $file, string $folder): ?string
    {
        // SVG là ảnh vector, không có "điểm ảnh" để thu nhỏ.
        if (strtolower($file->getClientOriginalExtension()) === 'svg') {
            return null;
        }
        if (! extension_loaded('gd')) {
            return null;
        }

        try {
            $src = @imagecreatefromstring(file_get_contents($file->getRealPath()));
            if ($src === false) {
                return null;
            }

            $src = self::applyExifRotation($src, $file->getRealPath());

            $w = imagesx($src);
            $h = imagesy($src);
            $scale = min(1, self::MAX_EDGE / max($w, $h));
            $newW = max(1, (int) round($w * $scale));
            $newH = max(1, (int) round($h * $scale));

            if ($scale < 1) {
                $dst = imagecreatetruecolor($newW, $newH);
                // Giữ nền trong suốt của PNG/GIF/WEBP; thiếu hai dòng này thì vùng
                // trong suốt biến thành đen sau khi thu nhỏ.
                imagealphablending($dst, false);
                imagesavealpha($dst, true);
                imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $w, $h);
                imagedestroy($src);
                $src = $dst;
            }

            $ext = self::outputExtension($file);
            $path = $folder . '/' . \Illuminate\Support\Str::random(40) . '.' . $ext;
            $tmp = tempnam(sys_get_temp_dir(), 'img');

            $ok = match ($ext) {
                'png' => imagepng($src, $tmp, 6),
                'webp' => imagewebp($src, $tmp, self::QUALITY),
                'avif' => imageavif($src, $tmp, self::QUALITY),
                'gif' => imagegif($src, $tmp),
                default => imagejpeg($src, $tmp, self::QUALITY),
            };
            imagedestroy($src);

            if (! $ok) {
                @unlink($tmp);

                return null;
            }

            // Ảnh gốc đã nhẹ hơn bản vừa nén (ảnh nhỏ, tối ưu sẵn) thì giữ bản gốc.
            if (filesize($tmp) >= $file->getSize() && $scale >= 1) {
                @unlink($tmp);

                return null;
            }

            Storage::disk('public')->put($path, file_get_contents($tmp));
            @unlink($tmp);

            return $path;
        } catch (\Throwable $e) {
            Log::warning('Nén ảnh thất bại, lưu nguyên bản', ['msg' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Định dạng ghi ra: giữ nguyên loại của ảnh gốc.
     *
     * Không tự đổi hết sang JPEG vì PNG/GIF/WEBP có nền trong suốt — logo bị ép
     * sang JPEG sẽ mọc ra một mảng nền trắng (hoặc đen) quanh nó.
     */
    private static function outputExtension(UploadedFile $file): string
    {
        return match (strtolower($file->getClientOriginalExtension())) {
            'png' => 'png',
            'gif' => 'gif',
            'webp' => 'webp',
            // Giữ AVIF chỉ khi PHP dựng được nó; bản GD thiếu bộ mã hoá AVIF sẽ
            // trả về false ở imageavif() và ta mất trắng ảnh vừa tải lên.
            'avif' => function_exists('imageavif') ? 'avif' : 'jpg',
            default => 'jpg',
        };
    }

    /**
     * Xoay ảnh theo thẻ EXIF của máy chụp.
     *
     * Ảnh chụp dọc bằng điện thoại thường được lưu nằm ngang kèm một thẻ "xoay 90
     * độ khi hiển thị". GD bỏ qua thẻ đó, nên không xử lý thì ảnh sản phẩm lên
     * website bị nằm nghiêng.
     */
    private static function applyExifRotation(\GdImage $img, string $path): \GdImage
    {
        if (! function_exists('exif_read_data')) {
            return $img;
        }

        try {
            $exif = @exif_read_data($path);
            $orientation = $exif['Orientation'] ?? 0;
            $degrees = match ($orientation) {
                3 => 180,
                6 => -90,
                8 => 90,
                default => 0,
            };
            if ($degrees === 0) {
                return $img;
            }
            $rotated = imagerotate($img, $degrees, 0);
            if ($rotated === false) {
                return $img;
            }
            imagedestroy($img);

            return $rotated;
        } catch (\Throwable $e) {
            return $img;
        }
    }
}
