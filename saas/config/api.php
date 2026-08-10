<?php

return [
    // Base URL của Backend Go API (đã bao gồm /api/v1).
    'base_url' => rtrim(env('API_BASE_URL', 'http://localhost:8080/api/v1'), '/'),

    // URL mà TRÌNH DUYỆT dùng để gọi API. Luồng realtime (SSE /events) nối thẳng
    // từ trình duyệt sang Go, không đi qua Laravel: `php artisan serve` chỉ chạy
    // một tiến trình nên một kết nối giữ mở sẽ treo toàn bộ trang quản trị.
    //
    // Thường trùng base_url; chỉ cần khai riêng khi API chạy trong Docker và tên
    // host nội bộ (vd http://api:8080) không phân giải được từ máy người dùng.
    'public_url' => rtrim(env('API_PUBLIC_URL', env('API_BASE_URL', 'http://localhost:8080/api/v1')), '/'),

    // Thời gian chờ tối đa mỗi request (giây).
    'timeout' => (int) env('API_TIMEOUT', 15),
];
