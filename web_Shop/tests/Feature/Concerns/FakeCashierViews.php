<?php

namespace Tests\Feature\Concerns;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\View;

/**
 * View GIẢ cho các màn thu ngân — để kiểm CONTROLLER mà không phụ thuộc view.
 *
 * Nối thêm một thư mục tạm vào namespace `v2`. Laravel tìm trong v2/ thật TRƯỚC:
 * màn nào đã có view thật thì bài kiểm dùng view thật, chưa có mới rơi vào bản giả.
 * Bài kiểm chỉ soát controller trả VIEW NÀO với DỮ LIỆU GÌ (assertViewIs /
 * assertViewHas); hình dáng trang là việc của bài kiểm view.
 */
trait FakeCashierViews
{
    protected function giaLapViewThuNgan(): void
    {
        $thuMuc = storage_path('framework/testing/view-pos');
        File::ensureDirectoryExists($thuMuc.'/pos');
        foreach (['sale', 'receipt', 'work-shift', 'work-shift-detail', 'orders'] as $ten) {
            File::put("$thuMuc/pos/$ten.blade.php", "view giả: $ten");
        }
        View::addNamespace('v2', $thuMuc);
    }
}
