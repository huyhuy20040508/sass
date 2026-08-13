{{--
    Thanh trái của khu điều hành nền tảng.

    Không dùng biểu tượng: menu chỉ có chữ. Bù lại chữ được đặt kiểu nhãn (chữ
    hoa, cỡ nhỏ, giãn chữ cái) để vẫn đọc ra ngay là mục điều hướng — xem
    .rail-link trong layouts/app.blade.php.

    Số đo (bề ngang 230px, hàng logo 56px, mục cao 36px) lấy đúng theo sidebar
    của Shop Admin để hai app mở cạnh nhau không lệch nhau.

    Chỉ liệt kê trang đã có thật. Trước đây chỗ này còn một loạt mục "Sắp có"
    làm bản đồ sản phẩm, nhưng một menu mà đa số bấm không được thì đọc như phần
    mềm hỏng chứ không như lộ trình — bản đồ đó nằm ở trang Tổng quan. Làm xong
    trang nào thì thêm <a> vào đây lúc đó.

    $apiOnline do View composer trong AppServiceProvider bơm vào (cache 30 giây),
    nên dải tình trạng ở đáy luôn có dữ liệu dù trang nào gọi tới.
--}}
<aside class="rail" id="rail">
    {{-- Logo bản chữ sáng vì nền là tím đậm. Khu điều hành nền tảng là của mình,
         không phải của khách, nên logo gắn cứng chứ không lấy từ cấu hình như
         bên Shop Admin. --}}
    <a href="{{ route('platform.dashboard') }}" class="rail-brand">
        <img src="{{ asset('images/logo-default-wide-light.svg') }}" alt="{{ config('app.name') }}">
    </a>

    <nav class="rail-nav">
        <p class="rail-group">Điều hành nền tảng</p>
        <a href="{{ route('platform.dashboard') }}"
           class="rail-link {{ request()->routeIs('platform.dashboard') ? 'is-current' : '' }}"
           @if (request()->routeIs('platform.dashboard')) aria-current="page" @endif>
            Tổng quan
        </a>
    </nav>

    <div class="rail-status">
        <div class="rail-status-line">
            <span class="dot {{ ($apiOnline ?? false) ? 'dot-good' : 'dot-bad' }}"></span>
            <span>Go API — {{ ($apiOnline ?? false) ? 'đang chạy' : 'mất kết nối' }}</span>
        </div>
        <span class="rail-status-host">{{ config('api.base_url') }}</span>
    </div>
</aside>
