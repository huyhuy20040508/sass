<?php

/**
 * Đếm thẻ mở / thẻ đóng của HTML mà một view v2 dựng ra.
 *
 * Vì sao cần: Blade biên dịch xong không nói gì về HTML — một khối bị cắt hụt
 * `</div>` vẫn "compile sạch", rồi trình duyệt tự đóng thẻ sớm và nuốt mất nửa
 * trang. Đúng lỗi vừa gặp ở màn Hàng hoá.
 *
 * Chạy:  php can_bang_the.php
 */
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
view()->share('errors', new Illuminate\Support\ViewErrorBag);

$man = [
    'v2::hang-hoa.index' => [
        'products' => [], 'meta' => ['page' => 1, 'page_size' => 20, 'total' => 0, 'total_pages' => 1],
        'filters' => ['keyword' => '', 'category_ids' => [], 'shop_id' => 0, 'location_id' => '', 'unit_id' => 0,
            'statuses' => [], 'multi_variant' => '', 'sort' => 'newest', 'per_page' => 20, 'page' => 1],
        'categories' => [], 'nhomCoHang' => [], 'locations' => [], 'units' => [], 'attributes' => [],
        'branches' => [], 'tags' => [], 'vatRates' => [0, 8, 10],
        'loaiThue' => [['loai' => 'mac-dinh', 'ten' => 'Thuế mặc định', 'muc' => [0, 8, 10]]],
        'napLoi' => [],
        'statuses' => ['active' => 'a', 'hidden' => 'b', 'discontinued' => 'c'],
        'statusHints' => ['active' => '', 'hidden' => '', 'discontinued' => ''],
        'sorts' => [], 'perPageOptions' => [10, 20, 30, 40, 50], 'maTuSinh' => false,
    ],
    'v2::nha-cung-cap.index' => [
        'list' => [], 'filters' => ['keyword' => '', 'status' => '', 'sort' => 'moi_nhat', 'page' => 1, 'page_size' => 20],
        'thongKe' => ['tong' => 0, 'dang_hop_tac' => 0],
        'meta' => ['page' => 1, 'page_size' => 20, 'total' => 0, 'total_pages' => 1],
    ],
    'v2::don-vi-tinh.index' => [
        'list' => [], 'tong' => 0, 'dangDung' => 0, 'stt' => 0,
        'meta' => ['page' => 1, 'total_pages' => 1, 'total' => 0, 'page_size' => 20],
        'filters' => ['keyword' => '', 'status' => ''],
    ],
    'v2::thuoc-tinh.index' => [
        'list' => [], 'tong' => 0, 'dangDung' => 0, 'stt' => 0,
        'meta' => ['page' => 1, 'total_pages' => 1, 'total' => 0, 'page_size' => 20],
        'filters' => ['keyword' => '', 'status' => ''],
    ],
    'v2::thue.index' => ['taxes' => []],
    'v2::nhom-hang-hoa.index' => ['categories' => [], 'vatRates' => [0, 8, 10]],
];

$hong = 0;

foreach ($man as $ten => $dl) {
    try {
        $html = view($ten, $dl)->render();
    } catch (\Throwable $e) {
        echo str_pad($ten, 26), "LỖI DỰNG: ", $e->getMessage(), PHP_EOL;
        $hong++;

        continue;
    }

    // Bỏ phần trong <script>/<style> ra: chuỗi JS có thể chứa "<div".
    $sach = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#si', '', $html);

    $mo = preg_match_all('#<div\b#i', $sach);
    $dong = preg_match_all('#</div>#i', $sach);

    $ok = $mo === $dong;
    printf("%-26s div mở %-4d đóng %-4d  %s\n", $ten, $mo, $dong, $ok ? 'CÂN' : 'LỆCH '.($mo - $dong));
    if (! $ok) {
        $hong++;
    }
}

exit($hong === 0 ? 0 : 1);
