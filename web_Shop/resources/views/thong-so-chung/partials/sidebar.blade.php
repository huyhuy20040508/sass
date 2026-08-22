{{-- Thanh bên của khu Thông số chung — mỗi trang con một mục, đúng lối bản cũ.
     Danh sách lấy từ ThongSoChungController::PAGES nên thêm trang là hiện ngay. --}}
<nav class="tsc-nav">
    <div class="tsc-nav-head">{{ \App\Http\Controllers\ThongSoChungController::TITLE }}</div>

    @foreach(\App\Http\Controllers\ThongSoChungController::PAGES as $ma => $trang)
        <a href="{{ route('admin.thong-so-chung.'.$ma) }}"
           class="tsc-nav-item {{ $page === $ma ? 'is-active' : '' }}">{{ $trang['title'] }}</a>
    @endforeach
</nav>
