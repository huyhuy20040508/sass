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

    Nhóm xổ (.rail-drop) mang tên MỘT PHẦN MỀM: "QLTK khách hàng order" là sổ
    khách của Sellio Order. Nền tảng bán nhiều phần mềm, nên khi có phần mềm thứ
    hai thì thêm một nhóm nữa cạnh nó — chứ không gộp thành một nhóm chung rồi
    bắt người dùng đổi bộ chọn phần mềm ở trong trang: lúc đó thanh trái không
    còn nói được đang xem phần mềm nào.

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

    @php
        // Mục con của nhóm "QLTK khách hàng order". Để thành mảng chứ không viết
        // tay 5 thẻ <a>: trạng thái "đang mở" của nhóm cha suy ra từ chính danh
        // sách này, nên không có cách nào thêm mục mới mà quên khai báo nó với
        // cái nút bật/tắt.
        $muc_order = [
            ['route' => 'platform.khach-hang-order.nguoi-dung-thu',  'nhan' => 'Người dùng thử'],
            ['route' => 'platform.khach-hang-order.nguoi-chinh-thuc', 'nhan' => 'Người chính thức'],
            ['route' => 'platform.khach-hang-order.goi-dich-vu',      'nhan' => 'Các gói dịch vụ'],
            ['route' => 'platform.khach-hang-order.tinh-nang-goi',    'nhan' => 'Tính năng gói'],
            ['route' => 'platform.khach-hang-order.database',         'nhan' => 'Database'],
        ];

        // Nhóm mở sẵn khi đang đứng ở một trang bên trong nó. Người ta vừa bấm
        // vào đấy xong: mở trang mới ra mà nhóm đã cụp lại thì đọc như menu quên
        // mất mình đang ở đâu.
        $order_dang_mo = request()->routeIs('platform.khach-hang-order.*');
    @endphp

    <nav class="rail-nav">
        <p class="rail-group">Điều hành nền tảng</p>
        <a href="{{ route('platform.dashboard') }}"
           class="rail-link {{ request()->routeIs('platform.dashboard') ? 'is-current' : '' }}"
           @if (request()->routeIs('platform.dashboard')) aria-current="page" @endif>
            Tổng quan
        </a>

        {{-- Nhóm xổ. Cái mũi tên là NÚT ĐIỀU KHIỂN chứ không phải biểu tượng
             trang trí cho mục menu, nên nó không phạm vào quy tắc "menu chỉ có
             chữ" ở đầu tệp — cùng lý do với ba vạch của nút mở ngăn kéo. Vẽ
             bằng CSS, không mượn phông biểu tượng. --}}
        <div class="rail-drop {{ $order_dang_mo ? 'is-open' : '' }}" data-rail-drop>
            <button type="button" class="rail-link rail-drop-toggle {{ $order_dang_mo ? 'is-parent' : '' }}"
                    aria-expanded="{{ $order_dang_mo ? 'true' : 'false' }}"
                    aria-controls="drop-order">
                <span>QLTK khách hàng order</span>
                <span class="rail-caret" aria-hidden="true"></span>
            </button>

            {{-- hidden (không phải chỉ cao 0): nhóm cụp lại mà mục con vẫn "nhìn
                 thấy được" thì phím Tab vẫn nhảy vào 5 đường dẫn đang bị giấu. --}}
            <div class="rail-drop-panel" id="drop-order" @unless ($order_dang_mo) hidden @endunless>
                @foreach ($muc_order as $muc)
                    <a href="{{ route($muc['route']) }}"
                       class="rail-sublink {{ request()->routeIs($muc['route']) ? 'is-current' : '' }}"
                       @if (request()->routeIs($muc['route'])) aria-current="page" @endif>
                        {{ $muc['nhan'] }}
                    </a>
                @endforeach
            </div>
        </div>

        {{-- Cài đặt của NHÀ CUNG CẤP: không thuộc phần mềm nào nên đứng ngoài
             nhóm sản phẩm ở trên, và đứng cuối — thứ sửa vài tháng một lần thì
             không tranh chỗ với thứ mở hằng ngày. --}}
        <a href="{{ route('platform.cai-dat.thanh-toan') }}"
           class="rail-link {{ request()->routeIs('platform.cai-dat.*') ? 'is-current' : '' }}"
           @if (request()->routeIs('platform.cai-dat.*')) aria-current="page" @endif>
            Phương thức thanh toán
        </a>
    </nav>

    <div class="rail-status">
        {{-- Chỉ chấm màu và một chữ trạng thái. KHÔNG in config('api.base_url')
             ra đây: địa chỉ đó là hằng số, hiện thường trực ở mọi trang thì chỉ
             là nhiễu, mà vẫn là chi tiết hạ tầng nội bộ. Ai cần tra thì xem
             saas/.env. --}}
        <div class="rail-status-line">
            <span class="dot {{ ($apiOnline ?? false) ? 'dot-good' : 'dot-bad' }}"></span>
            <span>Go API — {{ ($apiOnline ?? false) ? 'đang chạy' : 'mất kết nối' }}</span>
        </div>
    </div>
</aside>
