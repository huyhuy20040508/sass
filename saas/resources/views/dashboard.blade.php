@extends('layouts.app')

@section('title', 'Tổng quan')

@section('content')
    <div class="mb-4">
        <h2 class="h5 fw-semibold mb-1">Xin chào, {{ data_get($user, 'full_name') ?: 'quản trị viên' }}</h2>
        <p class="text-muted-x mb-0 small">Khu điều hành nền tảng {{ config('app.name') }}.</p>
    </div>

    {{-- Các ô số liệu để "—" chứ không hiển thị 0: nền tảng chưa có bảng cửa
         hàng nào, nên 0 sẽ bị đọc thành "chưa bán được shop nào" trong khi thực
         tế là "chưa làm tới phần đếm". Nối vào /platform/* rồi mới thay số. --}}
    <div class="row g-3 mb-4">
        @foreach ([
            ['Cửa hàng đang hoạt động', 'bi-shop', '#4f46e5'],
            ['Sắp hết hạn (30 ngày)', 'bi-hourglass-split', '#d97706'],
            ['Doanh thu tháng này', 'bi-cash-coin', '#059669'],
            ['Yêu cầu chờ xử lý', 'bi-inbox', '#dc2626'],
        ] as [$label, $icon, $color])
            <div class="col-12 col-sm-6 col-xl-3">
                <div class="card-x p-3 h-100">
                    <div class="d-flex align-items-start justify-content-between">
                        <div>
                            <div class="text-muted-x small">{{ $label }}</div>
                            <div class="fs-3 fw-semibold lh-1 mt-2">—</div>
                        </div>
                        <span class="d-grid place-items-center rounded-3"
                              style="width:38px;height:38px;background:{{ $color }}1a;color:{{ $color }};display:grid;place-items:center">
                            <i class="bi {{ $icon }}"></i>
                        </span>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="row g-3">
        <div class="col-12 col-lg-7">
            <div class="card-x p-4 h-100">
                <h3 class="h6 fw-semibold mb-3">Còn thiếu gì để trang này có số liệu</h3>
                <ol class="small mb-0 ps-3 text-muted-x">
                    <li class="mb-2">
                        Go API mở nhóm <code>/platform/*</code> kèm bảng
                        <code>shops</code>, <code>plans</code>, <code>subscriptions</code>.
                    </li>
                    <li class="mb-2">
                        Tách vai trò: <code>super_admin</code> phải mang nghĩa "quản trị nền tảng",
                        không còn là vai trò cao nhất trong một cửa hàng.
                    </li>
                    <li class="mb-0">
                        Thêm cột phân tách cửa hàng vào dữ liệu nghiệp vụ, và sửa các
                        UNIQUE KEY đang ở phạm vi toàn cục.
                    </li>
                </ol>
            </div>
        </div>

        <div class="col-12 col-lg-5">
            <div class="card-x p-4 h-100">
                <h3 class="h6 fw-semibold mb-3">Tình trạng hệ thống</h3>

                <div class="d-flex align-items-center justify-content-between py-2 border-bottom">
                    <span class="small">Go API</span>
                    @if ($apiOnline)
                        <span class="badge text-bg-success">Đang chạy</span>
                    @else
                        <span class="badge text-bg-danger">Không kết nối được</span>
                    @endif
                </div>
                <div class="d-flex align-items-center justify-content-between py-2 border-bottom">
                    <span class="small">Địa chỉ API</span>
                    <code class="small">{{ config('api.base_url') }}</code>
                </div>
                <div class="d-flex align-items-center justify-content-between py-2">
                    <span class="small">Vai trò đang dùng</span>
                    <span class="badge text-bg-secondary">{{ data_get($user, 'role.name') }}</span>
                </div>

                @unless ($apiOnline)
                    <div class="alert alert-warning small mt-3 mb-0">
                        Không gọi được <code>/health</code>. Kiểm tra Go API đã chạy ở
                        cổng 8080 chưa, và <code>API_BASE_URL</code> trong <code>saas/.env</code>.
                    </div>
                @endunless
            </div>
        </div>
    </div>
@endsection
