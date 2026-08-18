<?php

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Ảnh tải lên phải rơi vào đúng thư mục mà `/storage/...` phục vụ.
 *
 * Đã hỏng đúng kiểu này một lần, và hỏng IM LẶNG: đĩa `public` trỏ vào
 * storage/app/public theo mặc định của Laravel, nhưng public/storage trên máy
 * lại là một THƯ MỤC THẬT chứa sẵn ảnh sản phẩm, danh mục, banner — không phải
 * liên kết tượng trưng. Ảnh mới tải lên rơi vào storage/app/public rồi nằm im:
 * lượt tải báo thành công, đường dẫn trả về trông đúng, ảnh cũ vẫn hiện, chỉ
 * ảnh mới là 403. Không có gì trên màn hình chỉ ra chỗ sai.
 */
class AnhTaiLenTest extends TestCase
{
    /**
     * Nơi ĐĨA GHI VÀO và nơi WEB PHỤC VỤ phải là một.
     *
     * So bằng realpath nên bài này đúng ở cả hai kiểu cài: máy dùng thư mục thật,
     * và máy chủ có liên kết tượng trưng (realpath đi xuyên qua liên kết ra cùng
     * một chỗ).
     */
    public function test_dia_public_ghi_vao_thu_muc_duoc_phuc_vu(): void
    {
        // KHÔNG đòi thư mục phải có sẵn: trên máy vừa clone (CI) thì public/storage
        // chưa tồn tại — nó do `php artisan storage:link` dựng lúc triển khai. Đòi
        // realpath thành công ở đây là bắt bài kiểm phụ thuộc vào việc ai đó đã
        // chạy một lệnh khác trước đó.
        //
        // Nên chuẩn hoá rồi so VỊ TRÍ ĐÃ CẤU HÌNH: có thật thì realpath (đi xuyên
        // qua liên kết tượng trưng trên máy chủ), chưa có thì so chính đường dẫn.
        $chuanHoa = static fn (string $duong): string => rtrim(
            str_replace('\\', '/', realpath($duong) ?: $duong), '/'
        );

        $this->assertSame(
            $chuanHoa(public_path('storage')),
            $chuanHoa((string) config('filesystems.disks.public.root')),
            'Ảnh tải lên rơi vào một chỗ, còn /storage phục vụ một chỗ khác'
        );
    }

    /** Đường dẫn trả về phải là URL đầy đủ dưới /storage, không phải đường tương đối. */
    public function test_duong_dan_tra_ve_nam_duoi_storage(): void
    {
        Storage::fake('public');

        $url = \App\Services\ImageStore::put(
            UploadedFile::fake()->image('thu.jpg', 300, 300), 'nhan-su'
        );

        $this->assertStringStartsWith('http', $url, 'Phải là URL đầy đủ để dùng thẳng trong src=""');
        $this->assertStringContainsString('/storage/nhan-su/', $url);
    }

    /**
     * Đổi ảnh thì tệp CŨ bị dọn — không để lại rác không ai trỏ tới.
     *
     * Tệp mồ côi không hiện ra ở đâu cả: không hồ sơ nào trỏ tới, không màn hình
     * nào liệt kê. Một cửa hàng sửa ảnh vài trăm lượt là ổ đĩa đầy những thứ như
     * vậy mà chẳng ai biết để dọn.
     */
    public function test_doi_anh_thi_xoa_tep_cu(): void
    {
        Storage::fake('public');

        $cu = \App\Services\ImageStore::put(
            UploadedFile::fake()->image('cu.jpg', 200, 200), 'nhan-su'
        );
        $khoa = ltrim(parse_url($cu, PHP_URL_PATH), '/');
        $khoa = preg_replace('#^storage/#', '', $khoa);
        Storage::disk('public')->assertExists($khoa);

        \App\Services\ImageStore::xoa($cu);

        Storage::disk('public')->assertMissing($khoa);
    }

    /**
     * xoa() im lặng bỏ qua thứ không phải của mình.
     *
     * Đây là việc DỌN DẸP đi kèm một thao tác khác, không phải thao tác chính —
     * ném lỗi ở đây là làm hỏng lượt lưu hồ sơ vì một tệp rác. Và đường dẫn của
     * nơi khác (ảnh dán từ web) thì tuyệt đối không được đụng tới.
     */
    public function test_xoa_bo_qua_duong_dan_la(): void
    {
        Storage::fake('public');

        foreach (['', '   ', 'https://example.com/anh/x.jpg', '/khong-phai-storage/x.jpg'] as $la) {
            \App\Services\ImageStore::xoa($la);
        }

        $this->assertTrue(true, 'Không được ném lỗi với đường dẫn lạ');
    }
}
