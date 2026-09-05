<?php

namespace Tests\Feature;

use App\Services\ApiClient;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Mọi lượt gọi ApiClient trong app phải trỏ vào một method CÓ THẬT.
 *
 * VÌ SAO CẦN BÀI NÀY: gần như mọi chỗ gọi API đều bọc `try { … } catch
 * (\Throwable $e)` để một trục trặc mạng không làm vỡ cả trang. Nhưng
 * `\Error` — thứ PHP ném ra khi gọi method không tồn tại — cũng là
 * `\Throwable`, nên nó rơi vào đúng cái catch ấy và biến thành "API đang lỗi".
 * Trang vẫn 200, danh sách vẫn rỗng, huy hiệu vẫn 0 — không có gì đỏ lên.
 *
 * Đó không phải chuyện giả định: cả cụm "Yêu cầu của khách" và "Đăng ký nhận
 * tin" gọi sáu method chưa ai viết, và nó im lặng như thế suốt cho tới lúc có
 * người mở tệp ra và thấy trình soạn thảo gạch đỏ.
 */
class ApiClientDuMethodTest extends TestCase
{
    public function test_moi_loi_goi_api_deu_co_method_that(): void
    {
        $co = [];
        foreach ((new ReflectionClass(ApiClient::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {
            $co[$m->getName()] = true;
        }

        $thieu = [];
        foreach ($this->tepPhpCuaApp() as $tep) {
            $noiDung = (string) file_get_contents($tep);

            // Ba hình dạng đang dùng trong dự án: thuộc tính `$this->api`,
            // biến cục bộ `$api`, và lượt lấy thẳng từ container.
            preg_match_all(
                '/(?:\$this->api|\$api|app\(ApiClient::class\))->(\w+)\s*\(/',
                $noiDung,
                $khop
            );

            foreach ($khop[1] as $ten) {
                if (! isset($co[$ten])) {
                    $thieu[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $tep).' gọi ApiClient::'.$ten.'()';
                }
            }
        }

        $thieu = array_values(array_unique($thieu));

        $this->assertSame([], $thieu,
            "Có lượt gọi trỏ vào method ApiClient không tồn tại — chỗ gọi bọc catch \Throwable nên\n"
            ."nó chỉ hiện ra dưới dạng 'API đang lỗi', dữ liệu rỗng, không có gì đỏ:\n"
            .implode("\n", $thieu));
    }

    /** @return list<string> */
    protected function tepPhpCuaApp(): array
    {
        $ra = [];
        $duyet = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($duyet as $tep) {
            if ($tep->isFile() && $tep->getExtension() === 'php') {
                $ra[] = $tep->getPathname();
            }
        }

        return $ra;
    }
}
