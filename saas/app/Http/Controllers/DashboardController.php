<?php

namespace App\Http\Controllers;

class DashboardController extends Controller
{
    /**
     * Trang tổng quan.
     *
     * Các ô số liệu cố tình để trống (hiển thị "—") thay vì bịa số hay đếm tạm
     * từ dữ liệu của một cửa hàng: nền tảng chưa có bảng cửa hàng nào, mọi con
     * số hiện ra lúc này đều sai. Ô nào cũng chỉ nối vào /platform/* khi Go API
     * mở nhóm đó.
     *
     * $apiOnline không lấy ở đây: View composer trong AppServiceProvider đã bơm
     * cho cả `dashboard` lẫn `partials.sidebar`, và có cache 30 giây — gọi thêm
     * một lần nữa ở đây là gọi /health hai lượt cho cùng một lần mở trang.
     */
    public function index()
    {
        return view('dashboard', [
            'user' => session('api.user'),
        ]);
    }
}
