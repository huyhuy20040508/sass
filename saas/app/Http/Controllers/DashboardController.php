<?php

namespace App\Http\Controllers;

use App\Services\ApiClient;

class DashboardController extends Controller
{
    public function __construct(protected ApiClient $api) {}

    /**
     * Trang tổng quan.
     *
     * Các ô số liệu cố tình để trống (hiển thị "—") thay vì bịa số hay đếm tạm
     * từ dữ liệu của một cửa hàng: nền tảng chưa có bảng cửa hàng nào, mọi con
     * số hiện ra lúc này đều sai. Ô nào cũng chỉ nối vào /platform/* khi Go API
     * mở nhóm đó.
     */
    public function index()
    {
        return view('dashboard', [
            'apiOnline' => $this->api->health(),
            'user' => session('api.user'),
        ]);
    }
}
