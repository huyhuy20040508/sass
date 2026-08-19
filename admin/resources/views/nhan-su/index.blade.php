@extends('layouts.app')

@section('title', \App\Http\Controllers\NhanSuController::TITLE_PAGE)

@section('content')
    {{-- Trang Nhân sự: [header] + [thanh lọc] + [bảng] + [modal thêm/sửa].
         Tên ô của form = tên trường của API nên controller gửi thẳng payload đi. --}}
    @php
        $C = \App\Http\Controllers\NhanSuController::class;
        $TITLE = $C::TITLE_PAGE;
        $EMPTY_TEXT = $C::EMPTY_TEXT;

        $so = fn ($n) => number_format((float) $n, 0, ',', '.');
        $dt = fn ($s) => filled($s) ? \Illuminate\Support\Carbon::parse($s)->format('d/m/Y') : '';

        $dangLam = collect($list)->where('status', 'dang_lam')->count();

        // Hàng lọc chính giữ ô tìm + trạng thái; phần còn lại nằm trong "Nâng cao",
        // hàng đó tự mở kèm con số trên nút khi đang có điều kiện lọc.
        $advCount = ($filters['work_shift'] !== '' ? 1 : 0)
            + ($filters['shop_id'] > 0 ? 1 : 0);
        $advOpen = $advCount > 0;
        $hasFilter = $advCount > 0 || $filters['keyword'] !== '' || $filters['status'] !== '';

        // Bấm Lưu mà hỏng thì mở lại hộp thoại kèm dữ liệu vừa gõ.
        $moLaiForm = filled(old('full_name'));
    @endphp

    <div class="nsu">
        {{-- Header: tiêu đề + một dòng tổng. Nút "Thêm" nằm cuối thanh lọc. --}}
        <div class="nsu-head">
            <h1 class="nsu-title">{{ $TITLE }}</h1>
            <span class="nsu-sum">
                Đang làm: <b>{{ $so($dangLam) }}</b>/{{ $so(count($list)) }} người
            </span>
        </div>

        @if(!empty($error))
            <p class="nsu-callout is-error">{{ $error }}</p>
        @endif

        {{-- Bộ lọc realtime: đổi select chạy ngay, gõ thì chờ 400ms. Không có nút
             "Lọc" — quy tắc chung của mọi trang danh sách trong dự án. --}}
        <form method="GET" action="{{ route('admin.nhan-su.index') }}" id="nsuFilter" class="nsu-filter">
            <div class="nsu-toolbar">
                <div class="nsu-searchbox">
                    <input type="text" name="keyword" value="{{ $filters['keyword'] }}" class="nsu-search-input"
                           placeholder="Tìm theo tên, mã nhân viên hoặc SĐT" autocomplete="off">
                    <button type="submit" class="nsu-search-btn" title="Tìm kiếm">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                    </button>
                </div>

                <select name="status" class="nsu-select" title="Lọc theo trạng thái làm việc">
                    <option value="">Tất cả trạng thái</option>
                    @foreach($C::TRANG_THAI as $ma => $ten)
                        <option value="{{ $ma }}" @selected($filters['status'] === $ma)>{{ $ten }}</option>
                    @endforeach
                </select>

                <button type="button" id="nsuAdvToggle" class="nsu-adv-btn {{ $advOpen ? 'is-open' : '' }}"
                        aria-expanded="{{ $advOpen ? 'true' : 'false' }}">
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M7 12h10M11 18h2"/></svg>
                    Nâng cao
                    <svg class="nsu-adv-caret" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                    @if($advCount > 0)<span class="nsu-adv-count">{{ $advCount }}</span>@endif
                </button>

                {{-- "Xoá lọc" ở hàng chính: một điều kiện đang ẩn mà không thấy đường
                     gỡ ra là cách nhanh nhất để tưởng danh sách mất dữ liệu. --}}
                @if($hasFilter)
                    <a href="{{ route('admin.nhan-su.index') }}" class="nsu-clear">Xoá lọc</a>
                @endif

                <div class="nsu-toolbar-actions">
                    <button type="button" class="nsu-btn-primary" data-nsu-add>
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M12 5v14"/></svg>
                        Thêm nhân viên
                    </button>

                    {{-- Xuất Excel mang theo ĐÚNG bộ lọc đang xem.
                         Người ta lọc ra một nhóm rồi mới bấm xuất — trả về cả bảng
                         thì họ phải lọc lại lần nữa trong Excel, và cái thanh lọc
                         phía trên thành ra nói dối. --}}
                    <a href="{{ route('admin.nhan-su.export', array_filter($filters, fn ($v) => $v !== '' && $v !== 0)) }}"
                       class="nsu-btn-ghost">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5"/><path d="M12 15V3"/></svg>
                        Xuất Excel
                    </a>
                </div>
            </div>

            {{-- Hàng nâng cao: ca làm + chi nhánh. --}}
            <div class="nsu-toolbar-adv {{ $advOpen ? 'is-open' : '' }}" id="nsuAdvRow">
                {{-- Lọc theo MỘT ca; người trực cả sáng lẫn chiều vẫn lọt vào cả hai
                     lượt lọc (API dùng FIND_IN_SET trên cột SET). --}}
                <select name="work_shift" class="nsu-select" title="Lọc theo ca làm việc">
                    <option value="">Tất cả ca làm</option>
                    @foreach($C::CA_LAM as $ma => $ten)
                        <option value="{{ $ma }}" @selected($filters['work_shift'] === $ma)>{{ $ten }}</option>
                    @endforeach
                </select>

                <select name="shop_id" class="nsu-select" title="Lọc theo chi nhánh">
                    <option value="">Tất cả chi nhánh</option>
                    @foreach($chiNhanh as $cn)
                        <option value="{{ $cn['id'] }}" @selected($filters['shop_id'] === (int) $cn['id'])>{{ $cn['name'] }}</option>
                    @endforeach
                </select>
            </div>
        </form>

        {{-- THANH HÀNH ĐỘNG HÀNG LOẠT — chỉ hiện khi đã chọn ít nhất một dòng.

             Hiện sẵn lúc chưa chọn gì thì nó là một hàng nút bấm vào không xảy ra
             gì cả, nằm ngay trên bảng. Ẩn đi khi rỗng cũng là cách nói "chọn dòng
             trước đã" mà không cần viết câu nào. --}}
        {{-- Form RỖNG, đứng ngoài bảng. Mấy ô tick trong bảng nối vào nó bằng
             thuộc tính `form="nsuBulkForm"` chứ KHÔNG bọc bảng lại: mỗi dòng đã có
             form riêng cho công tắc trạng thái và nút xoá, mà form lồng form thì
             HTML không cho — trình duyệt bỏ cái bên trong, và hai nút ấy chết. --}}
        <form method="POST" id="nsuBulkForm" action="{{ route('admin.nhan-su.bulkDestroy') }}">
            @csrf
            <input type="hidden" name="status" id="nsuBulkTrangThai" value="">
        </form>

        <div class="nsu-bulkbar" id="nsuBulkBar" style="display:none;">
            {{-- Thứ tự như trang Sản phẩm: đếm, bỏ chọn, rồi mới tới hành động —
                 lối thoát đứng trước thứ không lùi lại được. --}}
            <span class="nsu-bulkbar__so">Đã chọn <b id="nsuBulkSo">0</b> hồ sơ</span>
            <button type="button" class="nsu-bulkbar__bo" id="nsuBulkBo">Bỏ chọn</button>
            <button type="button" class="nsu-btn-ghost" data-nsu-bulk="nghi">Đánh dấu nghỉ việc</button>
            <button type="button" class="nsu-btn-ghost is-danger" data-nsu-bulk="xoa">
                <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M6 6v14a1 1 0 0 0 1 1h10a1 1 0 0 0 1-1V6"/><path d="M10 11v6M14 11v6"/></svg>
                Xoá
            </button>
        </div>

        {{-- Bảng --}}
        <div class="nsu-table-wrap">
            <table class="nsu-table">
                {{-- CHÍN cột, không phải mười hai.
                     Ngày sinh, giới tính, CCCD và ngày vào làm đã bỏ khỏi bảng: không
                     ai quét danh sách nhân sự để đọc số căn cước, mà bốn cột ấy đủ ép
                     bảng rộng quá màn hình rồi bắt cuộn ngang mới thấy nút Sửa. Chúng
                     nằm đầy đủ trong hộp thoại sửa, cách một cú bấm.

                     Số còn lại là những gì người ta thật sự tra khi mở trang: dòng thứ
                     mấy, mã nào, ai, mở được cửa nào, trực ca nào, gọi số nào, đứng
                     đâu, còn làm không. --}}
                <thead>
                    <tr>
                        <th class="tc" style="width:38px">
                            {{-- Chọn hết những dòng ĐANG HIỆN, không phải cả cửa hàng:
                                 bảng đang lọc thì "hết" nghĩa là hết phần đang xem. --}}
                            <input type="checkbox" id="nsuChonHet" title="Chọn hết dòng đang hiện">
                        </th>
                        <th class="tc" style="width:48px">STT</th>
                        <th style="width:100px">Mã NV</th>
                        <th style="min-width:180px">Họ tên</th>
                        <th style="width:170px">Phân quyền</th>
                        <th style="width:150px">Ca làm việc</th>
                        <th style="width:130px">Điện thoại</th>
                        <th style="width:160px">Chi nhánh</th>
                        <th class="tc" style="width:110px">Trạng thái</th>
                        <th class="tc" style="width:90px">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($list as $i => $ns)
                        @php
                            $tt = $ns['status'] ?? $C::DANG_LAM;
                            $tenDangNhap = (string) ($ns['username'] ?? '');
                            // Tài khoản khoá = người này còn hồ sơ nhưng không đăng nhập
                            // được nữa. Trạng thái của TÀI KHOẢN, khác cột trạng thái làm
                            // việc bên phải — hai thứ tách nhau nên phải hiện cả hai.
                            $tkKhoa = $tenDangNhap !== '' && ($ns['user_status'] ?? 'active') !== 'active';
                        @endphp
                        <tr>
                            <td class="tc">
                                <input type="checkbox" form="nsuBulkForm" name="ids[]"
                                       value="{{ $ns['id'] }}" data-nsu-chon>
                            </td>
                            <td class="tc nsu-muted">{{ $i + 1 }}</td>
                            {{-- Mã nhân viên ĐỨNG CỘT RIÊNG: nó là thứ để đối chiếu với
                                 bảng lương và bảng chấm công, nên phải xếp thẳng hàng và
                                 sắp/dò được. Nhét xuống dòng phụ dưới tên thì mắt phải
                                 nhảy zíc-zắc khi dò một danh sách mã. --}}
                            <td><code class="nsu-code">{{ $ns['code'] ?: '—' }}</code></td>
                            <td>
                                {{-- Không có ảnh ở đây: bảng để QUÉT MẮT tìm người, mà một
                                     cột ảnh tròn đẩy mỗi hàng cao thêm và làm chậm đúng
                                     việc ấy. Ảnh xem trong hộp chi tiết. --}}
                                <div class="nsu-name">{{ $ns['full_name'] }}</div>
                            </td>
                            <td>
                                {{-- Không có tài khoản thì nói thẳng bằng huy hiệu xám;
                                     để trống trông như dữ liệu bị thiếu. --}}
                                @if($tenDangNhap !== '')
                                    {{-- MỘT huy hiệu cho MỖI cửa đã tick. API trả về đúng
                                         `users.access_areas` nên bảng chép lại y nguyên: tick
                                         hai ô ra hai huy hiệu, tick một ô ra một. --}}
                                    @forelse((array) ($ns['quyen'] ?? []) as $cua)
                                        <span class="nsu-badge is-cua-{{ $cua }}">{{ $C::NHAN_CUA[$cua] ?? $cua }}</span>
                                    @empty
                                        <span class="nsu-badge">{{ ($ns['role_display_name'] ?? '') ?: 'Có tài khoản' }}</span>
                                    @endforelse
                                    <div class="nsu-muted nsu-small">
                                        {{ $tenDangNhap }}@if($tkKhoa) · <span class="nsu-locked">đã khoá</span>@endif
                                    </div>
                                @else
                                    <span class="nsu-badge is-none">Không đăng nhập</span>
                                @endif
                            </td>
                            <td>
                                {{-- Cột SET đọc lên là "sang,chieu" — tách ra, đổi sang
                                     nhãn tiếng Việt, mỗi ca một thẻ nhỏ. --}}
                                @php
                                    $caTruc = collect(explode(',', (string) ($ns['work_shift'] ?? '')))
                                        ->filter()->values();
                                @endphp
                                @forelse($caTruc as $ca)
                                    <span class="nsu-ca">{{ $C::CA_LAM[$ca] ?? $ca }}</span>
                                @empty
                                    <span class="nsu-muted">Chưa xếp ca</span>
                                @endforelse
                            </td>
                            <td>{{ ($ns['phone'] ?? '') ?: '—' }}</td>
                            <td class="nsu-muted">{{ ($ns['shop_name'] ?? '') ?: '—' }}</td>
                            <td class="tc">
                                {{-- Công tắc: mỗi dòng một form riêng, gửi đúng một trường
                                     sang /trang-thai. JS hỏng thì vẫn còn nút submit.

                                     Ba data-* dưới đây là đủ dữ liệu để JS hỏi đúng câu
                                     trước khi gửi: gạt sang "đã nghỉ" là khoá tài khoản,
                                     gạt về "đang làm" thì hỏi có mở lại tài khoản không. --}}
                                <form method="POST" action="{{ route('admin.nhan-su.updateStatus', $ns['id']) }}"
                                      class="nsu-switch-form" data-nsu-status
                                      data-ten="{{ $ns['full_name'] }}"
                                      data-tai-khoan="{{ $tenDangNhap }}"
                                      data-tai-khoan-khoa="{{ $tkKhoa ? 1 : 0 }}">
                                    @csrf
                                    @method('PUT')
                                    <input type="hidden" name="status"
                                           value="{{ $tt === $C::DANG_LAM ? $C::DA_NGHI : $C::DANG_LAM }}">
                                    {{-- Mặc định KHÔNG mở tài khoản: chỉ lượt hỏi bên dưới
                                         mới bật lên 1. Không có JS thì mở tài khoản là việc
                                         phải làm tay trong hộp thoại sửa. --}}
                                    <input type="hidden" name="mo_tai_khoan" value="0" data-nsu-mo-tk>
                                    <input type="checkbox" class="nsu-switch"
                                           @checked($tt === $C::DANG_LAM)
                                           title="{{ $C::TRANG_THAI[$tt] ?? $tt }} — bấm để {{ $tt === $C::DANG_LAM ? 'đánh dấu nghỉ việc' : 'đặt lại đang làm' }}">
                                    <noscript><button type="submit" class="nsu-btn-ghost">Đổi</button></noscript>
                                </form>
                            </td>
                            <td class="nsu-actions">
                                {{-- Xem chi tiết. Đứng TRƯỚC nút Sửa vì nó là việc
                                     không đổi gì cả — tra một số điện thoại hay xem
                                     mức lương thì không có lý do gì phải mở ra một
                                     cái form đầy ô sửa được.

                                     Dựng từ chính dòng dữ liệu đã có trong trang, không
                                     gọi thêm API: danh sách đã mang đủ mọi ô. --}}
                                <button type="button" class="nsu-btn-icon" title="Xem chi tiết"
                                        data-nsu-xem data-ho-so="{{ json_encode($ns, JSON_UNESCAPED_UNICODE) }}">
                                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                                </button>

                                <button type="button" class="nsu-btn-icon" title="Sửa"
                                        data-nsu-edit data-ho-so="{{ json_encode($ns, JSON_UNESCAPED_UNICODE) }}">
                                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                                </button>

                                {{-- Form riêng từng dòng: nút dùng chung + id nhét bằng JS
                                     thì lúc JS hỏng sẽ xoá nhầm dòng cuối cùng đã gán. --}}
                                <form method="POST" action="{{ route('admin.nhan-su.destroy', $ns['id']) }}"
                                      class="nsu-inline-form" data-nsu-xoa
                                      data-ten="{{ $ns['full_name'] }}">
                                    @csrf
                                    @method('DELETE')
                                    {{-- Xoá hồ sơ thì tệp ảnh cũng thành mồ côi: không hồ
                                         sơ nào trỏ tới, không màn hình nào hiện ra. Gửi kèm
                                         đường dẫn để controller dọn luôn. --}}
                                    <input type="hidden" name="avatar" value="{{ $ns['avatar'] ?? '' }}">
                                    <button type="submit" class="nsu-btn-icon is-danger" title="Xoá">
                                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M6 6v14a1 1 0 0 0 1 1h10a1 1 0 0 0 1-1V6"/><path d="M10 11v6M14 11v6"/></svg>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10" class="nsu-empty">{{ $EMPTY_TEXT }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Modal thêm / sửa --}}
    <div class="nsu-overlay" id="nsuOverlay" style="display:none;">
        <div class="nsu-dialog">
            <div class="nsu-modal-head">
                <h4 class="nsu-modal-title" id="nsuModalTitle">Thêm nhân viên</h4>
                <button type="button" class="nsu-modal-x" data-close>
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </div>

            <form id="nsuForm" method="POST" action="{{ route('admin.nhan-su.store') }}">
                @csrf
                {{-- _method rỗng khi TẠO, "PUT" khi sửa — Laravel bỏ qua giá trị rỗng. --}}
                <input type="hidden" name="_method" id="nsuMethod" value="">

                {{-- Ba tab như modal của order v2. Ba nhóm ô này được điền ở ba thời
                     điểm khác nhau: hồ sơ lúc tuyển, tài khoản lúc giao việc, lương
                     lúc chốt mức. --}}
                <ul class="nav nsu-tabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" type="button" role="tab"
                                data-bs-toggle="tab" data-bs-target="#nsuTabChiTiet">Chi tiết</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" type="button" role="tab"
                                data-bs-toggle="tab" data-bs-target="#nsuTabDangNhap">Đăng nhập</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" type="button" role="tab"
                                data-bs-toggle="tab" data-bs-target="#nsuTabLuong">Lương</button>
                    </li>
                </ul>

                <div class="tab-content nsu-tab-content">
                    <div class="tab-pane fade show active" id="nsuTabChiTiet" role="tabpanel">
                        {{-- Bố cục CỘT DỌC chép từ modal nhân sự của order v2 (#CreateEmployee),
                             GIỮ NGUYÊN thứ tự trường của bên đó.

                             Bên đó bốn cột: [ảnh] [hồ sơ] [nơi làm] [phân quyền]. Cột ảnh
                             chưa dựng được vì bảng `employees` chưa có cột ảnh, nên ở đây
                             còn ba cột — ba cột còn lại giữ nguyên cả thứ tự lẫn nội dung. --}}
                        <div class="nsu-cols">
                            {{-- Cột 1 của bản cũ: ẢNH. Không phải để trang trí — cửa hàng
                                 đông người thì tên trùng nhau, còn lúc giao ca thì người ta
                                 đối chiếu bằng mặt chứ không bằng mã nhân viên.

                                 Ảnh tải lên NGAY khi chọn, trước lúc bấm Lưu: form chỉ mang
                                 theo đường dẫn trả về, nên lượt Lưu hỏng vì bất kỳ lý do gì
                                 cũng không bắt chọn lại ảnh. --}}
                            <div class="nsu-col">
                                <label class="nsu-field-label">Hình ảnh</label>
                                <div class="nsu-anh">
                                    <div class="nsu-anh-khung" id="nsuAnhKhung">
                                        <img id="nsuAnhXem" alt="" style="display:none;">
                                        <span class="nsu-anh-chu" id="nsuAnhChu">Chưa có ảnh</span>
                                    </div>
                                    <input type="hidden" name="avatar" id="nsuAnh" value="{{ old('avatar') }}">
                                    {{-- Ảnh CŨ của hồ sơ đang mở. Lượt lưu so hai ô này để
                                         biết tệp nào vừa thành mồ côi mà dọn đi. --}}
                                    <input type="hidden" name="avatar_cu" id="nsuAnhCu" value="{{ old('avatar_cu') }}">
                                    <label class="nsu-anh-nut">
                                        <span id="nsuAnhNutChu">Chọn ảnh</span>
                                        <input type="file" id="nsuAnhFile" accept="image/*" hidden>
                                    </label>
                                    <button type="button" class="nsu-anh-go" id="nsuAnhGo" style="display:none;">Gỡ ảnh</button>
                                </div>
                            </div>

                            {{-- Cột 2 của bản cũ, đúng thứ tự: mã, họ tên, ngày sinh, email,
                                 ngày vào làm, trạng thái. --}}
                            <div class="nsu-col">
                                <div>
                                    <label class="nsu-field-label" for="nsuMa">Mã nhân viên</label>
                                    <input type="text" id="nsuMa" name="code" class="nsu-input" maxlength="30"
                                           autocomplete="off" placeholder="Tự đặt NV0001, NV0002…" value="{{ old('code') }}">
                                </div>
                                <div>
                                    <label class="nsu-field-label" for="nsuHoTen">Họ tên <span class="nsu-req">*</span></label>
                                    <input type="text" id="nsuHoTen" name="full_name" class="nsu-input" maxlength="150" required
                                           autocomplete="off" placeholder="Ví dụ: Nguyễn Văn An" value="{{ old('full_name') }}">
                                </div>
                                <div>
                                    <label class="nsu-field-label" for="nsuNgaySinh">Ngày sinh</label>
                                    <input type="date" id="nsuNgaySinh" name="birth_date" class="nsu-input" value="{{ old('birth_date') }}">
                                </div>
                                <div>
                                    <label class="nsu-field-label" for="nsuEmail">Email</label>
                                    <input type="email" id="nsuEmail" name="email" class="nsu-input" maxlength="191"
                                           autocomplete="off" placeholder="Bắt buộc nếu cấp tài khoản" value="{{ old('email') }}">
                                </div>
                                <div>
                                    <label class="nsu-field-label" for="nsuNgayVao">Ngày vào làm</label>
                                    <input type="date" id="nsuNgayVao" name="hired_on" class="nsu-input" value="{{ old('hired_on') }}">
                                </div>

                                {{-- Công tắc bật/tắt giữa "đang làm" và "đã nghỉ"; giá trị gửi
                                     đi nằm ở input hidden ngay dưới. --}}
                                <div>
                                    <label class="nsu-field-label" for="nsuTrangThai">Trạng thái</label>
                                    <label class="nsu-switch-row">
                                        <input type="checkbox" id="nsuTrangThai" class="nsu-switch"
                                               @checked(old('status', $C::DANG_LAM) !== $C::DA_NGHI)>
                                        <span class="nsu-switch-label" id="nsuTrangThaiChu">Đang làm việc</span>
                                    </label>
                                    <input type="hidden" name="status" id="nsuTrangThaiValue"
                                           value="{{ old('status', $C::DANG_LAM) }}">
                                    <p class="nsu-hint">Tắt đi là đã nghỉ việc — tài khoản bị khoá ngay.</p>

                                    {{-- Chỉ hiện khi hồ sơ đang sửa có tài khoản ĐANG KHOÁ và
                                         công tắc vừa bật lại — ngoài trường hợp đó thì ô này
                                         không có nghĩa gì. --}}
                                    <div id="nsuMoTaiKhoanRow" class="nsu-mt" style="display:none;">
                                        <label class="nsu-switch-row">
                                            <input type="checkbox" id="nsuMoTaiKhoan" name="mo_tai_khoan" value="1"
                                                   class="nsu-switch" @checked(old('mo_tai_khoan'))>
                                            <span class="nsu-switch-label">Mở lại tài khoản đăng nhập</span>
                                        </label>
                                        <p class="nsu-hint">Tài khoản đang khoá vì trước đó đã đánh dấu nghỉ việc.</p>
                                    </div>
                                </div>
                            </div>

                            {{-- Cột 3 của bản cũ, đúng thứ tự: chi nhánh, giới tính, điện
                                 thoại, CCCD, ca làm việc. --}}
                            <div class="nsu-col">
                                <div>
                                    <label class="nsu-field-label" for="nsuChiNhanh">Chi nhánh <span class="nsu-req">*</span></label>
                                    {{-- Một chi nhánh thì chọn sẵn luôn, nhưng vẫn để ô chọn:
                                         hôm sau mở thêm điểm bán là nó có hai mục ngay. --}}
                                    <select id="nsuChiNhanh" name="shop_id" class="nsu-input" required data-ph>
                                        <option value="">Chọn chi nhánh</option>
                                        @foreach($chiNhanh as $cn)
                                            <option value="{{ $cn['id'] }}"
                                                    @selected((int) old('shop_id', count($chiNhanh) === 1 ? $chiNhanh[0]['id'] : 0) === (int) $cn['id'])>
                                                {{ $cn['name'] }}
                                            </option>
                                        @endforeach
                                    </select>
                                    @if($chiNhanh === [])
                                        <p class="nsu-hint">
                                            Chưa tra được chi nhánh nào.
                                            <a href="{{ route('admin.chi-nhanh.index') }}">Mở trang Chi nhánh</a>
                                        </p>
                                    @endif
                                </div>
                                <div>
                                    <label class="nsu-field-label" for="nsuGioiTinh">Giới tính</label>
                                    <select id="nsuGioiTinh" name="gender" class="nsu-input" data-ph>
                                        <option value="">Chọn giới tính</option>
                                        @foreach($C::GIOI_TINH as $ma => $ten)
                                            <option value="{{ $ma }}" @selected(old('gender') === $ma)>{{ $ten }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label class="nsu-field-label" for="nsuDienThoai">Điện thoại</label>
                                    <input type="text" id="nsuDienThoai" name="phone" class="nsu-input" maxlength="20"
                                           autocomplete="off" value="{{ old('phone') }}">
                                </div>
                                <div>
                                    <label class="nsu-field-label" for="nsuCanCuoc">CCCD</label>
                                    <input type="text" id="nsuCanCuoc" name="id_number" class="nsu-input" maxlength="20"
                                           autocomplete="off" value="{{ old('id_number') }}">
                                </div>
                                {{-- Ca làm việc — ô tick, chọn nhiều như select2 multiple của
                                     bản cũ: một người trực được cả sáng lẫn chiều. --}}
                                <div>
                                    <label class="nsu-field-label">Ca làm việc</label>
                                    <div class="nsu-nhom-quyen is-cot">
                                        @foreach($C::CA_LAM as $ma => $ten)
                                            <label class="nsu-nhom-quyen-item">
                                                <input type="checkbox" name="work_shift[]" value="{{ $ma }}" data-nsu-ca
                                                       @checked(in_array($ma, (array) old('work_shift', [])))>
                                                <span>{{ $ten }}</span>
                                            </label>
                                        @endforeach
                                    </div>
                                </div>
                            </div>

                            {{-- Cột 4 của bản cũ: ô tick phân quyền (`account_type[]` bên đó —
                                 Quản lý / Bếp / Thu ngân / Nhân viên order). Bán lẻ chỉ còn
                                 hai vai nên còn hai ô.

                                 Đây là CẤP QUYỀN ĐĂNG NHẬP: tick Quản lý thì người này mở
                                 được khu quản trị, tick Thu ngân thì vào quầy bán. Ô tick chứ
                                 không phải danh sách chọn một, đúng như bản cũ — một người
                                 giữ được cả hai vai. --}}
                            <div class="nsu-col">
                                <div>
                                    <label class="nsu-field-label">Phân quyền <span class="nsu-req">*</span></label>
                                    <div class="nsu-nhom-quyen is-cot">
                                        @foreach($quyen as $id => $ten)
                                            <label class="nsu-nhom-quyen-item">
                                                <input type="checkbox" name="quyen[]" value="{{ $id }}" data-nsu-quyen
                                                       @checked(in_array($id, (array) old('quyen', [])))>
                                                <span>{{ $ten }}</span>
                                            </label>
                                        @endforeach
                                    </div>
                                </div>
                            </div>
                        </div>

                        {{-- Hai ô dài không có trong modal cũ, để nằm ngang hết bề rộng chứ
                             không chen vào giữa một cột đang xếp đúng thứ tự bên đó. --}}
                        <div class="nsu-ghi-chu nsu-row">
                            <div>
                                <label class="nsu-field-label" for="nsuDiaChi">Địa chỉ</label>
                                <input type="text" id="nsuDiaChi" name="address" class="nsu-input" maxlength="255"
                                       autocomplete="off" value="{{ old('address') }}">
                            </div>
                            <div>
                                <label class="nsu-field-label" for="nsuGhiChu">Ghi chú</label>
                                <input type="text" id="nsuGhiChu" name="note" class="nsu-input" maxlength="500"
                                       autocomplete="off" value="{{ old('note') }}">
                            </div>
                        </div>
                    </div>

                    {{-- Tab 2: tài khoản đăng nhập — nằm trong hồ sơ chứ không phải một
                         trang riêng, vì chủ tiệm tuyển người trước rồi mới quyết định
                         người đó có cần vào phần mềm hay không. --}}
                    <div class="tab-pane fade" id="nsuTabDangNhap" role="tabpanel">
                        {{-- Hồ sơ đã có tài khoản thì chỗ này chỉ còn một dòng để đọc:
                             API từ chối cấp tài khoản thứ hai cho cùng một hồ sơ. --}}
                        <p class="nsu-taikhoan-da-co" id="nsuTaiKhoanDaCo" style="display:none;"></p>

                        <div id="nsuCoTaiKhoanRow">
                            <label class="nsu-switch-row">
                                <input type="checkbox" id="nsuCoTaiKhoan" name="co_tai_khoan" value="1"
                                       class="nsu-switch" @checked(old('co_tai_khoan'))>
                                <span class="nsu-switch-label">Cấp tài khoản đăng nhập</span>
                            </label>
                            <p class="nsu-hint" id="nsuCoTaiKhoanHint">Bỏ trống nếu người này không dùng phần mềm.</p>
                        </div>

                        {{-- Nhóm quyền — CHỌN NHIỀU.

                             Một người mang được nhiều nhóm cùng lúc và quyền của họ là
                             HỢP của chúng: quản lý ca tối vẫn đứng quầy, kế toán vẫn phải
                             vào được kho. Với ô chọn một, cách duy nhất để nói "vừa quản
                             lý vừa thu ngân" là đẻ ra một nhóm thứ ba mang đúng cái tên
                             ấy — rồi nhóm thứ tư, thứ năm cho mọi tổ hợp.

                             Chỉ có nghĩa khi hồ sơ CÓ tài khoản: nhóm quyền thuộc về tài
                             khoản, còn hồ sơ là con người. --}}
                        {{-- Ẩn khi chưa bật. --}}
                        <div id="nsuTaiKhoanBox" class="nsu-grid nsu-mt" style="display:none;">
                            <div>
                                <label class="nsu-field-label" for="nsuUsername">Tên đăng nhập</label>
                                <input type="text" id="nsuUsername" name="username" class="nsu-input" maxlength="50"
                                       autocomplete="off" placeholder="vd: an.nv" value="{{ old('username') }}">
                            </div>
                            <div>
                                <label class="nsu-field-label" for="nsuMatKhau">Mật khẩu</label>
                                <input type="text" id="nsuMatKhau" name="password" class="nsu-input" maxlength="72"
                                       autocomplete="off" placeholder="Bỏ trống để dùng mật khẩu mặc định" value="{{ old('password') }}">
                            </div>
                        </div>

                    </div>

                    {{-- Tab 3: lương (bên order v2 chỗ này là tab "Cài đặt"). --}}
                    <div class="tab-pane fade" id="nsuTabLuong" role="tabpanel">
                        <div class="nsu-grid">
                            <div>
                                <label class="nsu-field-label" for="nsuHinhThuc">Hình thức trả lương</label>
                                <select id="nsuHinhThuc" name="salary_type" class="nsu-input" data-ph>
                                    <option value="">Chọn hình thức</option>
                                    @foreach($C::HINH_THUC_LUONG as $ma => $ten)
                                        <option value="{{ $ma }}" @selected(old('salary_type') === $ma)>{{ $ten }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="nsu-field-label" for="nsuMucLuong">Mức lương (₫)</label>
                                <input type="number" id="nsuMucLuong" name="salary" class="nsu-input"
                                       min="0" step="1000" value="{{ old('salary') }}">
                            </div>
                            <div>
                                <label class="nsu-field-label" for="nsuPhuCap">Phụ cấp (₫)</label>
                                <input type="number" id="nsuPhuCap" name="allowance" class="nsu-input"
                                       min="0" step="1000" value="{{ old('allowance') }}">
                            </div>
                            <div>
                                <label class="nsu-field-label" for="nsuHoaHong">Hoa hồng (% doanh số)</label>
                                <input type="number" id="nsuHoaHong" name="commission_rate" class="nsu-input"
                                       min="0" max="100" step="0.1" value="{{ old('commission_rate') }}">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="nsu-modal-foot">
                    <button type="button" class="nsu-btn-ghost" data-close>Đóng</button>
                    <button type="submit" class="nsu-btn-primary" id="nsuSubmit">Lưu nhân viên</button>
                </div>
            </form>
        </div>
    </div>

    {{-- Hộp xác nhận dùng chung cho cả trang: gạt công tắc trạng thái và xoá hồ sơ.

         Không dùng confirm() của trình duyệt, vì ba lượt hỏi ở đây đều phải nói ra
         HẬU QUẢ chứ không chỉ hỏi "chắc chưa": tài khoản nào sắp bị khoá, ai đăng
         nhập được bằng mật khẩu cũ. Hộp xám của trình duyệt dán tên miền lên đầu,
         không tách nổi hai đoạn chữ, và hai nút của nó trông y hệt nhau — đúng lúc
         cần nhìn ra nút nào là nút nguy hiểm. --}}
    <div class="nsu-overlay" id="nsuConfirm" style="display:none;">
        <div class="nsu-dialog is-confirm">
            <div class="nsu-modal-head">
                <h4 class="nsu-modal-title" id="nsuConfirmTitle"></h4>
                <button type="button" class="nsu-modal-x" data-nsu-confirm-huy>
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </div>
            {{-- Nội dung do JS dựng bằng textContent từng đoạn: tên nhân viên và tên
                 đăng nhập là dữ liệu người dùng gõ vào, không nối thẳng vào HTML. --}}
            <div class="nsu-confirm-body" id="nsuConfirmBody"></div>
            <div class="nsu-modal-foot">
                <button type="button" class="nsu-btn-ghost" id="nsuConfirmHuy" data-nsu-confirm-huy>Huỷ</button>
                <button type="button" class="nsu-btn-primary" id="nsuConfirmOk">Đồng ý</button>
            </div>
        </div>
    </div>

    <style>
        .nsu {
            /* Phá padding p-4 của <main> để tràn viền như các trang danh sách khác */
            margin: -1.5rem;
            min-height: calc(100vh - 56px);
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            color: #262626; background: #fff;
        }

        /* Header — cùng khuôn trang Nhà cung cấp / Đơn hàng. */
        .nsu-head { display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; padding: 14px 20px; }
        .nsu-title { margin: 0; font-size: 16px; font-weight: 700; color: #262626; line-height: 34px; }
        .nsu-sum { font-size: 13px; color: #595959; }
        .nsu-sum b { color: #262626; }

        .nsu-callout {
            display: flex; align-items: flex-start; gap: 8px;
            margin: 0 20px 12px; padding: 10px 12px; border-radius: 4px;
            background: #fff7e6; border: 1px solid #ffd591; color: #874d00; font-size: 13px;
        }
        .nsu-callout.is-error { background: #fff1f0; border-color: #ffa39e; color: #a8071a; }

        /* Bộ lọc */
        .nsu-filter { padding: 0 20px 12px; margin-bottom: 12px; border-bottom: 1px solid #eee; }
        .nsu-toolbar { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; }
        .nsu-toolbar-actions { margin-left: auto; display: flex; align-items: center; gap: 8px; }

        .nsu-searchbox { display: flex; border-radius: 4px; }
        .nsu-searchbox:focus-within { box-shadow: 0 0 0 4px rgba(13,110,253,.25); }
        .nsu-search-input {
            height: 34px; width: 280px; border: 1px solid #d9d9d9; border-right: 0;
            border-radius: 4px 0 0 4px; background: #fff; padding: 0 12px; font-size: 13px; outline: none;
        }
        .nsu-search-input::placeholder { color: #bfbfbf; }
        /* Vòng focus vẽ ở .nsu-searchbox, ô trong không vẽ lại (luật chung ở layout). */
        .nsu-searchbox:focus-within .nsu-search-input,
        .nsu-searchbox:focus-within .nsu-search-btn { border-color: #86b7fe; }
        .nsu-search-input:focus { box-shadow: none !important; }
        .nsu-search-btn {
            height: 34px; width: 40px; display: inline-flex; align-items: center; justify-content: center;
            border: 1px solid #d9d9d9; border-radius: 0 4px 4px 0; background: #fafafa; color: #595959; cursor: pointer;
        }
        .nsu-search-btn:hover { color: #1890ff; }

        .nsu-select {
            height: 34px; border: 1px solid #d9d9d9; border-radius: 4px; background-color: #fff;
            padding: 0 30px 0 12px; font-size: 13px; color: #262626; cursor: pointer; outline: none; max-width: 220px;
            appearance: none; -webkit-appearance: none;
            background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23595959' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><path d='m6 9 6 6 6-6'/></svg>");
            background-repeat: no-repeat; background-position: right 8px center;
        }

        .nsu-clear {
            display: inline-flex; align-items: center; height: 34px; padding: 0 10px; border-radius: 4px;
            font-size: 13px; color: #8c8c8c; text-decoration: none; transition: background .15s, color .15s;
        }
        .nsu-clear:hover { background: #fff1f0; color: #ff4d4f; }

        /* Hàng bộ lọc nâng cao (ẩn tới khi bấm "Nâng cao") */
        .nsu-toolbar-adv { display: none; flex-wrap: wrap; align-items: center; gap: 12px; margin-top: 12px; }
        .nsu-toolbar-adv.is-open { display: flex; }
        .nsu-adv-btn {
            height: 34px; display: inline-flex; align-items: center; gap: 6px;
            border: 1px solid #d9d9d9; border-radius: 4px; background: #fff; padding: 0 12px;
            font-size: 13px; color: #595959; cursor: pointer; transition: border-color .15s, color .15s;
        }
        .nsu-adv-btn:hover, .nsu-adv-btn.is-open { border-color: #1890ff; color: #1890ff; }
        .nsu-adv-caret { transition: transform .2s; }
        .nsu-adv-btn.is-open .nsu-adv-caret { transform: rotate(180deg); }
        .nsu-adv-count {
            display: inline-flex; align-items: center; justify-content: center;
            min-width: 18px; height: 18px; padding: 0 5px; border-radius: 9999px;
            background: #1890ff; color: #fff; font-size: 11px; font-weight: 600;
        }

        /* Thanh hành động hàng loạt — NỔI ở đáy màn hình, bo tròn, cùng khuôn với
           trang Sản phẩm và Danh mục (xem .prd-bulk).
           
           Nổi chứ không nằm trên đầu bảng: người ta tick vài dòng, cuộn xuống tick
           tiếp, rồi mới bấm — thanh nằm trên đầu thì lúc ấy nó đã trôi khỏi màn
           hình, và phải cuộn ngược lên mới bấm được.

           left: 230px bù đúng bề ngang thanh trái để thanh nổi căn giữa VÙNG NỘI
           DUNG chứ không phải giữa cửa sổ; thu gọn thanh trái thì bù lại 48px. */
        .nsu-bulkbar {
            position: fixed; bottom: 24px; left: 230px; right: 0; margin-inline: auto; z-index: 1070;
            width: max-content; max-width: calc(100vw - 260px);
            display: flex; align-items: center; gap: 16px;
            border: 1px solid #e6e6e6; border-radius: 9999px;
            background: #fff; padding: 12px 20px; box-shadow: 0 6px 24px rgba(0,0,0,.15);
        }
        body:has(.jh-sidebar.collapsed) .nsu-bulkbar { left: 48px; }
        @media (max-width: 820px) { .nsu-bulkbar { left: 0; } }
        .nsu-bulkbar__so { font-size: 13px; font-weight: 500; color: #262626; }
        .nsu-bulkbar__bo {
            border: 0; background: none; padding: 0;
            font-size: 13px; color: #8c8c8c; cursor: pointer; transition: color .15s;
        }
        .nsu-bulkbar__bo:hover { color: #262626; }
        /* Nút trong thanh nổi bo tròn theo thanh; nút Xoá tô đỏ đặc vì nó là thứ
           không lùi lại được, và ở đây nó đứng cạnh một nút vô hại. */
        .nsu-bulkbar .nsu-btn-ghost { border-radius: 9999px; height: 32px; }
        .nsu-btn-ghost.is-danger {
            border-color: transparent; background: #ff4d4f; color: #fff;
        }
        .nsu-btn-ghost.is-danger:hover { background: #ff7875; color: #fff; border-color: transparent; }

        .nsu-table-wrap { padding: 0 20px 24px; overflow-x: auto; }
        /* Chín cột vẫn vừa một màn hình laptop nên KHÔNG ép min-width rộng như bảng
           cũ (1120px, luôn phải cuộn ngang mới thấy nút Sửa). Vẫn giữ overflow-x của
           khung ngoài cho màn hình thật hẹp. */
        .nsu-table { width: 100%; min-width: 900px; border-collapse: collapse; font-size: 13.5px; }
        .nsu-table th {
            text-align: left; font-weight: 600; color: #595959; background: #fafafa;
            padding: 11px 12px; border-bottom: 1px solid #f0f0f0; white-space: nowrap;
        }
        .nsu-table td { padding: 14px 12px; border-bottom: 1px solid #f5f5f5; vertical-align: middle; }
        .nsu-table tbody tr:hover td { background: #fafafa; }
        .nsu-name { font-weight: 600; }
        .nsu-small { font-size: 12px; }
        .nsu-muted { color: #8c8c8c; }
        .nsu-code { font-size: 12px; background: #f5f5f5; border-radius: 3px; padding: 2px 6px; color: #595959; }
        .nsu-empty { text-align: center; color: #8c8c8c; padding: 32px 12px; }

        /* Căn cột theo bảng của order v2. */
        .nsu-table .tc { text-align: center; }
        .nsu-table .tr { text-align: right; }

        /* Huy hiệu phân quyền — bảng màu của badge account_type bên order v2. */
        /* Một người giữ hai vai thì hai huy hiệu nằm cạnh nhau — cần margin, không
           thì chúng dính vào nhau khi cột hẹp. */
        .nsu-badge {
            display: inline-block; margin: 1px 4px 1px 0; padding: 2px 8px; border-radius: 4px;
            font-size: 12px; font-weight: 600; white-space: nowrap;
            background: #f1f3f5; color: #495057;
        }
        .nsu-badge.is-cua-quan_ly { background: #d1e7dd; color: #0f5132; }
        .nsu-badge.is-cua-thu_ngan { background: #fff3cd; color: #664d03; }
        .nsu-badge.is-none { background: #f1f3f5; color: #868e96; font-weight: 500; }

        /* "đã khoá" đứng ngay cạnh tên đăng nhập: huy hiệu quyền vẫn xanh/vàng như
           cũ, nhưng tài khoản đó không vào được nữa — không nói ra thì cả dòng
           trông y hệt một người đang đi làm bình thường. */
        .nsu-locked { color: #cf1322; font-weight: 600; }

        .nsu-tag { display: inline-block; padding: 1px 8px; border-radius: 10px; font-size: 12px; white-space: nowrap; }
        .nsu-tag.is-dang_lam { background: #f6ffed; color: #389e0d; border: 1px solid #b7eb8f; }
        .nsu-tag.is-tam_nghi { background: #fff7e6; color: #d46b08; border: 1px solid #ffd591; }
        .nsu-tag.is-da_nghi { background: #fafafa; color: #8c8c8c; border: 1px solid #e8e8e8; }

        .nsu-actions { display: flex; align-items: center; gap: 4px; }
        .nsu-inline-form { display: inline; margin: 0; }
        .nsu-btn-icon {
            display: inline-flex; align-items: center; justify-content: center;
            width: 30px; height: 30px; border-radius: 4px;
            border: 1px solid #d9d9d9; background: #fff; color: #595959; cursor: pointer;
        }
        .nsu-btn-icon:hover { border-color: #0d6efd; color: #0d6efd; }
        .nsu-btn-icon.is-danger:hover { border-color: #ff4d4f; color: #ff4d4f; }

        .nsu-btn-primary {
            display: inline-flex; align-items: center; gap: 6px;
            height: 34px; padding: 0 14px; border-radius: 4px; border: 0;
            background: #0d6efd; color: #fff; font-size: 13px; font-weight: 600; cursor: pointer;
        }
        .nsu-btn-primary:hover { background: #0b5ed7; }
        .nsu-btn-ghost {
            display: inline-flex; align-items: center; gap: 6px;
            height: 34px; padding: 0 14px; border-radius: 4px; text-decoration: none;
            border: 1px solid #d9d9d9; background: #fff; color: #595959; font-size: 13px; cursor: pointer;
            white-space: nowrap;
        }
        .nsu-btn-ghost:hover { color: #0d6efd; border-color: #0d6efd; }

        /* Modal */
        .nsu-overlay { position: fixed; inset: 0; background: rgba(0,0,0,.45); z-index: 1050; display: flex; align-items: center; justify-content: center; padding: 16px; }
        /* Rộng 1080px — modal thêm nhân viên bên order v2 rộng 60% màn hình, và
           tab Chi tiết ở đây cũng xếp bốn cột dọc như bên đó nên cần chỗ. Cột ảnh
           để cố định 150px: nó không co giãn theo nội dung như ba cột kia. */
        .nsu-dialog { width: 100%; max-width: 1080px; max-height: calc(100vh - 32px); display: flex; flex-direction: column; background: #fff; border-radius: 6px; box-shadow: 0 6px 24px rgba(0,0,0,.2); }
        .nsu-dialog form { display: flex; flex-direction: column; min-height: 0; }
        .nsu-modal-head { display: flex; align-items: center; justify-content: space-between; padding: 14px 20px; border-bottom: 1px solid #f0f0f0; }
        .nsu-modal-title { margin: 0; font-size: 15px; font-weight: 700; }
        .nsu-modal-x { border: 0; background: none; color: #8c8c8c; cursor: pointer; line-height: 0; }
        /* Form dài hơn màn hình laptop nên phần thân tự cuộn, còn hàng nút luôn
           nhìn thấy — không thì phải cuộn cả trang mới bấm được Lưu. */
        .nsu-modal-body { padding: 16px; display: flex; flex-direction: column; gap: 14px; overflow-y: auto; }

        /* Tab gạch chân, ép lại cỡ chữ cho khớp các ô nhập. */
        .nsu-tabs {
            display: flex; gap: 4px; list-style: none;
            padding: 0 20px; margin: 0; border-bottom: 1px solid #f0f0f0;
        }
        .nsu-tabs .nav-link {
            padding: 10px 4px; margin: 0 10px -1px 0;
            border: 0; border-bottom: 2px solid transparent; background: none;
            font-size: 13px; font-weight: 600; color: #8c8c8c; cursor: pointer;
        }
        .nsu-tabs .nav-link:hover { color: #262626; }
        .nsu-tabs .nav-link.active { color: #0d6efd; border-bottom-color: #0d6efd; }
        /* Thân tab tự cuộn để hàng nút luôn nhìn thấy. */
        .nsu-tab-content { padding: 18px 20px; overflow-y: auto; }
        /* Lưới 3 cột của tab Đăng nhập và tab Lương: mỗi ô một khối, đọc ngang. */
        .nsu-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px 16px; }
        .nsu-mt { margin-top: 14px; }
        /* Ba CỘT DỌC của tab Chi tiết — khác .nsu-grid ở chiều đọc: lưới xếp ô
           theo hàng (trái sang phải rồi xuống dòng), còn đây mỗi cột là một
           chồng ô riêng đọc từ trên xuống. Vạch ngăn giữa các cột để mắt không
           trượt từ nhóm này sang nhóm kia giữa chừng. */
        .nsu-cols { display: grid; grid-template-columns: 150px repeat(3, 1fr); }
        .nsu-col { display: flex; flex-direction: column; gap: 14px; padding: 0 18px; min-width: 0; }
        .nsu-col:first-child { padding-left: 0; }
        .nsu-col:last-child { padding-right: 0; }
        .nsu-col + .nsu-col { border-left: 1px solid #f5f5f5; }
        /* Tiêu đề cột đã có gap 14px của .nsu-col lo khoảng cách. */
        .nsu-col .nsu-group { margin-bottom: 0; }
        .nsu-ghi-chu { margin-top: 18px; padding-top: 16px; border-top: 1px solid #f5f5f5; }

        /* Ô ảnh nhân viên — khung vuông, ảnh phủ kín chứ không méo. */
        .nsu-anh { display: flex; flex-direction: column; align-items: center; gap: 8px; }
        .nsu-anh-khung {
            width: 118px; height: 118px; border-radius: 6px; overflow: hidden;
            border: 1px dashed #d9d9d9; background: #fafafa;
            display: flex; align-items: center; justify-content: center;
        }
        .nsu-anh-khung img { width: 100%; height: 100%; object-fit: cover; }
        .nsu-anh-chu { font-size: 12px; color: #bfbfbf; }
        .nsu-anh-nut {
            display: inline-block; padding: 5px 12px; border-radius: 4px;
            border: 1px solid #d9d9d9; background: #fff;
            font-size: 12.5px; color: #434343; cursor: pointer;
        }
        .nsu-anh-nut:hover { color: #0d6efd; border-color: #0d6efd; }
        .nsu-anh-go {
            border: 0; background: none; padding: 0;
            font-size: 12px; color: #ff4d4f; cursor: pointer;
        }

        /* Danh sách nhóm quyền: mỗi nhóm một ô tick, xuống dòng theo bề ngang. */
        .nsu-nhom-quyen { display: flex; flex-wrap: wrap; gap: 8px 18px; margin-top: 4px; }
        /* Trong cột hẹp thì xếp dọc — hàng ngang tự ngắt dòng ở chỗ khó đoán,
           đọc thành hai cụm lệch nhau. Cột ô tick bên order v2 cũng xếp dọc. */
        .nsu-nhom-quyen.is-cot { flex-direction: column; gap: 10px; }
        .nsu-nhom-quyen-item {
            display: inline-flex; align-items: center; gap: 7px;
            font-size: 13px; color: #262626; cursor: pointer;
        }
        .nsu-nhom-quyen-note { color: #8c8c8c; font-style: normal; font-size: 12px; }
        /* Ô đang bị luật khoá lại (Cả ngày vs Sáng/Chiều) — mờ đi và con trỏ nói
           rõ là bấm không được, chứ không phải ô hỏng. */
        .nsu-nhom-quyen-item.is-khoa { color: #bfbfbf; cursor: not-allowed; }
        .nsu-nhom-quyen-item.is-khoa input { cursor: not-allowed; }

        /* Thẻ ca trực trong bảng — nhiều ca thì nhiều thẻ, đọc ra ngay là mấy buổi.
           Xám trung tính: đây là thông tin xếp lịch, không phải cảnh báo. */
        .nsu-ca {
            display: inline-block; margin: 1px 4px 1px 0; padding: 2px 8px;
            border: 1px solid #e8e8e8; border-radius: 10px;
            background: #fafafa; color: #595959; font-size: 12px; white-space: nowrap;
        }

        /* Công tắc — dáng của input.switch_customer bên order v2, đổi màu bật. */
        .nsu-switch {
            appearance: none; -webkit-appearance: none;
            width: 2.6em; height: 1.4em; margin: 0;
            background: #dcdcdc; border: 0; border-radius: 3em;
            position: relative; cursor: pointer; outline: none;
            transition: background .2s ease-in-out; flex-shrink: 0;
        }
        .nsu-switch:checked { background: #0d6efd; }
        .nsu-switch::after {
            content: ''; position: absolute; left: 0; top: 0;
            width: 1.4em; height: 1.4em; border-radius: 50%;
            background: #fff; box-shadow: 0 0 .25em rgba(0,0,0,.3);
            transform: scale(.72); transition: left .2s ease-in-out;
        }
        .nsu-switch:checked::after { left: calc(100% - 1.4em); }
        .nsu-switch:disabled { opacity: .5; cursor: not-allowed; }
        .nsu-switch-row { display: inline-flex; align-items: center; gap: 10px; cursor: pointer; }
        .nsu-switch-label { font-size: 13px; color: #262626; }
        /* Công tắc trong bảng nằm trong form riêng nên phải bỏ margin. */
        .nsu-switch-form { display: inline-flex; margin: 0; }
        .nsu-modal-foot { display: flex; justify-content: flex-end; gap: 8px; padding: 12px 20px; border-top: 1px solid #f0f0f0; background: #fafafa; border-radius: 0 0 6px 6px; }

        /* Hộp xác nhận: cùng khung với hộp thêm/sửa, chỉ hẹp lại vì nó chỉ chứa
           mấy dòng chữ. Nút Đồng ý tô ĐỎ khi lượt bấm khoá tài khoản hay xoá hồ
           sơ — hai nút xanh giống hệt nhau thì người đọc bấm theo vị trí, không
           theo chữ. */
        .nsu-dialog.is-confirm { max-width: 460px; }

        /* CHẾ ĐỘ CHỈ ĐỌC — vẫn là hộp thoại thêm/sửa, chỉ khoá lại.
           Ô khoá giữ nguyên vị trí và nhãn, chỉ đổi nền và bỏ viền nhấn: người đọc
           thấy đúng bố cục quen thuộc, và thấy ngay là không gõ được. */
        .nsu-form-chi-doc .nsu-input:disabled {
            background: #fafafa; color: #262626; border-color: #f0f0f0; cursor: default;
        }
        .nsu-form-chi-doc .nsu-switch:disabled { opacity: 1; }
        /* Trình duyệt tự làm mờ ô tick disabled. Ở chế độ đọc thì mờ đi là MẤT
           THÔNG TIN — người xem không còn đọc ra được "người này trực ca nào". */
        .nsu-form-chi-doc input[type="checkbox"]:disabled { opacity: 1; }
        /* Mấy nút chỉ có nghĩa khi đang sửa. */
        .nsu-form-chi-doc .nsu-anh-nut,
        .nsu-form-chi-doc .nsu-anh-go { display: none; }
        /* Ô tick khoá vẫn phải ĐỌC RÕ đang tick gì — mờ đi thì mất luôn thông tin. */
        .nsu-form-chi-doc .nsu-nhom-quyen-item { color: #262626; cursor: default; }
        .nsu-form-chi-doc .nsu-nhom-quyen-item.is-khoa { color: #bfbfbf; }

        .nsu-group {
            margin: 0 0 12px; padding-bottom: 6px; border-bottom: 1px solid #f5f5f5;
            font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: #8c8c8c;
        }
        .nsu-grid + .nsu-group { margin-top: 20px; }
        .nsu-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .nsu-field-label { display: block; font-size: 12.5px; font-weight: 600; color: #434343; margin-bottom: 5px; }
        .nsu-req { color: #ff4d4f; }
        .nsu-input {
            width: 100%; height: 36px; border: 1px solid #d9d9d9; border-radius: 4px;
            padding: 0 12px; font-size: 13px; outline: none; background-color: #fff; color: #262626;
        }
        .nsu-textarea { height: auto; padding: 8px 12px; resize: vertical; }
        .nsu-hint { margin: 4px 0 0; font-size: 12px; color: #8c8c8c; }
        .nsu-hint a { color: #0d6efd; }
        /* Dòng "đã có tài khoản" — chỉ để đọc. */
        .nsu-taikhoan-da-co {
            margin: 0; padding: 10px 12px; border-radius: 4px;
            background: #fafafa; border: 1px solid #f0f0f0; color: #595959; font-size: 13px;
        }

        /* Hẹp hơn thì xuống hai cột và bỏ vạch ngăn — vạch dọc chỉ đúng khi mọi
           cột nằm trên cùng một hàng; xuống hàng rồi thì nó chỉ vào chỗ trống. */
        @media (max-width: 1040px) {
            .nsu-cols { grid-template-columns: 1fr 1fr; gap: 18px 20px; }
            .nsu-col, .nsu-col:first-child, .nsu-col:last-child { padding: 0; }
            .nsu-col + .nsu-col { border-left: 0; }
            .nsu-grid { grid-template-columns: 1fr 1fr; }
        }
        @media (max-width: 720px) {
            .nsu-row, .nsu-grid, .nsu-cols { grid-template-columns: 1fr; }
        }
    </style>

    <script src="{{ asset('js/nhan-su-luat.js') }}"></script>

    <script>
        (function () {
            // ---------- Bộ lọc realtime: đổi select -> chạy ngay; gõ -> chờ 400ms ----------
            var filter = document.getElementById('nsuFilter');
            filter.querySelectorAll('select').forEach(function (sel) {
                sel.addEventListener('change', function () { filter.submit(); });
            });
            var search = filter.querySelector('input[name="keyword"]');
            var searchTimer = null;
            search.addEventListener('input', function () {
                clearTimeout(searchTimer);
                searchTimer = setTimeout(function () { filter.submit(); }, 400);
            });

            // Hàng lọc nâng cao — nhớ trạng thái mở bằng localStorage như trang Đơn hàng.
            (function () {
                var btn = document.getElementById('nsuAdvToggle');
                var row = document.getElementById('nsuAdvRow');
                var setOpen = function (open) {
                    row.classList.toggle('is-open', open);
                    btn.classList.toggle('is-open', open);
                    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
                };
                if (!row.classList.contains('is-open') && localStorage.getItem('nsu-adv-open') === '1') setOpen(true);
                btn.addEventListener('click', function () {
                    var open = !row.classList.contains('is-open');
                    setOpen(open);
                    localStorage.setItem('nsu-adv-open', open ? '1' : '0');
                });
            })();

            var overlay = document.getElementById('nsuOverlay');
            var form = document.getElementById('nsuForm');
            var method = document.getElementById('nsuMethod');
            var title = document.getElementById('nsuModalTitle');
            var submit = document.getElementById('nsuSubmit');

            var STORE = @json(route('admin.nhan-su.store'));
            // Khuôn đường sửa: thay 0 bằng id thật. Dựng bằng route() để đường dẫn
            // vẫn đúng nếu prefix của nhóm route đổi.
            var UPDATE = @json(route('admin.nhan-su.update', 0));

            // Tên ô trong form ↔ khoá trong hồ sơ. Một danh sách duy nhất cho cả lúc
            // dọn form lẫn lúc đổ dữ liệu vào sửa.
            var O = {
                code: 'nsuMa',
                full_name: 'nsuHoTen',
                gender: 'nsuGioiTinh',
                birth_date: 'nsuNgaySinh',
                id_number: 'nsuCanCuoc',
                phone: 'nsuDienThoai',
                email: 'nsuEmail',
                address: 'nsuDiaChi',
                shop_id: 'nsuChiNhanh',
                hired_on: 'nsuNgayVao',
                status: 'nsuTrangThaiValue',
                salary_type: 'nsuHinhThuc',
                salary: 'nsuMucLuong',
                allowance: 'nsuPhuCap',
                commission_rate: 'nsuHoaHong',
                username: 'nsuUsername',
                password: 'nsuMatKhau',
                note: 'nsuGhiChu',
            };

            var DANG_LAM = @json($C::DANG_LAM);
            var DA_NGHI = @json($C::DA_NGHI);
            // 0 = cửa hàng có nhiều chi nhánh, để người dùng tự chọn.
            var CHI_NHANH_MAC_DINH = @json(count($chiNhanh) === 1 ? (int) $chiNhanh[0]['id'] : 0);

            // ---------- Hộp xác nhận dùng chung ----------
            //
            // hoi() thay chỗ confirm() của trình duyệt: nhận nhiều đoạn chữ, đặt
            // được chữ trên từng nút, và tô đỏ nút Đồng ý khi lượt bấm là thứ khó
            // lấy lại. Bất đồng bộ nên nơi gọi phải đưa việc cần làm vào `xong`.
            var hopHoi = document.getElementById('nsuConfirm');
            var hopHoiTieuDe = document.getElementById('nsuConfirmTitle');
            var hopHoiNoiDung = document.getElementById('nsuConfirmBody');
            var hopHoiOk = document.getElementById('nsuConfirmOk');
            var hopHoiHuy = document.getElementById('nsuConfirmHuy');
            // Việc cần làm của lượt hỏi ĐANG mở. Xoá ngay khi đóng để một lượt hỏi
            // không trả lời hai lần.
            var traLoi = null;

            function dongHoi(dongY) {
                var xong = traLoi;
                traLoi = null;
                hopHoi.style.display = 'none';
                if (xong) xong(dongY);
            }

            function hoi(o, xong) {
                traLoi = xong;
                hopHoiTieuDe.textContent = o.tieuDe;

                // textContent từng đoạn: tên nhân viên và tên đăng nhập là chữ do
                // người dùng gõ, nối thẳng vào innerHTML là mở cửa cho thẻ script.
                hopHoiNoiDung.innerHTML = '';
                (o.doan || []).forEach(function (d) {
                    var p = document.createElement('p');
                    p.textContent = d;
                    hopHoiNoiDung.appendChild(p);
                });

                hopHoiOk.textContent = o.nutDongY || 'Đồng ý';
                hopHoiHuy.textContent = o.nutHuy || 'Huỷ';
                hopHoiOk.classList.toggle('is-danger', !!o.nguyHiem);
                hopHoi.style.display = 'flex';
                hopHoiOk.focus();
            }

            hopHoiOk.addEventListener('click', function () { dongHoi(true); });
            document.querySelectorAll('[data-nsu-confirm-huy]').forEach(function (el) {
                el.addEventListener('click', function () { dongHoi(false); });
            });
            // Bấm ra ngoài = Huỷ, y như hộp thêm/sửa.
            hopHoi.addEventListener('click', function (e) { if (e.target === hopHoi) dongHoi(false); });

            // Công tắc trên bảng: gạt là gửi form của chính dòng đó, rồi khoá lại để
            // hai cú gạt liên tiếp không thành hai lượt ghi ngược nhau.
            //
            // Trước khi gửi thì hỏi, vì lượt gạt này kéo theo TÀI KHOẢN ĐĂNG NHẬP —
            // thứ không hiện trên công tắc: sang "đã nghỉ" là khoá luôn, còn nhận
            // người cũ làm lại thì mở hay chưa mở là quyết định của chủ tiệm.
            document.querySelectorAll('[data-nsu-status]').forEach(function (f) {
                var oGat = f.querySelector('.nsu-switch');
                var oTrangThai = f.querySelector('input[name="status"]');
                var oMoTaiKhoan = f.querySelector('[data-nsu-mo-tk]');
                var ten = f.dataset.ten || 'Nhân viên này';
                var taiKhoan = f.dataset.taiKhoan || '';

                function gui() {
                    oGat.disabled = true;
                    f.submit();
                }

                oGat.addEventListener('change', function () {
                    // Không có tài khoản thì chẳng có gì để hỏi — gạt là xong.
                    if (taiKhoan && oTrangThai.value === DA_NGHI) {
                        hoi({
                            tieuDe: 'Đánh dấu đã nghỉ việc?',
                            doan: [
                                ten + ' chuyển sang trạng thái đã nghỉ việc. Hồ sơ vẫn giữ nguyên để tra lại.',
                                'Tài khoản đăng nhập “' + taiKhoan + '” bị khoá ngay: người này không mở được quầy bán bằng mật khẩu cũ nữa.',
                            ],
                            nutDongY: 'Nghỉ việc & khoá tài khoản',
                            nguyHiem: true,
                        }, function (dongY) {
                            // Trả công tắc về đúng chỗ cũ, đừng để nó nói dối trạng thái.
                            if (!dongY) {
                                oGat.checked = true;

                                return;
                            }
                            gui();
                        });

                        return;
                    }

                    if (taiKhoan && f.dataset.taiKhoanKhoa === '1') {
                        hoi({
                            tieuDe: 'Mở lại tài khoản đăng nhập?',
                            doan: [
                                ten + ' được đặt lại thành đang làm việc.',
                                'Tài khoản “' + taiKhoan + '” đang khoá. Mở lại thì họ đăng nhập được ngay bằng mật khẩu cũ.',
                            ],
                            nutDongY: 'Mở lại tài khoản',
                            nutHuy: 'Chưa mở',
                        }, function (dongY) {
                            // Cả hai nút đều gửi: người này đi làm lại là chuyện đã rồi,
                            // câu hỏi chỉ là tài khoản có mở theo hay không.
                            oMoTaiKhoan.value = dongY ? '1' : '0';
                            gui();
                        });

                        return;
                    }

                    gui();
                });
            });

            // Xoá hồ sơ hỏi qua CÙNG một hộp, không để riêng một dòng confirm() giữa
            // trang: hai kiểu hộp thoại trên cùng màn hình thì cái nào cũng trông như
            // của người khác.
            document.querySelectorAll('[data-nsu-xoa]').forEach(function (f) {
                f.addEventListener('submit', function (e) {
                    e.preventDefault();
                    hoi({
                        tieuDe: 'Xoá hồ sơ nhân viên?',
                        doan: [
                            'Xoá hồ sơ của ' + (f.dataset.ten || 'người này') + '.',
                            'Tài khoản đăng nhập (nếu có) KHÔNG bị đụng tới. Người đã nghỉ thì nên gạt công tắc sang “đã nghỉ việc” — xoá là dành cho hồ sơ nhập nhầm.',
                        ],
                        nutDongY: 'Xoá hồ sơ',
                        nguyHiem: true,
                    }, function (dongY) {
                        // f.submit() không chạy lại lượt kiểm này nên không cần cờ chặn.
                        if (dongY) f.submit();
                    });
                });
            });

            // ---------- Công tắc trạng thái trong form ----------
            var congTac = document.getElementById('nsuTrangThai');
            var congTacChu = document.getElementById('nsuTrangThaiChu');
            var congTacValue = document.getElementById('nsuTrangThaiValue');
            var hangMoTaiKhoan = document.getElementById('nsuMoTaiKhoanRow');
            var oMoTaiKhoanForm = document.getElementById('nsuMoTaiKhoan');
            // Hồ sơ đang mở trong hộp thoại có tài khoản đang khoá hay không; dat()
            // đặt lại mỗi lần mở.
            var taiKhoanDangKhoa = false;

            function dongBoTrangThai() {
                congTacValue.value = congTac.checked ? DANG_LAM : DA_NGHI;
                congTacChu.textContent = congTac.checked ? 'Đang làm việc' : 'Đã nghỉ việc';

                // Hỏi "mở lại tài khoản?" chỉ khi câu hỏi đó có nghĩa: người đang được
                // đặt lại thành đi làm, và tài khoản của họ đang khoá.
                var hoi = taiKhoanDangKhoa && congTac.checked;
                hangMoTaiKhoan.style.display = hoi ? '' : 'none';
                if (!hoi) oMoTaiKhoanForm.checked = false;
            }
            congTac.addEventListener('change', dongBoTrangThai);

            // ---------- Phân quyền: CỬA VÀO của tài khoản ----------
            //
            // Ô tick, không phải danh sách chọn một — một người giữ được cả hai vai
            // như `account_type[]` bên order v2. Dưới database chỉ có MỘT cột
            // `users.role_id`, và tick cả hai quy về vai Quản lý: khu quản trị đã
            // bao trùm quầy bán, nên "quản lý kiêm thu ngân" chính là Quản lý.
            // Chỗ quy đổi nằm ở controller (NhanSuController::vaiTro), một chỗ duy nhất.
            // Ô tick mang `role_id` làm value (form gửi lên `quyen[]`), còn API trả
            // về mã cửa — bảng tra này nối hai bên.
            var CUA_THEO_O = @json($C::CUA);
            var oQuyen = Array.prototype.slice.call(
                document.querySelectorAll('[data-nsu-quyen]'));
            var oCaLam = Array.prototype.slice.call(
                document.querySelectorAll('[data-nsu-ca]'));

            // ---------- Ảnh nhân viên ----------
            //
            // Tải lên NGAY khi chọn tệp, không chờ bấm Lưu: form chỉ mang theo đường
            // dẫn trả về, nên lượt Lưu hỏng (thiếu ô bắt buộc, API từ chối) cũng
            // không bắt người dùng chọn lại ảnh.
            var oAnh = document.getElementById('nsuAnh');
            var oAnhFile = document.getElementById('nsuAnhFile');
            var oAnhXem = document.getElementById('nsuAnhXem');
            var oAnhChu = document.getElementById('nsuAnhChu');
            var oAnhNutChu = document.getElementById('nsuAnhNutChu');
            var oAnhGo = document.getElementById('nsuAnhGo');
            var DUONG_ANH = @json(route('admin.nhan-su.anh'));

            function veAnh(duong) {
                oAnh.value = duong || '';
                var co = !!duong;
                oAnhXem.style.display = co ? '' : 'none';
                oAnhChu.style.display = co ? 'none' : '';
                oAnhGo.style.display = co ? '' : 'none';
                oAnhNutChu.textContent = co ? 'Đổi ảnh' : 'Chọn ảnh';
                if (co) oAnhXem.src = duong;
            }

            oAnhFile.addEventListener('change', function () {
                var tep = oAnhFile.files && oAnhFile.files[0];
                if (!tep) return;

                oAnhNutChu.textContent = 'Đang tải…';
                var du = new FormData();
                du.append('anh', tep);
                du.append('_token', form.querySelector('input[name="_token"]').value);

                fetch(DUONG_ANH, { method: 'POST', body: du })
                    .then(function (r) { return r.ok ? r.json() : r.json().then(function (j) { throw j; }); })
                    .then(function (j) { veAnh(j.url); })
                    .catch(function (j) {
                        // Nói ra ngay tại ô ảnh. Ném người dùng về đầu trang với một
                        // dải lỗi thì họ mất luôn mọi ô vừa gõ trong hộp thoại.
                        oAnhChu.textContent = (j && j.errors && j.errors.anh && j.errors.anh[0])
                            || 'Không tải được ảnh';
                        oAnhChu.style.display = '';
                        oAnhNutChu.textContent = 'Chọn lại';
                    })
                    .then(function () { oAnhFile.value = ''; });
            });

            oAnhGo.addEventListener('click', function () {
                // Chỉ bỏ đường dẫn khỏi form. Tệp trên đĩa giữ nguyên: hồ sơ khác có
                // thể đang trỏ vào nó, và một lượt bấm nhầm không đáng làm mất ảnh.
                veAnh('');
                oAnhChu.textContent = 'Chưa có ảnh';
            });

            // "Cả ngày" LOẠI TRỪ sáng và chiều: nó đã gồm cả hai. Khoá ô thay vì
            // để tick rồi báo lỗi lúc Lưu — người dùng biết mình không chọn được
            // ngay tại chỗ, và không phải điền lại cả form để đọc một câu từ chối.
            //
            // Luật này còn nằm ở controller và ở service bên API: một lượt POST
            // dựng tay không đi qua đoạn mã này.

            // Bật khi hộp thoại đang ở chế độ ĐỌC. Đặt ở đây, trên cả hai hàm dùng
            // tới nó, vì nó là thứ quyết định ai được đụng vào thuộc tính disabled.
            var cheDoChiDoc = false;

            function dongBoCaLam() {
                // Luật nằm ở public/js/nhan-su-luat.js — chỗ node --test chạy tới
                // được. Ở đây chỉ còn việc đem kết quả gắn vào DOM.
                var biKhoa = NhanSuLuat.caLamBiKhoa(oCaLam.map(function (o) {
                    return { value: o.value, checked: o.checked };
                }));

                oCaLam.forEach(function (o) {
                    var khoa = biKhoa[o.value];

                    // NHÃN luôn cập nhật, kể cả ở chế độ đọc. Bỏ qua ở đó thì xem
                    // hồ sơ thứ hai vẫn thấy vệt mờ của hồ sơ thứ nhất — đọc ra một
                    // câu sai về ca trực của người đang mở.
                    o.closest('.nsu-nhom-quyen-item').classList.toggle('is-khoa', khoa);

                    // THUỘC TÍNH disabled thì chỉ một nơi được ghi. Chế độ đọc khoá
                    // sạch mọi ô và khoaForm giữ điều đó; luật ca mà cũng ghi vào
                    // đây nữa thì hai luật giẫm lên nhau, ai chạy sau thì thắng —
                    // kết quả phụ thuộc THỨ TỰ GỌI, thứ nhìn mã không thấy được.
                    if (!cheDoChiDoc) {
                        o.disabled = khoa;
                    }
                });
            }
            oCaLam.forEach(function (o) { o.addEventListener('change', dongBoCaLam); });
            // Hồ sơ đang mở có sẵn tài khoản hay không; dat() đặt lại mỗi lần mở.
            var daCoTaiKhoan = false;

            var coTaiKhoan = document.getElementById('nsuCoTaiKhoan');
            var hopTaiKhoan = document.getElementById('nsuTaiKhoanBox');
            var hangCapTaiKhoan = document.getElementById('nsuCoTaiKhoanRow');
            var chuCapTaiKhoan = document.getElementById('nsuCoTaiKhoanHint');
            var chuDaCoTaiKhoan = document.getElementById('nsuTaiKhoanDaCo');

            function hienKhoiTaiKhoan() {
                hopTaiKhoan.style.display = coTaiKhoan.checked ? '' : 'none';
            }
            coTaiKhoan.addEventListener('change', hienKhoiTaiKhoan);

            // Hồ sơ đã có tài khoản: bỏ công tắc "cấp tài khoản" đi (API từ chối cấp
            // cái thứ hai cho cùng một người) nhưng GIỮ ô quyền lại — đổi vai trò cho
            // tài khoản đang có là việc thường gặp hơn cả lúc cấp nó.
            function khoiTaiKhoanTheoHoSo(hoSo) {
                daCoTaiKhoan = !!(hoSo && hoSo.username);

                hangCapTaiKhoan.style.display = daCoTaiKhoan ? 'none' : '';
                chuCapTaiKhoan.style.display = daCoTaiKhoan ? 'none' : '';
                chuDaCoTaiKhoan.style.display = daCoTaiKhoan ? '' : 'none';
                if (daCoTaiKhoan) {
                    chuDaCoTaiKhoan.textContent = 'Đã có tài khoản: ' + hoSo.username
                        + (hoSo.role_display_name ? ' — ' + hoSo.role_display_name : '')
                        + '. Đổi quyền bằng ô Phân quyền ở tab Chi tiết; đặt lại mật khẩu là thao tác riêng.';
                }

                // Công tắc luôn tắt khi mở hộp thoại: nó nghĩa là "cấp mới".
                coTaiKhoan.checked = false;
                hienKhoiTaiKhoan();
            }

            function dat(hoSo) {
                Object.keys(O).forEach(function (khoa) {
                    var el = document.getElementById(O[khoa]);
                    if (!el) return;
                    var v = hoSo && hoSo[khoa];
                    v = (v === null || v === undefined) ? '' : String(v);
                    // API trả ngày đầy đủ còn <input type="date"> chỉ nhận YYYY-MM-DD;
                    // nhét nguyên chuỗi vào thì trình duyệt bỏ trắng ô.
                    if (el.type === 'date' && v.length > 10) v = v.slice(0, 10);
                    el.value = v;
                    // Ô chọn nhận giá trị lạ thì trình duyệt để trống — về mục đầu.
                    if (el.tagName === 'SELECT' && el.value === '' && el.required) el.selectedIndex = 0;
                });
                // Chạy lại luật màu placeholder của select (layouts/app.blade.php).
                document.querySelectorAll('#nsuForm select[data-ph]').forEach(function (sel) {
                    sel.dispatchEvent(new Event('change', { bubbles: true }));
                });

                // Công tắc bám theo ô hidden vừa đổ giá trị; "tạm nghỉ" của dữ liệu cũ
                // hiện ra như đang tắt.
                congTac.checked = congTacValue.value !== DA_NGHI;
                // Đặt TRƯỚC dongBoTrangThai: nó quyết định ô "mở lại tài khoản" có
                // hiện ra hay không.
                taiKhoanDangKhoa = !!(hoSo && hoSo.username
                    && hoSo.user_status && hoSo.user_status !== 'active');
                dongBoTrangThai();

                veAnh((hoSo && hoSo.avatar) || '');
                document.getElementById('nsuAnhCu').value = (hoSo && hoSo.avatar) || '';
                oAnhChu.textContent = 'Chưa có ảnh';

                // Ca trực: API trả cột SET nguyên chuỗi "sang,chieu" — tách ra rồi
                // tick lại. Không có ô nào trong bảng O vì đây là nhiều ô tick chứ
                // không phải một ô nhập.
                var caDangCo = NhanSuLuat.tachCa(hoSo && hoSo.work_shift);
                // Chỉ đặt ô TICK. Thuộc tính disabled để dongBoCaLam ngay dưới lo —
                // nó ghi cả hai chiều, và giữ đúng một nơi được ghi vào disabled là
                // toàn bộ lý do chỗ này không còn phụ thuộc thứ tự gọi.
                oCaLam.forEach(function (o) {
                    o.checked = caDangCo.indexOf(o.value) !== -1;
                });
                dongBoCaLam();

                // Tick lại ĐÚNG những cửa đã lưu (users.access_areas), không suy từ
                // vai trò: tích gì vào được nấy, nên hộp thoại phải hiện lại y nguyên
                // thứ chủ tiệm đã tích lần trước.
                var nenTick = NhanSuLuat.oQuyenNenTick((hoSo && hoSo.quyen) || [], CUA_THEO_O);
                oQuyen.forEach(function (o) {
                    o.checked = !!nenTick[o.value];
                });

                // Tên đăng nhập và mật khẩu không mang giá trị cũ sang.
                document.getElementById('nsuUsername').value = '';
                document.getElementById('nsuMatKhau').value = '';

                khoiTaiKhoanTheoHoSo(hoSo);
            }

            // Mở hộp thoại là luôn về tab đầu.
            function veTabDau() {
                var dau = document.querySelector('.nsu-tabs .nav-link');
                if (dau && window.bootstrap) bootstrap.Tab.getOrCreateInstance(dau).show();
            }

            // Ô bắt buộc nằm trong tab ẩn thì trình duyệt không focus được và lượt
            // gửi đứng im — mở đúng tab chứa ô đó ra trước.
            form.addEventListener('invalid', function (e) {
                var pane = e.target.closest('.tab-pane');
                if (!pane || pane.classList.contains('active') || !window.bootstrap) return;
                var nut = document.querySelector('[data-bs-target="#' + pane.id + '"]');
                if (nut) bootstrap.Tab.getOrCreateInstance(nut).show();
            }, true);

            function mo() {
                overlay.style.display = 'flex';
                document.getElementById('nsuHoTen').focus();
            }
            function dong() { overlay.style.display = 'none'; }

            document.querySelectorAll('[data-close]').forEach(function (el) {
                el.addEventListener('click', dong);
            });
            overlay.addEventListener('click', function (e) { if (e.target === overlay) dong(); });
            document.addEventListener('keydown', function (e) {
                if (e.key !== 'Escape') return;
                // Hộp xác nhận đứng TRÊN hộp thêm/sửa, nên Esc đóng nó trước và
                // dừng lại — không đóng luôn cả hai bằng một lượt bấm.
                if (hopHoi.style.display === 'flex') {
                    dongHoi(false);

                    return;
                }
                if (overlay.style.display === 'flex') dong();
            });

            // ---------- Chọn nhiều dòng ----------
            var bulkForm = document.getElementById('nsuBulkForm');
            var bulkBar = document.getElementById('nsuBulkBar');
            var bulkSo = document.getElementById('nsuBulkSo');
            var bulkTrangThai = document.getElementById('nsuBulkTrangThai');
            var oChonHet = document.getElementById('nsuChonHet');
            var oChon = Array.prototype.slice.call(document.querySelectorAll('[data-nsu-chon]'));
            var XOA_HANG_LOAT = @json(route('admin.nhan-su.bulkDestroy'));
            var TRANG_THAI_HANG_LOAT = @json(route('admin.nhan-su.bulkTrangThai'));

            function daChon() {
                return oChon.filter(function (o) { return o.checked; });
            }

            function veThanhBulk() {
                var n = daChon().length;
                bulkBar.style.display = n ? 'flex' : 'none';
                bulkSo.textContent = n;

                // Ô "chọn hết" phản ánh đúng ba trạng thái: hết, không, và một phần.
                // Thiếu nấc giữa thì bấm hai lần mới bỏ chọn được — lần đầu nó tự
                // tick lại tất cả.
                if (oChonHet) {
                    oChonHet.checked = n > 0 && n === oChon.length;
                    oChonHet.indeterminate = n > 0 && n < oChon.length;
                }
            }

            oChon.forEach(function (o) { o.addEventListener('change', veThanhBulk); });

            if (oChonHet) {
                oChonHet.addEventListener('change', function () {
                    oChon.forEach(function (o) { o.checked = oChonHet.checked; });
                    veThanhBulk();
                });
            }

            document.getElementById('nsuBulkBo').addEventListener('click', function () {
                oChon.forEach(function (o) { o.checked = false; });
                veThanhBulk();
            });

            // Hỏi lại trước khi chạy, và nói ra HẬU QUẢ chứ không hỏi "chắc chưa":
            // đánh dấu nghỉ việc là khoá tài khoản của từng người trong danh sách.
            document.querySelectorAll('[data-nsu-bulk]').forEach(function (nut) {
                nut.addEventListener('click', function () {
                    var n = daChon().length;
                    if (!n) return;

                    var xoa = nut.dataset.nsuBulk === 'xoa';

                    hoi({
                        tieuDe: xoa ? 'Xoá ' + n + ' hồ sơ?' : 'Đánh dấu nghỉ việc ' + n + ' hồ sơ?',
                        doan: xoa
                            ? [
                                'Hồ sơ được giữ lại để tra lương cũ, nhưng biến mất khỏi danh sách này. '
                                    + 'Tài khoản đăng nhập của từng người bị khoá.',
                                'Người còn ca chưa đóng hoặc đã ghi sổ quỹ sẽ KHÔNG xoá được — '
                                    + 'lượt này báo lại từng trường hợp một.',
                            ]
                            : [
                                'Tài khoản đăng nhập của từng người trong danh sách bị khoá ngay.',
                                'Hồ sơ vẫn giữ nguyên để tra lại, và nhận họ làm lại thì mở tài khoản '
                                    + 'là một lượt bấm riêng.',
                            ],
                        nutDongY: xoa ? 'Xoá' : 'Đánh dấu nghỉ việc',
                        nguyHiem: true,
                    }, function (dongY) {
                        if (!dongY) return;
                        bulkForm.action = xoa ? XOA_HANG_LOAT : TRANG_THAI_HANG_LOAT;
                        bulkTrangThai.value = xoa ? '' : DA_NGHI;
                        bulkForm.submit();
                    });
                });
            });

            veThanhBulk();

            var them = document.querySelector('[data-nsu-add]');
            if (them) {
                them.addEventListener('click', function () {
                    // Mở khoá trước: hộp thoại có thể vừa đóng lại ở chế độ chỉ đọc.
                    khoaForm(false);
                    form.action = STORE;
                    method.value = '';
                    title.textContent = 'Thêm nhân viên';
                    submit.textContent = 'Lưu nhân viên';
                    dat({ status: DANG_LAM, shop_id: CHI_NHANH_MAC_DINH });
                    veTabDau();
                    mo();
                });
            }

            // ---------- Xem chi tiết ----------
            //
            // KHÔNG dựng một hộp thoại thứ hai. Dùng CHÍNH hộp thêm/sửa rồi khoá
            // lại — cùng bố cục bốn cột, cùng ba tab, cùng vị trí từng ô.
            //
            // Hai hộp riêng thì phải xếp lại từng ô lần nữa, và từ hôm sau chúng
            // trôi khỏi nhau: thêm một ô vào form thì hộp xem thiếu ô đó, mà không
            // có gì báo. Một hộp hai chế độ thì không có chỗ nào để lệch.
            var xemHoSo = null;

            function khoaForm(khoa) {
                cheDoChiDoc = khoa;

                // Ô ẩn ĐỨNG NGOÀI, khai thẳng trong bộ chọn chứ không lọc bằng một
                // câu if bên trong: `_token`, `_method`, `status` và đường dẫn ảnh
                // không phải thứ người dùng gõ, mà khoá chúng lại thì trình duyệt
                // bỏ luôn khỏi lượt gửi — lượt bấm "Sửa hồ sơ" ngay sau đó đi thiếu
                // và hỏng ở chỗ chẳng liên quan gì tới cái nút vừa bấm.
                form.querySelectorAll('input:not([type="hidden"]), select, textarea')
                    .forEach(function (el) { el.disabled = khoa; });

                form.classList.toggle('nsu-form-chi-doc', khoa);
                submit.style.display = khoa ? 'none' : '';
                nutSuaTuXem.style.display = khoa ? '' : 'none';

                // Mở khoá xong thì áp LẠI luật ca ngay, vì vòng lặp trên vừa bật
                // tất cả — kể cả mấy ô đáng lẽ vẫn phải khoá vì đã tick "Cả ngày".
                // Nhờ dòng này mà gọi khoaForm() và dat() theo thứ tự nào cũng ra
                // cùng một kết quả.
                if (!khoa) {
                    dongBoCaLam();
                }
            }

            // Nút "Sửa hồ sơ" chỉ hiện ở chế độ đọc — dựng bằng JS vì nó thuộc về
            // chế độ, không thuộc về cái form.
            var nutSuaTuXem = document.createElement('button');
            nutSuaTuXem.type = 'button';
            nutSuaTuXem.className = 'nsu-btn-primary';
            nutSuaTuXem.textContent = 'Sửa hồ sơ';
            nutSuaTuXem.style.display = 'none';
            submit.parentNode.appendChild(nutSuaTuXem);

            function moSua(hoSo) {
                khoaForm(false);
                form.action = UPDATE.replace(/0$/, hoSo.id);
                method.value = 'PUT';
                title.textContent = 'Sửa hồ sơ nhân viên';
                submit.textContent = 'Cập nhật';
                dat(hoSo);
                veTabDau();
                mo();
            }

            nutSuaTuXem.addEventListener('click', function () {
                if (xemHoSo) moSua(xemHoSo);
            });

            document.querySelectorAll('[data-nsu-xem]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var hoSo = {};
                    try { hoSo = JSON.parse(btn.dataset.hoSo || '{}'); } catch (e) { hoSo = {}; }
                    xemHoSo = hoSo;

                    dat(hoSo);
                    khoaForm(true);

                    title.textContent = 'Hồ sơ nhân viên';
                    veTabDau();
                    mo();
                });
            });

            document.querySelectorAll('[data-nsu-edit]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var hoSo = {};
                    try { hoSo = JSON.parse(btn.dataset.hoSo || '{}'); } catch (e) { hoSo = {}; }
                    moSua(hoSo);
                });
            });

            // Vừa bấm Lưu mà hỏng: mở lại hộp thoại (các ô đã điền sẵn bằng old()).
            hienKhoiTaiKhoan();
            @if($moLaiForm)
                mo();
            @endif
        })();
    </script>
@endsection
