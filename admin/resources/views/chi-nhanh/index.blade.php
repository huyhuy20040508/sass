@extends('layouts.app')

@section('title', \App\Http\Controllers\ChiNhanhController::TITLE_PAGE)

@section('content')
    {{-- Bộ cột và chức năng lấy của bản cũ v2; bố cục theo khuôn trang danh sách
         hiện tại (Vị trí, Nhân sự): header + thanh lọc ngang + bảng + hộp thoại. --}}
    @php
        $C = \App\Http\Controllers\ChiNhanhController::class;
        $TITLE = $C::TITLE_PAGE;

        $so = fn ($n) => number_format((int) $n, 0, ',', '.');
        $hasFilter = $filters['keyword'] !== '' || $filters['branch'] > 0 || $filters['status'] !== '';

        // Lưu hỏng thì mở lại hộp thoại kèm dữ liệu vừa gõ.
        $moLaiForm = filled(old('name'));

        // Cột ẩn/hiện được — STT đứng ngoài, v2 cũng không cho tắt nó.
        $cotAnHien = [
            'code' => 'Mã chi nhánh',
            'name' => 'Tên chi nhánh',
            'tax' => 'Mã số thuế',
            'phone' => 'Điện thoại',
            'etax' => 'Sử dụng HĐĐT',
            'type' => 'Công ty / Chi nhánh',
            'created' => 'Thời gian tạo',
            'status' => 'Trạng thái',
            'act' => 'Hành động',
        ];

        $gio = fn ($s) => filled($s) ? \Illuminate\Support\Carbon::parse($s)->format('H:i:s d-m-Y') : '—';
        $MAX = $C::CHU_HOA_DON_TOI_DA;

        // Khu quản trị không dùng guard của Laravel — hồ sơ người đăng nhập nằm
        // trong phiên (xem AuthController).
        $toiLa = trim((string) data_get(session('api.user'), 'full_name', ''))
            ?: (string) data_get(session('api.user'), 'username', '');
    @endphp

    <div class="cn">
        <div class="cn-head">
            <h1 class="cn-title">{{ $TITLE }}</h1>
            <span class="cn-sum">Đang mở <b>{{ $so($dangMo) }}</b>/{{ $so($tong) }} chi nhánh</span>
        </div>

        {{-- Callout chỉ cho lỗi TẢI TRANG; kết quả một lượt bấm để toast lo. --}}
        @if(!empty($error))
            <p class="cn-callout is-error">{{ $error }}</p>
        @endif

        {{-- Lọc realtime: đổi select chạy ngay, gõ chờ 400ms. Không có nút "Lọc". --}}
        <form method="GET" action="{{ route('admin.chi-nhanh.index') }}" id="cnFilter" class="cn-filter">
            <div class="cn-toolbar">
                <div class="cn-searchbox">
                    <input type="text" name="keyword" value="{{ $filters['keyword'] }}" class="cn-search-input"
                           placeholder="Tìm theo tên hoặc mã chi nhánh" autocomplete="off">
                    <button type="submit" class="cn-search-btn" title="Tìm kiếm">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                    </button>
                </div>

                <select name="branch" class="cn-select" title="Lọc theo chi nhánh">
                    <option value="">Tất cả chi nhánh</option>
                    @foreach($tatCa as $item)
                        <option value="{{ $item['id'] }}" @selected($filters['branch'] === $item['id'])>
                            {{ $item['code'] }} - {{ $item['name'] }}
                        </option>
                    @endforeach
                </select>

                <select name="status" class="cn-select" title="Lọc theo trạng thái">
                    <option value="">Tất cả trạng thái</option>
                    @foreach($C::TRANG_THAI as $ma => $ten)
                        <option value="{{ $ma }}" @selected($filters['status'] === (string) $ma)>{{ $ten }}</option>
                    @endforeach
                </select>

                @if($hasFilter)
                    <a href="{{ route('admin.chi-nhanh.index') }}" class="cn-clear">Xoá lọc</a>
                @endif

                {{-- Thứ tự nút của mọi trang danh sách: Thêm → Xuất file → tiện ích. --}}
                <div class="cn-toolbar-actions">
                    <button type="button" class="cn-btn-primary" data-cn-add>
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M12 5v14"/></svg>
                        Thêm chi nhánh
                    </button>

                    <button type="button" class="cn-btn-ghost" id="cnExport">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5"/><path d="M12 15V3"/></svg>
                        Xuất file (CSV)
                    </button>

                    {{-- Ẩn/hiện cột: v2 lưu xuống database theo từng người, ở đây chưa
                         có bảng nào giữ hộ nên cất vào localStorage. --}}
                    <div class="cn-cols" id="cnCols">
                        <button type="button" class="cn-btn-ghost" id="cnColsBtn" title="Ẩn/hiện cột">
                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 6h16M4 12h16M4 18h16"/><circle cx="9" cy="6" r="2"/><circle cx="15" cy="12" r="2"/><circle cx="8" cy="18" r="2"/></svg>
                            Cột
                        </button>
                        <div class="cn-cols-panel" id="cnColsPanel" hidden>
                            <label class="cn-cols-item is-all">
                                <input type="checkbox" id="cnColsAll" checked>
                                <span>Tất cả</span>
                            </label>
                            <div class="cn-cols-sep"></div>
                            @foreach($cotAnHien as $ma => $ten)
                                <label class="cn-cols-item">
                                    <input type="checkbox" class="cn-cols-one" data-col="{{ $ma }}" checked>
                                    <span>{{ $ten }}</span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
        </form>

        <div class="cn-table-wrap">
            <table class="cn-table" id="cnTable">
                <thead>
                    <tr>
                        <th class="cn-c-stt">STT</th>
                        <th class="cn-c-code">Mã chi nhánh</th>
                        <th class="cn-c-name">Tên chi nhánh</th>
                        <th class="cn-c-tax">Mã số thuế</th>
                        <th class="cn-c-phone">Điện thoại</th>
                        <th class="cn-c-etax">Sử dụng HĐĐT</th>
                        <th class="cn-c-type">Công ty / Chi nhánh</th>
                        <th class="cn-c-created">Thời gian tạo</th>
                        <th class="cn-c-status">Trạng thái</th>
                        <th class="cn-c-act cn-no-export">Hành động</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($list as $i => $cn)
                        @php
                            $id = (int) ($cn['id'] ?? 0);
                            $mo = (bool) ($cn['is_active'] ?? false);
                            $dangDungRow = $dangLamId > 0 && $dangLamId === $id;
                            $loai = (int) ($cn['branch_type'] ?? $C::LOAI_CHI_NHANH);
                            $etax = $cn['etax'] ?? null;
                            $json = json_encode($cn, JSON_UNESCAPED_UNICODE);
                        @endphp
                        <tr>
                            <td class="cn-c-stt cn-muted">{{ $stt + $i + 1 }}</td>
                            <td class="cn-c-code"><code class="cn-code">{{ ($cn['code'] ?? '') ?: '—' }}</code></td>
                            <td class="cn-c-name">
                                <span class="cn-name">{{ ($cn['name'] ?? '') ?: '—' }}</span>
                                @if($dangDungRow)
                                    <span class="cn-here" title="Mọi thao tác kho và đơn của bạn đang tính vào chi nhánh này">Đang làm việc</span>
                                @endif
                            </td>
                            <td class="cn-c-tax">{{ ($cn['tax_code'] ?? '') ?: '—' }}</td>
                            <td class="cn-c-phone">{{ ($cn['phone'] ?? '') ?: '—' }}</td>
                            <td class="cn-c-etax">
                                {{-- Đã nối thì mở thẳng hộp cài đặt, chưa nối thì mở hộp khai
                                     tài khoản — cùng một nút, hộp thoại tự chọn mặt. --}}
                                <button type="button" class="cn-btn-etax {{ $etax ? '' : 'is-chua' }}"
                                        data-cn-etax data-id="{{ $id }}" data-ten="{{ $cn['name'] ?? '' }}"
                                        {{-- Chữ đi vào tệp xuất: "Chi tiết" trong bảng tính thì
                                             không nói lên điều gì. --}}
                                        data-xuat="{{ $etax ? 'Đã kết nối - '.($etax['tax_code'] ?? '') : 'Chưa kết nối' }}"
                                        title="{{ $etax
                                            ? 'Đang dùng '.($C::NHA_CUNG_CAP_ETAX[$etax['provider'] ?? ''] ?? 'cổng hoá đơn').' — mã số thuế '.($etax['tax_code'] ?? '')
                                            : 'Chi nhánh này chưa nối cổng hoá đơn điện tử' }}">
                                    {{ $etax ? 'Chi tiết' : 'Kết nối' }}
                                </button>
                            </td>
                            <td class="cn-c-type">{{ $C::LOAI[$loai] ?? '—' }}</td>
                            <td class="cn-c-created cn-muted">{{ $gio($cn['created_at'] ?? null) }}</td>
                            <td class="cn-c-status">
                                {{-- Mỗi dòng một form riêng, gửi ĐÚNG một trường sang /status. --}}
                                <form method="POST" action="{{ route('admin.chi-nhanh.toggleStatus', $id) }}"
                                      class="cn-switch-form" data-cn-status>
                                    @csrf
                                    @method('PUT')
                                    <input type="hidden" name="is_active" value="{{ $mo ? 0 : 1 }}">
                                    {{-- Chi nhánh mình đang đứng thì khoá công tắc: đóng nó là
                                         mọi thao tác sau đó của chính người vừa bấm rơi vào một
                                         điểm bán đã đóng. --}}
                                    <input type="checkbox" class="cn-switch" @checked($mo)
                                           @disabled($dangDungRow)
                                           title="{{ $dangDungRow
                                                ? 'Bạn đang làm việc tại chi nhánh này — đổi chi nhánh ở thanh trên cùng trước khi đóng nó.'
                                                : ($mo ? 'Đang mở — bấm để đóng' : 'Đã đóng — bấm để mở lại') }}">
                                    <noscript><button type="submit" class="cn-btn-ghost">Đổi</button></noscript>
                                </form>
                            </td>
                            <td class="cn-c-act cn-no-export">
                                <div class="cn-rowacts">
                                    <button type="button" class="cn-rowbtn cn-view" title="Xem chi tiết"
                                            data-cn-view data-cn="{{ $json }}">
                                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M2.06 12.35a1 1 0 0 1 0-.7 10.75 10.75 0 0 1 19.88 0 1 1 0 0 1 0 .7 10.75 10.75 0 0 1-19.88 0"/><circle cx="12" cy="12" r="3"/></svg>
                                    </button>

                                    <button type="button" class="cn-rowbtn cn-edit" title="Sửa"
                                            data-cn-edit data-cn="{{ $json }}">
                                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                                    </button>

                                    {{-- Form riêng từng dòng: nút dùng chung + id nhét bằng JS
                                         thì lúc JS hỏng sẽ xoá nhầm dòng cuối cùng đã gán. --}}
                                    <form method="POST" action="{{ route('admin.chi-nhanh.destroy', $id) }}"
                                          class="cn-inline-form" data-cn-xoa data-ten="{{ $cn['name'] ?? '' }}">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="cn-rowbtn cn-del" title="Xoá">
                                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M6 6v14a1 1 0 0 0 1 1h10a1 1 0 0 0 1-1V6"/><path d="M10 11v6M14 11v6"/></svg>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10" class="cn-empty">
                                {{ $hasFilter ? 'Không có chi nhánh nào khớp bộ lọc đang bật.' : $C::EMPTY_TEXT }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @include('partials.pagination-v2', [
            'meta' => $meta,
            'noun' => 'chi nhánh',
            'perPageOptions' => $C::MUC_SO_DONG,
        ])
    </div>

    {{-- ===================== HỘP THOẠI THÊM / SỬA ===================== --}}
    <div class="cn-overlay" id="cnOverlay" style="display:none;">
        <div class="cn-dialog">
            <div class="cn-modal-head">
                <h4 class="cn-modal-title" id="cnModalTitle">Thêm chi nhánh</h4>
                <button type="button" class="cn-modal-x" data-close>
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </div>

            <form id="cnForm" method="POST" action="{{ route('admin.chi-nhanh.store') }}">
                @csrf
                {{-- _method rỗng khi TẠO, "PUT" khi sửa — Laravel bỏ qua giá trị rỗng. --}}
                <input type="hidden" name="_method" id="cnMethod" value="">
                <input type="hidden" name="cn_id" id="cnId" value="{{ old('cn_id') }}">
                <input type="hidden" name="image" id="cnAnh" value="{{ old('image') }}">
                <input type="hidden" name="is_active" id="cnTrangThaiValue" value="{{ old('is_active', 1) }}">

                <div class="cn-modal-body">
                    <div class="cn-cols-form">
                        {{-- ----- Nhận diện ----- --}}
                        <section class="cn-col cn-col-anh">
                            <p class="cn-nhom">Nhận diện</p>

                            <div class="cn-anh-khung">
                                <img id="cnAnhPreview" src="" alt="Logo chi nhánh" style="display:none;">
                                <span class="cn-anh-trong" id="cnAnhChu">
                                    <svg viewBox="0 0 24 24" width="30" height="30" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"><rect x="2.5" y="5" width="19" height="14" rx="2"/><circle cx="8.5" cy="10" r="1.6"/><path d="m3.5 17 5-4.5 3.5 3 3.5-3.5 5 5"/></svg>
                                </span>
                            </div>
                            <div class="cn-anh-nut">
                                <label class="cn-btn-nho" id="cnAnhNut"><span id="cnAnhNutChu">Chọn ảnh</span></label>
                                <button type="button" class="cn-link-nho" id="cnAnhGo" style="display:none;">Gỡ ảnh</button>
                            </div>
                            <input type="file" id="cnAnhFile" accept="image/*" hidden>
                            <p class="cn-hint cn-tt-giua">Logo in trên hoá đơn tại quầy — chọn ảnh ngang.</p>

                            <div class="cn-anh-tt">
                                <span class="cn-field-label">Trạng thái</span>
                                <label class="cn-switch-row">
                                    <input type="checkbox" id="cnTrangThai" class="cn-switch" @checked(old('is_active', 1))>
                                    <span class="cn-switch-label" id="cnTrangThaiChu">Đang mở</span>
                                </label>
                            </div>
                        </section>

                        {{-- ----- Hồ sơ ----- --}}
                        <section class="cn-col">
                            <p class="cn-nhom">Hồ sơ</p>

                            <div>
                                <label class="cn-field-label" for="cnMa">Mã chi nhánh</label>
                                <input type="text" id="cnMa" name="code" class="cn-input" maxlength="30"
                                       autocomplete="off" placeholder="Bỏ trống để hệ thống tự đặt" value="{{ old('code') }}">
                                <p class="cn-hint" id="cnMaHint">
                                    Chữ thường không dấu, số, chấm, gạch ngang hoặc gạch dưới.
                                    Bỏ trống thì hệ thống đặt theo
                                    <a href="{{ route('admin.thong-so-chung.quy-tac-danh-so') }}" target="_blank" rel="noopener">quy tắc đánh số</a>
                                    của cửa hàng; chưa khai quy tắc thì dùng dải <code>chi-nhanh-2</code>.
                                </p>
                            </div>

                            <div>
                                <label class="cn-field-label" for="cnTen">Tên chi nhánh <span class="cn-req">*</span></label>
                                <input type="text" id="cnTen" name="name" class="cn-input" maxlength="150" required
                                       autocomplete="off" placeholder="Ví dụ: Kho miền Bắc" value="{{ old('name') }}">
                            </div>

                            <div>
                                <label class="cn-field-label" for="cnTenGD">Tên giao dịch viết tắt</label>
                                <input type="text" id="cnTenGD" name="transaction_name" class="cn-input" maxlength="150"
                                       autocomplete="off" placeholder="Tên ngắn in trên hoá đơn" value="{{ old('transaction_name') }}">
                            </div>

                            <div>
                                <label class="cn-field-label" for="cnMST">Mã số thuế</label>
                                <input type="text" id="cnMST" name="tax_code" class="cn-input" maxlength="20"
                                       autocomplete="off" value="{{ old('tax_code') }}">
                            </div>

                            <div>
                                <span class="cn-field-label">Công ty / Chi nhánh</span>
                                <div class="cn-radios">
                                    @foreach($C::LOAI as $ma => $ten)
                                        <label class="cn-radio">
                                            <input type="radio" name="branch_type" value="{{ $ma }}"
                                                   @checked((int) old('branch_type', $C::LOAI_CHI_NHANH) === $ma)>
                                            <span>{{ $ten }}</span>
                                        </label>
                                    @endforeach
                                </div>
                            </div>
                        </section>

                        {{-- ----- Liên hệ & vị trí ----- --}}
                        <section class="cn-col">
                            <p class="cn-nhom">Liên hệ &amp; vị trí</p>

                            <div>
                                <label class="cn-field-label" for="cnDiaChi">Địa chỉ</label>
                                <input type="text" id="cnDiaChi" name="address" class="cn-input" maxlength="255"
                                       autocomplete="off" value="{{ old('address') }}">
                            </div>

                            <div class="cn-row2">
                                <div>
                                    <label class="cn-field-label" for="cnThanhPho">Thành phố</label>
                                    <input type="text" id="cnThanhPho" name="city" class="cn-input" maxlength="100"
                                           autocomplete="off" value="{{ old('city') }}">
                                </div>
                                <div>
                                    <label class="cn-field-label" for="cnQuocGia">Quốc gia</label>
                                    <input type="text" id="cnQuocGia" name="country" class="cn-input" maxlength="100"
                                           autocomplete="off" placeholder="Việt Nam" value="{{ old('country') }}">
                                </div>
                            </div>

                            <div class="cn-row-viTri">
                                <div>
                                    <label class="cn-field-label" for="cnViTri">
                                        Vị trí
                                        <a href="https://support.google.com/maps/answer/18539?hl=vi" target="_blank" rel="noopener"
                                           class="cn-help" title="Cách lấy toạ độ trên Google Maps">
                                            <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M9.6 9.3a2.5 2.5 0 0 1 4.8.9c0 1.7-2.4 2.1-2.4 3.6"/><path d="M12 17.2v.1"/></svg>
                                        </a>
                                    </label>
                                    <input type="text" id="cnViTri" name="location" class="cn-input" maxlength="50"
                                           autocomplete="off" placeholder="10.813129, 106.710010" value="{{ old('location') }}">
                                </div>
                                <div>
                                    <label class="cn-field-label" for="cnPhamVi">Phạm vi</label>
                                    <div class="cn-input-don">
                                        <input type="number" id="cnPhamVi" name="area_scope" class="cn-input" min="1"
                                               autocomplete="off" placeholder="100" value="{{ old('area_scope') }}">
                                        <span>m</span>
                                    </div>
                                </div>
                            </div>
                            <p class="cn-hint cn-hint-keo">Khai vị trí thì phải khai cả phạm vi, và ngược lại.</p>

                            <div class="cn-row2">
                                <div>
                                    <label class="cn-field-label" for="cnDienThoai">Điện thoại</label>
                                    <input type="text" id="cnDienThoai" name="phone" class="cn-input" maxlength="20"
                                           autocomplete="off" value="{{ old('phone') }}">
                                </div>
                                <div>
                                    <label class="cn-field-label" for="cnEmail">Email</label>
                                    <input type="email" id="cnEmail" name="email" class="cn-input" maxlength="150"
                                           autocomplete="off" value="{{ old('email') }}">
                                </div>
                            </div>

                            <div>
                                <label class="cn-field-label" for="cnLink">Link truy cập</label>
                                <input type="text" id="cnLink" name="access_link" class="cn-input" maxlength="255"
                                       autocomplete="off" placeholder="Link đặt hàng online của điểm bán" value="{{ old('access_link') }}">
                            </div>
                        </section>

                        {{-- ----- In trên hoá đơn ----- --}}
                        <section class="cn-col">
                            <p class="cn-nhom">In trên hoá đơn</p>

                            @php
                                $khoiHoaDon = [
                                    ['header_invoice_info', 'cnHeader', 'Thông tin tên cửa hàng'],
                                    ['wifi_invoice_info', 'cnWifi', 'Thông tin wifi'],
                                    ['footer_invoice_info', 'cnFooter', 'Thông tin chân hoá đơn'],
                                ];
                            @endphp
                            @foreach($khoiHoaDon as [$ten, $oid, $nhan])
                                <div>
                                    <label class="cn-field-label" for="{{ $oid }}">{{ $nhan }}</label>
                                    <textarea id="{{ $oid }}" name="{{ $ten }}" class="cn-textarea cn-dem" rows="3"
                                              maxlength="{{ $MAX }}" placeholder="{{ $nhan }}">{{ old($ten) }}</textarea>
                                    <small class="cn-dem-so"><span>0</span> / {{ $MAX }}</small>
                                </div>
                            @endforeach

                            {{-- Hai ô hệ thống tự ghi, bày ra để đối chiếu. --}}
                            <div class="cn-doc-them">
                                <div>
                                    <label class="cn-field-label" for="cnNguoiTao">Người tạo</label>
                                    <input type="text" id="cnNguoiTao" class="cn-input" disabled value="{{ $toiLa }}">
                                </div>
                                <div>
                                    <label class="cn-field-label" for="cnNgayTao">Thời gian tạo</label>
                                    <input type="text" id="cnNgayTao" class="cn-input" disabled value="">
                                </div>
                            </div>
                        </section>
                    </div>
                </div>

                <div class="cn-modal-foot">
                    <button type="button" class="cn-btn-ghost" data-close>Đóng</button>
                    <button type="submit" class="cn-btn-primary" id="cnSubmit">Lưu</button>
                </div>
            </form>
        </div>
    </div>

    {{-- ===================== HỘP THOẠI XEM CHI TIẾT ===================== --}}
    <div class="cn-overlay" id="cnViewOverlay" style="display:none;">
        <div class="cn-dialog is-view">
            <div class="cn-modal-head">
                <h4 class="cn-modal-title">Chi tiết chi nhánh</h4>
                <button type="button" class="cn-modal-x" data-cn-view-close>
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </div>

            <div class="cn-modal-body">
                <div class="cn-view-grid">
                    <div>
                        <p class="cn-nhom">Hồ sơ</p>
                        <div class="cn-info">
                            <div class="cn-info-row"><label>Mã chi nhánh</label><span id="cnVMa"></span></div>
                            <div class="cn-info-row"><label>Tên chi nhánh</label><span id="cnVTen"></span></div>
                            <div class="cn-info-row"><label>Tên giao dịch</label><span id="cnVTenGD"></span></div>
                            <div class="cn-info-row"><label>Mã số thuế</label><span id="cnVMST"></span></div>
                            <div class="cn-info-row"><label>Công ty / Chi nhánh</label><span id="cnVLoai"></span></div>
                            <div class="cn-info-row"><label>Trạng thái</label><span id="cnVTrangThai"></span></div>
                            <div class="cn-info-row"><label>Người tạo</label><span id="cnVNguoiTao"></span></div>
                            <div class="cn-info-row"><label>Thời gian tạo</label><span id="cnVNgayTao"></span></div>
                        </div>
                    </div>

                    <div>
                        <p class="cn-nhom">Liên hệ &amp; vị trí</p>
                        <div class="cn-info">
                            <div class="cn-info-row"><label>Địa chỉ</label><span id="cnVDiaChi"></span></div>
                            <div class="cn-info-row"><label>Thành phố</label><span id="cnVThanhPho"></span></div>
                            <div class="cn-info-row"><label>Quốc gia</label><span id="cnVQuocGia"></span></div>
                            <div class="cn-info-row"><label>Vị trí</label><span id="cnVViTri"></span></div>
                            <div class="cn-info-row"><label>Phạm vi</label><span id="cnVPhamVi"></span></div>
                            <div class="cn-info-row"><label>Điện thoại</label><span id="cnVDienThoai"></span></div>
                            <div class="cn-info-row"><label>Email</label><span id="cnVEmail"></span></div>
                            <div class="cn-info-row"><label>Link truy cập</label><span id="cnVLink"></span></div>
                        </div>
                    </div>

                    <div>
                        <p class="cn-nhom">In trên hoá đơn</p>
                        <div class="cn-vanh">
                            <img id="cnVAnh" src="" alt="Logo chi nhánh" style="display:none;">
                            <span class="cn-anh-trong" id="cnVAnhTrong">Chưa có logo</span>
                        </div>
                        <label class="cn-field-label">Thông tin tên cửa hàng</label>
                        <div class="cn-vpre" id="cnVHeader"></div>
                        <label class="cn-field-label">Thông tin wifi</label>
                        <div class="cn-vpre" id="cnVWifi"></div>
                        <label class="cn-field-label">Thông tin chân hoá đơn</label>
                        <div class="cn-vpre" id="cnVFooter"></div>
                    </div>
                </div>
            </div>

            <div class="cn-modal-foot">
                <button type="button" class="cn-btn-ghost" data-cn-view-close>Đóng</button>
            </div>
        </div>
    </div>

    {{-- ===================== HỘP THOẠI HOÁ ĐƠN ĐIỆN TỬ ===================== --}}
    <div class="cn-overlay" id="cnEtaxOverlay" style="display:none;">
        <div class="cn-dialog is-etax">
            <div class="cn-modal-head">
                <h4 class="cn-modal-title">Hoá đơn điện tử <span class="cn-modal-phu" id="cnEtaxTenCN"></span></h4>
                <button type="button" class="cn-modal-x" data-cn-etax-close>
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </div>

            <div class="cn-modal-body">
                <p class="cn-etax-tai" id="cnEtaxTai">Đang đọc kết nối…</p>

                {{-- ----- Mặt 1: chưa nối, khai tài khoản ----- --}}
                <form id="cnEtaxFormNoi" method="POST" action="" hidden>
                    @csrf
                    <p class="cn-etax-dan">
                        Khai tài khoản cổng hoá đơn điện tử của chi nhánh này. Hệ thống ĐĂNG NHẬP THẬT
                        vào cổng trước khi lưu, rồi kéo luôn danh sách ký hiệu đã đăng ký về.
                    </p>

                    <div>
                        <span class="cn-field-label">Nhà cung cấp</span>
                        <div class="cn-radios">
                            @foreach($C::NHA_CUNG_CAP_ETAX as $ma => $ten)
                                <label class="cn-radio">
                                    <input type="radio" name="provider" value="{{ $ma }}" @checked($loop->first)>
                                    <span>{{ $ten }}</span>
                                </label>
                            @endforeach
                        </div>
                    </div>

                    <div class="cn-row2">
                        <div>
                            <label class="cn-field-label" for="cnEtaxMST">Mã số thuế <span class="cn-req">*</span></label>
                            <input type="text" id="cnEtaxMST" name="tax_code" class="cn-input" maxlength="30"
                                   required autocomplete="off" placeholder="0312345678">
                            <p class="cn-hint">Chính mã số thuế đăng nhập cổng — địa chỉ máy chủ dựng từ nó.</p>
                        </div>
                        <div>
                            <label class="cn-field-label" for="cnEtaxDVCS">Mã đơn vị cơ sở</label>
                            <input type="text" id="cnEtaxDVCS" name="ma_dvcs" class="cn-input" maxlength="20"
                                   autocomplete="off" placeholder="VP" value="VP">
                            <p class="cn-hint">Bỏ trống = VP (văn phòng).</p>
                        </div>
                    </div>

                    <div class="cn-row2">
                        <div>
                            <label class="cn-field-label" for="cnEtaxUser">Tên đăng nhập <span class="cn-req">*</span></label>
                            <input type="text" id="cnEtaxUser" name="username" class="cn-input" maxlength="150"
                                   required autocomplete="off">
                        </div>
                        <div>
                            <label class="cn-field-label" for="cnEtaxPass">Mật khẩu <span class="cn-req">*</span></label>
                            <input type="password" id="cnEtaxPass" name="password" class="cn-input" maxlength="200"
                                   required autocomplete="new-password">
                        </div>
                    </div>
                    <p class="cn-hint">Mật khẩu được mã hoá trước khi lưu và không có đường đọc lại. Đổi mật khẩu bên nhà cung cấp thì khai lại ở đây.</p>
                </form>

                {{-- ----- Mặt 2: đã nối, cài đặt phát hành ----- --}}
                <form id="cnEtaxFormLuu" method="POST" action="" hidden>
                    @csrf
                    @method('PUT')

                    <div class="cn-info cn-etax-info">
                        <div class="cn-info-row"><label>Nhà cung cấp</label><span id="cnEtaxNhaCC"></span></div>
                        <div class="cn-info-row"><label>Mã số thuế</label><span id="cnEtaxVMST"></span></div>
                        <div class="cn-info-row"><label>Tên đăng nhập</label><span id="cnEtaxVUser"></span></div>
                        <div class="cn-info-row"><label>Đăng nhập gần nhất</label><span id="cnEtaxVGio"></span></div>
                    </div>

                    <div>
                        <label class="cn-field-label" for="cnEtaxKyHieu">Ký hiệu phát hành <span class="cn-req">*</span></label>
                        <select id="cnEtaxKyHieu" name="template_symbol" class="cn-select cn-select-rong"></select>
                        <p class="cn-hint">
                            Danh sách kéo về từ cổng. Vừa đăng ký thêm ký hiệu mới thì bấm
                            <b>Đồng bộ mẫu</b> rồi chọn lại.
                        </p>
                    </div>

                    <label class="cn-switch-row">
                        <input type="checkbox" id="cnEtaxTuPhat" name="auto_release" value="1" class="cn-switch">
                        <span class="cn-switch-label">Tự phát hành hoá đơn khi đơn thanh toán xong</span>
                    </label>

                    <label class="cn-switch-row">
                        <input type="checkbox" id="cnEtaxTuIn" name="auto_print" value="1" class="cn-switch">
                        <span class="cn-switch-label">Tự in hoá đơn sau khi phát hành</span>
                    </label>

                    <p class="cn-hint">
                        Tự phát hành chỉ chạy được với chữ ký số MỀM (file p12, hoặc dịch vụ
                        EASY / ICA / INTRUST). Ký bằng USB token thì đơn vẫn lưu thành hoá đơn
                        nháp, bấm <b>Ký và gửi</b> ở màn Đơn hàng để phát hành.
                    </p>

                    <div class="cn-etax-phu">
                        <button type="button" class="cn-btn-nho" id="cnEtaxDongBo">Đồng bộ mẫu</button>
                        <button type="button" class="cn-link-nho" id="cnEtaxNgat">Ngắt kết nối</button>
                    </div>
                </form>
            </div>

            <div class="cn-modal-foot">
                <button type="button" class="cn-btn-ghost" data-cn-etax-close>Đóng</button>
                <button type="submit" class="cn-btn-primary" id="cnEtaxNoi" form="cnEtaxFormNoi" hidden>Kết nối</button>
                <button type="submit" class="cn-btn-primary" id="cnEtaxLuu" form="cnEtaxFormLuu" hidden>Lưu cài đặt</button>
            </div>
        </div>
    </div>

    {{-- Hai đường phụ của HĐĐT: form rỗng đứng ngoài để nút trong hộp thoại gửi
         được mà không phải lồng form vào form. --}}
    <form method="POST" id="cnEtaxFormDongBo" action="" class="cn-inline-form">@csrf</form>
    <form method="POST" id="cnEtaxFormNgat" action="" class="cn-inline-form">@csrf @method('DELETE')</form>

    {{-- ===================== HỘP XÁC NHẬN XOÁ ===================== --}}
    <div class="cn-overlay" id="cnConfirm" style="display:none;">
        <div class="cn-dialog is-confirm">
            <div class="cn-modal-head">
                <h4 class="cn-modal-title" id="cnConfirmTitle">Xoá chi nhánh?</h4>
                <button type="button" class="cn-modal-x" data-cn-confirm-huy>
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </div>
            <div class="cn-modal-body" id="cnConfirmBody"></div>
            <div class="cn-modal-foot">
                <button type="button" class="cn-btn-ghost" data-cn-confirm-huy>Huỷ</button>
                <button type="button" class="cn-btn-primary" id="cnConfirmOk">Xoá</button>
            </div>
        </div>
    </div>

    <style>
        .cn {
            /* Phá padding p-4 của <main> để tràn viền như các trang danh sách khác */
            margin: -1.5rem;
            min-height: calc(100vh - 56px);
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            color: #262626; background: #fff;
        }

        .cn-head { display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; padding: 14px 20px; }
        .cn-title { margin: 0; font-size: 16px; font-weight: 700; color: #262626; line-height: 34px; }
        .cn-sum { font-size: 13px; color: #595959; }
        .cn-sum b { color: #262626; }

        .cn-callout {
            display: flex; align-items: flex-start; gap: 8px;
            margin: 0 20px 12px; padding: 10px 12px; border-radius: 4px;
            background: #fff7e6; border: 1px solid #ffd591; color: #874d00; font-size: 13px;
        }
        .cn-callout.is-error { background: #fff1f0; border-color: #ffa39e; color: #a8071a; }

        /* Bộ lọc */
        .cn-filter { padding: 0 20px 12px; margin-bottom: 12px; border-bottom: 1px solid #eee; }
        .cn-toolbar { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; }
        .cn-toolbar-actions { margin-left: auto; display: flex; align-items: center; gap: 8px; }

        .cn-searchbox { display: flex; border-radius: 4px; }
        .cn-searchbox:focus-within { box-shadow: 0 0 0 4px rgba(13,110,253,.25); }
        .cn-search-input {
            height: 34px; width: 280px; border: 1px solid #d9d9d9; border-right: 0;
            border-radius: 4px 0 0 4px; background: #fff; padding: 0 12px; font-size: 13px; outline: none;
        }
        .cn-search-input::placeholder { color: #bfbfbf; }
        /* Vòng focus vẽ ở .cn-searchbox, ô trong không vẽ lại (luật chung ở layout). */
        .cn-searchbox:focus-within .cn-search-input,
        .cn-searchbox:focus-within .cn-search-btn { border-color: #86b7fe; }
        .cn-search-input:focus { box-shadow: none !important; }
        .cn-search-btn {
            height: 34px; width: 40px; display: inline-flex; align-items: center; justify-content: center;
            border: 1px solid #d9d9d9; border-radius: 0 4px 4px 0; background: #fafafa; color: #595959; cursor: pointer;
        }
        .cn-search-btn:hover { color: #1890ff; }

        .cn-select {
            height: 34px; border: 1px solid #d9d9d9; border-radius: 4px; background-color: #fff;
            padding: 0 30px 0 12px; font-size: 13px; color: #262626; cursor: pointer; outline: none; max-width: 220px;
            appearance: none; -webkit-appearance: none;
            background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23595959' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><path d='m6 9 6 6 6-6'/></svg>");
            background-repeat: no-repeat; background-position: right 8px center;
        }

        .cn-clear {
            display: inline-flex; align-items: center; height: 34px; padding: 0 10px; border-radius: 4px;
            font-size: 13px; color: #8c8c8c; text-decoration: none; transition: background .15s, color .15s;
        }
        .cn-clear:hover { background: #fff1f0; color: #ff4d4f; }

        .cn-cols { position: relative; }
        .cn-cols-panel {
            position: absolute; top: 40px; right: 0; z-index: 30; min-width: 210px;
            background: #fff; border: 1px solid #e6e6e6; border-radius: 6px;
            box-shadow: 0 6px 20px rgba(0,0,0,.12); padding: 8px;
        }
        .cn-cols-item {
            display: flex; align-items: center; gap: 8px; padding: 6px 8px; border-radius: 4px;
            font-size: 13px; color: #262626; cursor: pointer; user-select: none;
        }
        .cn-cols-item:hover { background: #fafafa; }
        .cn-cols-item.is-all { font-weight: 600; }
        .cn-cols-sep { height: 1px; background: #f0f0f0; margin: 4px 0; }

        /* Bảng — cách sắp xếp của v2 (mọi ô canh giữa), màu của bản hiện tại. */
        .cn-table-wrap { padding: 0 20px 24px; overflow-x: auto; }
        .cn-table { width: 100%; min-width: 1120px; border-collapse: collapse; font-size: 13px; table-layout: fixed; }
        .cn-table th {
            font-weight: 600; color: #595959; background: #fafafa;
            padding: 12px 8px; border-bottom: 1px solid #f0f0f0; white-space: nowrap;
        }
        .cn-table th, .cn-table td { text-align: center; vertical-align: middle; }
        .cn-table td { padding: 14px 8px; border-bottom: 1px solid #f5f5f5; }
        .cn-table tbody tr:hover td { background: #fafafa; }

        /* Bề rộng theo TỈ LỆ, tổng đúng 100% — bỏ trống một cột là bảng hở khoảng chết. */
        .cn-table th.cn-c-stt,     .cn-table td.cn-c-stt     { width: 4%; }
        .cn-table th.cn-c-code,    .cn-table td.cn-c-code    { width: 10%; }
        .cn-table th.cn-c-name,    .cn-table td.cn-c-name    { width: 18%; }
        .cn-table th.cn-c-tax,     .cn-table td.cn-c-tax     { width: 10%; }
        .cn-table th.cn-c-phone,   .cn-table td.cn-c-phone   { width: 10%; }
        .cn-table th.cn-c-etax,    .cn-table td.cn-c-etax    { width: 10%; }
        .cn-table th.cn-c-type,    .cn-table td.cn-c-type    { width: 10%; }
        .cn-table th.cn-c-created, .cn-table td.cn-c-created { width: 12%; }
        .cn-table th.cn-c-status,  .cn-table td.cn-c-status  { width: 6%; }
        .cn-table th.cn-c-act,     .cn-table td.cn-c-act     { width: 10%; }

        .cn-table .is-hidden { display: none; }

        .cn-name { font-weight: 600; }
        .cn-muted { color: #8c8c8c; }
        .cn-code { font-size: 12px; background: #f5f5f5; border-radius: 3px; padding: 2px 8px; color: #595959; }
        .cn-empty { text-align: center; color: #8c8c8c; padding: 32px 12px; }
        .cn-here {
            display: inline-block; margin-left: 6px; padding: 1px 8px; border-radius: 10px;
            font-size: 11px; font-weight: 500; white-space: nowrap;
            background: #e6f7ff; color: #1890ff; border: 1px solid #91d5ff;
        }
        /* Nút HĐĐT — màu cam của v2. Chưa nối thì để viền, đã nối mới tô đặc:
           nhìn lướt qua bảng là biết chi nhánh nào đã có hoá đơn điện tử. */
        .cn-btn-etax {
            height: 28px; padding: 0 14px; border: 1px solid #f29220; border-radius: 4px;
            background: #f29220; color: #fff; font-size: 12.5px; font-weight: 600; cursor: pointer;
        }
        .cn-btn-etax:hover { background: #d97d10; border-color: #d97d10; }
        .cn-btn-etax.is-chua { background: #fff; color: #d97d10; }
        .cn-btn-etax.is-chua:hover { background: #fff7e6; }

        /* Nút thao tác: ô vuông bo góc có viền, lúc thường xám, rê chuột mới ăn màu. */
        .cn-rowacts { display: flex; align-items: center; justify-content: center; gap: 6px; }
        .cn-inline-form { display: inline; margin: 0; }
        .cn-rowbtn {
            width: 32px; height: 32px; padding: 0;
            border: 1px solid #d9d9d9; border-radius: 6px;
            background: #fff; color: #595959; cursor: pointer;
            display: inline-flex; align-items: center; justify-content: center;
            transition: border-color .15s, background .15s, color .15s;
        }
        .cn-rowbtn:hover { border-color: #bfbfbf; background: #fafafa; }
        .cn-rowbtn.cn-view:hover,
        .cn-rowbtn.cn-edit:hover { border-color: #91d5ff; background: #e6f7ff; color: #1890ff; }
        .cn-rowbtn.cn-del:hover { border-color: #ffa39e; background: #fff1f0; color: #ff4d4f; }

        .cn-btn-primary {
            display: inline-flex; align-items: center; gap: 6px;
            height: 34px; padding: 0 14px; border-radius: 4px; border: 0;
            background: #0d6efd; color: #fff; font-size: 13px; font-weight: 600; cursor: pointer;
        }
        .cn-btn-primary:hover { background: #0b5ed7; }
        .cn-btn-primary:disabled { opacity: .55; cursor: default; }
        .cn-btn-ghost {
            display: inline-flex; align-items: center; gap: 6px;
            height: 34px; padding: 0 14px; border-radius: 4px; text-decoration: none;
            border: 1px solid #d9d9d9; background: #fff; color: #595959; font-size: 13px; cursor: pointer;
            white-space: nowrap;
        }
        .cn-btn-ghost:hover { color: #0d6efd; border-color: #0d6efd; }

        /* Công tắc — dáng của v2, đổi màu bật. */
        .cn-switch {
            appearance: none; -webkit-appearance: none;
            width: 2.6em; height: 1.4em; margin: 0;
            background: #dcdcdc; border: 0; border-radius: 3em;
            position: relative; cursor: pointer; outline: none;
            transition: background .2s ease-in-out; flex-shrink: 0;
        }
        .cn-switch:checked { background: #0d6efd; }
        .cn-switch::after {
            content: ''; position: absolute; left: 0; top: 0;
            width: 1.4em; height: 1.4em; border-radius: 50%;
            background: #fff; box-shadow: 0 0 .25em rgba(0,0,0,.3);
            transform: scale(.72); transition: left .2s ease-in-out;
        }
        .cn-switch:checked::after { left: calc(100% - 1.4em); }
        .cn-switch:disabled { opacity: .5; cursor: not-allowed; }
        .cn-switch-row { display: inline-flex; align-items: center; gap: 10px; cursor: pointer; margin: 0; }
        .cn-switch-label { font-size: 13px; color: #262626; }
        .cn-switch-form { display: inline-flex; margin: 0; }

        /* ----- Hộp thoại ----- */
        .cn-overlay { position: fixed; inset: 0; background: rgba(0,0,0,.45); z-index: 1050; display: flex; align-items: center; justify-content: center; padding: 16px; }
        .cn-dialog {
            width: 100%; max-width: 1120px; max-height: calc(100vh - 32px);
            display: flex; flex-direction: column; background: #fff; border-radius: 8px;
            box-shadow: 0 12px 40px rgba(0,0,0,.22);
        }
        .cn-dialog.is-view { max-width: 960px; }
        .cn-dialog.is-etax { max-width: 620px; }
        .cn-dialog.is-etax .cn-modal-body { display: flex; flex-direction: column; gap: 14px; }
        .cn-dialog.is-etax form { gap: 14px; }
        .cn-modal-phu { font-weight: 500; color: #8c8c8c; }
        .cn-etax-tai { color: #8c8c8c; padding: 12px 0; }
        .cn-etax-dan { color: #595959 !important; }
        .cn-etax-info { margin-bottom: 2px; }
        .cn-select-rong { width: 100%; max-width: none; height: 36px; }
        .cn-etax-phu { display: flex; align-items: center; gap: 14px; padding-top: 12px; border-top: 1px solid #f0f0f0; }
        .cn-dialog.is-confirm { max-width: 460px; }
        .cn-dialog form { display: flex; flex-direction: column; min-height: 0; }
        .cn-modal-head { display: flex; align-items: center; justify-content: space-between; padding: 15px 22px; border-bottom: 1px solid #f0f0f0; }
        .cn-modal-title { margin: 0; font-size: 15px; font-weight: 700; }
        .cn-modal-x { border: 0; background: none; color: #8c8c8c; cursor: pointer; line-height: 0; }
        .cn-modal-x:hover { color: #262626; }
        .cn-modal-body { padding: 18px 22px 22px; overflow-y: auto; }
        .cn-modal-body p { margin: 0; font-size: 13px; color: #595959; }
        #cnConfirmBody { display: flex; flex-direction: column; gap: 10px; padding: 18px 22px; }
        /* Hàng nút ở chân hộp thoại luôn CANH GIỮA — quy tắc chung của dự án. */
        .cn-modal-foot { display: flex; justify-content: center; gap: 8px; padding: 12px 22px; border-top: 1px solid #f0f0f0; background: #fafafa; border-radius: 0 0 8px 8px; }

        /* Bốn cột dọc, mỗi cột một nhóm việc, có vạch ngăn. */
        .cn-cols-form { display: grid; grid-template-columns: 176px repeat(3, 1fr); }
        .cn-col { display: flex; flex-direction: column; gap: 13px; padding: 0 20px; min-width: 0; }
        .cn-col:first-child { padding-left: 0; }
        .cn-col:last-child { padding-right: 0; }
        .cn-col + .cn-col { border-left: 1px solid #f0f0f0; }
        .cn-nhom {
            margin: 0 0 3px !important; padding-bottom: 8px; border-bottom: 1px solid #f0f0f0;
            font-size: 11.5px; font-weight: 700; letter-spacing: .05em; text-transform: uppercase;
            color: #8c8c8c !important;
        }
        .cn-row2 { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
        /* Toạ độ rộng, bán kính hẹp — hai ô này luôn đi cùng nhau. */
        .cn-row-viTri { display: grid; grid-template-columns: 1fr 92px; gap: 10px; }
        .cn-hint-keo { margin-top: -8px !important; }
        .cn-doc-them { display: grid; grid-template-columns: 1fr; gap: 13px; margin-top: 3px; padding-top: 14px; border-top: 1px solid #f0f0f0; }

        /* Cột nhận diện: logo + công tắc trạng thái. */
        .cn-col-anh { align-items: stretch; }
        .cn-anh-khung {
            height: 116px; border-radius: 6px; overflow: hidden;
            border: 1px dashed #d9d9d9; background: #fafafa;
            display: flex; align-items: center; justify-content: center;
        }
        .cn-anh-khung img { max-width: 100%; max-height: 100%; object-fit: contain; }
        .cn-anh-trong { color: #bfbfbf; font-size: 12px; display: inline-flex; align-items: center; }
        .cn-anh-nut { display: flex; flex-direction: column; align-items: center; gap: 6px; margin-top: -4px; }
        .cn-btn-nho {
            display: inline-block; margin: 0; padding: 6px 14px; border-radius: 4px;
            border: 1px solid #d9d9d9; background: #fff;
            font-size: 12.5px; color: #434343; cursor: pointer;
        }
        .cn-btn-nho:hover { color: #0d6efd; border-color: #0d6efd; }
        .cn-link-nho { border: 0; background: none; padding: 0; font-size: 12px; color: #8c8c8c; cursor: pointer; }
        .cn-link-nho:hover { color: #ff4d4f; }
        .cn-tt-giua { text-align: center; }
        .cn-anh-tt { margin-top: auto; padding-top: 14px; border-top: 1px solid #f0f0f0; }

        .cn-field-label { display: block; font-size: 12.5px; font-weight: 600; color: #434343; margin-bottom: 5px; }
        .cn-req { color: #ff4d4f; }
        .cn-help { color: #bfbfbf; }
        .cn-help:hover { color: #0d6efd; }
        .cn-input {
            width: 100%; height: 36px; border: 1px solid #d9d9d9; border-radius: 4px;
            padding: 0 12px; font-size: 13px; outline: none; background-color: #fff; color: #262626;
            transition: border-color .15s, box-shadow .15s;
        }
        .cn-input:focus { border-color: #86b7fe; box-shadow: 0 0 0 3px rgba(13,110,253,.12); }
        .cn-input:disabled { background: #f7f7f7; color: #8c8c8c; }
        .cn-input::placeholder { color: #c8c8c8; }
        /* Ô số kèm đơn vị: chữ "m" nằm trong khung, không phải một ô riêng. */
        .cn-input-don { position: relative; }
        .cn-input-don .cn-input { padding-right: 28px; }
        .cn-input-don span { position: absolute; right: 10px; top: 9px; font-size: 12.5px; color: #8c8c8c; }
        .cn-hint { margin: 4px 0 0 !important; font-size: 11.5px; color: #8c8c8c !important; }
        .cn-hint a { color: #0d6efd; }
        .cn-hint code { background: #f5f5f5; border-radius: 3px; padding: 0 4px; color: #595959; }
        .cn-radios { display: flex; align-items: center; gap: 20px; height: 36px; }
        .cn-radio { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; margin: 0; cursor: pointer; }
        .cn-textarea {
            width: 100%; border: 1px solid #d9d9d9; border-radius: 4px;
            padding: 8px 12px; font-size: 13px; outline: none; resize: vertical;
            background-color: #fff; color: #262626; font-family: inherit;
            transition: border-color .15s, box-shadow .15s;
        }
        .cn-textarea:focus { border-color: #86b7fe; box-shadow: 0 0 0 3px rgba(13,110,253,.12); }
        .cn-textarea::placeholder { color: #c8c8c8; }
        .cn-dem-so { display: block; text-align: right; margin-top: 2px; font-size: 11.5px; color: #bfbfbf; }

        /* Hộp xem chi tiết */
        .cn-view-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 22px; }
        .cn-info { display: flex; flex-direction: column; }
        .cn-info-row { display: flex; gap: 10px; padding: 6px 0; font-size: 13px; border-bottom: 1px solid #f5f5f5; }
        .cn-info-row label { flex: 0 0 45%; margin: 0; font-weight: 600; color: #595959; }
        .cn-info-row span { flex: 1; color: #262626; word-break: break-word; }
        .cn-info-row a { color: #0d6efd; }
        .cn-vanh {
            height: 100px; margin-bottom: 12px; border: 1px dashed #d9d9d9; border-radius: 6px; background: #fafafa;
            display: flex; align-items: center; justify-content: center; overflow: hidden;
        }
        .cn-vanh img { max-width: 100%; max-height: 100%; object-fit: contain; }
        .cn-vpre {
            white-space: pre-wrap; word-break: break-word; min-height: 2.4em;
            border: 1px solid #f0f0f0; border-radius: 4px; background: #fafafa;
            padding: 7px 10px; margin-bottom: 12px; font-size: 12.5px; color: #262626;
        }
        .cn-vpre:last-child { margin-bottom: 0; }

        @media (max-width: 1120px) {
            .cn-cols-form { grid-template-columns: 176px 1fr 1fr; }
            .cn-col:nth-child(4) { grid-column: 2 / -1; border-left: 0; padding: 14px 0 0; margin-top: 4px; border-top: 1px solid #f0f0f0; }
            .cn-col:nth-child(4) .cn-doc-them { grid-template-columns: 1fr 1fr; }
            .cn-view-grid { grid-template-columns: 1fr 1fr; }
        }
        @media (max-width: 760px) {
            .cn-cols-form, .cn-row2, .cn-row-viTri, .cn-view-grid { grid-template-columns: 1fr; }
            .cn-col, .cn-col:first-child, .cn-col:last-child { padding: 0; }
            .cn-col + .cn-col { border-left: 0; border-top: 1px solid #f0f0f0; padding-top: 14px; }
            .cn-col:nth-child(4) { grid-column: auto; }
        }
    </style>

    <script>
        (function () {
            // ---------- Lọc realtime ----------
            var filter = document.getElementById('cnFilter');
            filter.querySelectorAll('select').forEach(function (sel) {
                sel.addEventListener('change', function () { filter.submit(); });
            });
            var search = filter.querySelector('input[name="keyword"]');
            var searchTimer = null;
            search.addEventListener('input', function () {
                clearTimeout(searchTimer);
                searchTimer = setTimeout(function () { filter.submit(); }, 400);
            });

            // ---------- Ẩn/hiện cột ----------
            var KHOA_COT = 'cn.cols.v2';
            var colsBox = document.getElementById('cnCols');
            var colsBtn = document.getElementById('cnColsBtn');
            var colsPanel = document.getElementById('cnColsPanel');
            var colsAll = document.getElementById('cnColsAll');
            var colsOne = Array.prototype.slice.call(document.querySelectorAll('.cn-cols-one'));

            function veCot() {
                var an = {};
                colsOne.forEach(function (c) {
                    an[c.dataset.col] = !c.checked;
                    document.querySelectorAll('.cn-c-' + c.dataset.col).forEach(function (el) {
                        el.classList.toggle('is-hidden', !c.checked);
                    });
                });
                colsAll.checked = colsOne.every(function (c) { return c.checked; });
                try { localStorage.setItem(KHOA_COT, JSON.stringify(an)); } catch (e) {}
            }

            // localStorage bị chặn hoặc dữ liệu rác thì bỏ qua và hiện đủ cột.
            try {
                var daLuu = JSON.parse(localStorage.getItem(KHOA_COT) || '{}');
                colsOne.forEach(function (c) {
                    if (daLuu[c.dataset.col] === true) c.checked = false;
                });
            } catch (e) {}
            veCot();

            colsBtn.addEventListener('click', function () { colsPanel.hidden = !colsPanel.hidden; });
            colsOne.forEach(function (c) { c.addEventListener('change', veCot); });
            colsAll.addEventListener('change', function () {
                colsOne.forEach(function (c) { c.checked = colsAll.checked; });
                veCot();
            });
            document.addEventListener('click', function (e) {
                if (!colsBox.contains(e.target)) colsPanel.hidden = true;
            });

            // ---------- Xuất file ----------
            //
            // Dựng CSV tại trình duyệt từ đúng những cột ĐANG HIỆN. BOM UTF-8 ở đầu:
            // thiếu nó thì Excel trên Windows mở ra tiếng Việt thành ký tự rác.
            document.getElementById('cnExport').addEventListener('click', function () {
                var dong = [];

                document.getElementById('cnTable').querySelectorAll('tr').forEach(function (tr) {
                    var hang = [];
                    tr.querySelectorAll('th, td').forEach(function (td) {
                        if (td.classList.contains('is-hidden') || td.classList.contains('cn-no-export')) return;
                        var sw = td.querySelector('.cn-switch');
                        var rieng = td.querySelector('[data-xuat]');
                        if (sw) {
                            hang.push(sw.checked ? 'Đang mở' : 'Đã đóng');
                        } else if (rieng) {
                            hang.push(rieng.dataset.xuat);
                        } else {
                            hang.push(td.textContent.trim().replace(/\s+/g, ' '));
                        }
                    });
                    if (hang.length) dong.push(hang);
                });

                var csv = dong.map(function (hang) {
                    return hang.map(function (val) { return '"' + String(val).replace(/"/g, '""') + '"'; }).join(',');
                }).join('\r\n');

                var a = document.createElement('a');
                a.href = URL.createObjectURL(new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8;' }));
                a.download = 'danh-sach-chi-nhanh.csv';
                a.click();
                URL.revokeObjectURL(a.href);
            });

            // ---------- Hộp xác nhận ----------
            //
            // Thay confirm() của trình duyệt: đặt được chữ trên từng nút. Bất đồng bộ
            // nên nơi gọi đưa việc cần làm vào `xong`.
            var hopHoi = document.getElementById('cnConfirm');
            var hopHoiTieuDe = document.getElementById('cnConfirmTitle');
            var hopHoiNoiDung = document.getElementById('cnConfirmBody');
            var hopHoiOk = document.getElementById('cnConfirmOk');
            var traLoi = null;

            function dongHoi(dongY) {
                var xong = traLoi;
                traLoi = null;
                hopHoi.style.display = 'none';
                if (xong) xong(dongY);
            }

            function hoi(cauHoi, xong) {
                traLoi = xong;
                hopHoiTieuDe.textContent = cauHoi.tieuDe;

                // textContent từng đoạn: tên chi nhánh là chữ người dùng gõ.
                hopHoiNoiDung.innerHTML = '';
                (cauHoi.doan || []).forEach(function (d) {
                    var p = document.createElement('p');
                    p.textContent = d;
                    hopHoiNoiDung.appendChild(p);
                });

                hopHoi.style.display = 'flex';
                hopHoiOk.focus();
            }

            hopHoiOk.addEventListener('click', function () { dongHoi(true); });
            document.querySelectorAll('[data-cn-confirm-huy]').forEach(function (el) {
                el.addEventListener('click', function () { dongHoi(false); });
            });
            hopHoi.addEventListener('click', function (e) { if (e.target === hopHoi) dongHoi(false); });

            // ---------- Tiện ích chung ----------
            var LOAI = @json(\App\Http\Controllers\ChiNhanhController::LOAI);

            function chu(v) { return (v === null || v === undefined || v === '') ? '—' : String(v); }

            function gioNgay(s) {
                if (!s) return '—';
                var d = new Date(s);
                if (isNaN(d.getTime())) return '—';
                return d.toLocaleString('vi-VN', {
                    hour: '2-digit', minute: '2-digit', second: '2-digit',
                    day: '2-digit', month: '2-digit', year: 'numeric',
                });
            }

            // ---------- Hộp xem chi tiết ----------
            var xem = document.getElementById('cnViewOverlay');
            var v = {
                ma: document.getElementById('cnVMa'),
                ten: document.getElementById('cnVTen'),
                tenGD: document.getElementById('cnVTenGD'),
                mst: document.getElementById('cnVMST'),
                loai: document.getElementById('cnVLoai'),
                trangThai: document.getElementById('cnVTrangThai'),
                nguoiTao: document.getElementById('cnVNguoiTao'),
                ngayTao: document.getElementById('cnVNgayTao'),
                diaChi: document.getElementById('cnVDiaChi'),
                thanhPho: document.getElementById('cnVThanhPho'),
                quocGia: document.getElementById('cnVQuocGia'),
                viTri: document.getElementById('cnVViTri'),
                phamVi: document.getElementById('cnVPhamVi'),
                dienThoai: document.getElementById('cnVDienThoai'),
                email: document.getElementById('cnVEmail'),
                link: document.getElementById('cnVLink'),
                anh: document.getElementById('cnVAnh'),
                anhTrong: document.getElementById('cnVAnhTrong'),
                header: document.getElementById('cnVHeader'),
                wifi: document.getElementById('cnVWifi'),
                footer: document.getElementById('cnVFooter'),
            };

            document.querySelectorAll('[data-cn-view]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var cn = JSON.parse(btn.dataset.cn);
                    v.ma.textContent = chu(cn.code);
                    v.ten.textContent = chu(cn.name);
                    v.tenGD.textContent = chu(cn.transaction_name);
                    v.mst.textContent = chu(cn.tax_code);
                    v.loai.textContent = LOAI[cn.branch_type] || '—';
                    v.trangThai.textContent = cn.is_active ? 'Đang mở' : 'Đã đóng';
                    v.nguoiTao.textContent = chu(cn.created_by_name);
                    v.ngayTao.textContent = gioNgay(cn.created_at);

                    v.diaChi.textContent = chu(cn.address);
                    v.thanhPho.textContent = chu(cn.city);
                    v.quocGia.textContent = chu(cn.country);
                    v.viTri.textContent = chu(cn.location);
                    v.phamVi.textContent = cn.area_scope ? cn.area_scope + ' m' : '—';
                    v.dienThoai.textContent = chu(cn.phone);
                    v.email.textContent = chu(cn.email);

                    // Link do người dùng gõ: dựng bằng createElement, và chỉ mở
                    // http/https — "javascript:..." dán vào đây là một cú bấm chạy mã.
                    v.link.innerHTML = '';
                    var duong = String(cn.access_link || '');
                    if (!duong) {
                        v.link.textContent = '—';
                    } else if (/^https?:\/\//i.test(duong)) {
                        var a = document.createElement('a');
                        a.href = duong;
                        a.target = '_blank';
                        a.rel = 'noopener';
                        a.textContent = duong;
                        v.link.appendChild(a);
                    } else {
                        v.link.textContent = duong;
                    }

                    var coAnh = !!cn.image;
                    v.anh.style.display = coAnh ? '' : 'none';
                    v.anhTrong.style.display = coAnh ? 'none' : '';
                    if (coAnh) v.anh.src = cn.image;

                    v.header.textContent = cn.header_invoice_info || '—';
                    v.wifi.textContent = cn.wifi_invoice_info || '—';
                    v.footer.textContent = cn.footer_invoice_info || '—';

                    xem.style.display = 'flex';
                });
            });

            function dongXem() { xem.style.display = 'none'; }
            document.querySelectorAll('[data-cn-view-close]').forEach(function (el) {
                el.addEventListener('click', dongXem);
            });
            xem.addEventListener('click', function (e) { if (e.target === xem) dongXem(); });

            // ---------- Hộp thêm / sửa ----------
            var overlay = document.getElementById('cnOverlay');
            var form = document.getElementById('cnForm');
            var method = document.getElementById('cnMethod');
            var title = document.getElementById('cnModalTitle');
            var submit = document.getElementById('cnSubmit');
            var maHint = document.getElementById('cnMaHint');

            var o = {
                id: document.getElementById('cnId'),
                ma: document.getElementById('cnMa'),
                ten: document.getElementById('cnTen'),
                tenGD: document.getElementById('cnTenGD'),
                mst: document.getElementById('cnMST'),
                diaChi: document.getElementById('cnDiaChi'),
                thanhPho: document.getElementById('cnThanhPho'),
                quocGia: document.getElementById('cnQuocGia'),
                viTri: document.getElementById('cnViTri'),
                phamVi: document.getElementById('cnPhamVi'),
                dienThoai: document.getElementById('cnDienThoai'),
                email: document.getElementById('cnEmail'),
                link: document.getElementById('cnLink'),
                header: document.getElementById('cnHeader'),
                wifi: document.getElementById('cnWifi'),
                footer: document.getElementById('cnFooter'),
                nguoiTao: document.getElementById('cnNguoiTao'),
                ngayTao: document.getElementById('cnNgayTao'),
                bat: document.getElementById('cnTrangThai'),
                batChu: document.getElementById('cnTrangThaiChu'),
                batValue: document.getElementById('cnTrangThaiValue'),
            };

            var TOI = @json($toiLa);
            var STORE = @json(route('admin.chi-nhanh.store'));
            // Khuôn đường sửa: thay 0 bằng id thật.
            var UPDATE = @json(route('admin.chi-nhanh.update', 0));

            function veCongTac() {
                o.batValue.value = o.bat.checked ? '1' : '0';
                o.batChu.textContent = o.bat.checked ? 'Đang mở' : 'Đã đóng';
            }
            o.bat.addEventListener('change', veCongTac);

            // ----- Đếm ký tự ba khối chữ hoá đơn -----
            function demChu(ta) {
                var oSo = ta.parentElement.querySelector('.cn-dem-so span');
                if (oSo) oSo.textContent = String(ta.value.length);
            }
            document.querySelectorAll('.cn-dem').forEach(function (ta) {
                ta.addEventListener('input', function () { demChu(ta); });
            });
            function demHet() { document.querySelectorAll('.cn-dem').forEach(demChu); }

            // ----- Logo -----
            var anhGiaTri = document.getElementById('cnAnh');
            var anhFile = document.getElementById('cnAnhFile');
            var anhNut = document.getElementById('cnAnhNut');
            var anhNutChu = document.getElementById('cnAnhNutChu');
            var anhGo = document.getElementById('cnAnhGo');
            var anhXem = document.getElementById('cnAnhPreview');
            var anhChu = document.getElementById('cnAnhChu');
            var anhChuGoc = anhChu.innerHTML;
            var DUONG_ANH = @json(route('admin.chi-nhanh.anh'));

            function veAnh(duong) {
                anhGiaTri.value = duong || '';
                var co = !!duong;
                anhXem.style.display = co ? '' : 'none';
                anhChu.style.display = co ? 'none' : '';
                anhChu.innerHTML = anhChuGoc;
                anhGo.style.display = co ? '' : 'none';
                anhNutChu.textContent = co ? 'Đổi ảnh' : 'Chọn ảnh';
                if (co) anhXem.src = duong;
            }

            anhNut.addEventListener('click', function () { anhFile.click(); });
            anhGo.addEventListener('click', function () { anhFile.value = ''; veAnh(''); });

            anhFile.addEventListener('change', function () {
                var tep = anhFile.files && anhFile.files[0];
                if (!tep) return;

                anhNutChu.textContent = 'Đang tải…';
                var du = new FormData();
                du.append('image', tep);
                du.append('_token', form.querySelector('input[name="_token"]').value);

                fetch(DUONG_ANH, { method: 'POST', body: du })
                    .then(function (r) { return r.ok ? r.json() : r.json().then(function (j) { throw j; }); })
                    .then(function (j) { veAnh(j.url); })
                    .catch(function (j) {
                        // Nói ngay tại ô ảnh: ném người dùng về đầu trang là họ mất mọi
                        // ô vừa gõ trong hộp thoại.
                        anhChu.textContent = (j && j.errors && j.errors.image && j.errors.image[0])
                            || 'Không tải được ảnh';
                        anhChu.style.display = '';
                        anhNutChu.textContent = 'Chọn lại';
                    });
            });

            // id > 0 = lượt SỬA: đường gửi và _method suy ra từ chính nó.
            function moForm(d) {
                var id = Number(d.id || 0);
                title.textContent = d.tieuDe;
                form.action = id > 0 ? UPDATE.replace(/0$/, String(id)) : STORE;
                method.value = id > 0 ? 'PUT' : '';
                o.id.value = id > 0 ? String(id) : '';

                o.ma.value = d.code || '';
                // Bỏ trống ô mã khi SỬA = giữ nguyên mã cũ, nên điền sẵn mã hiện tại.
                o.ma.placeholder = id > 0 ? '' : 'Bỏ trống để hệ thống tự đặt';
                maHint.style.display = id > 0 ? 'none' : '';

                o.ten.value = d.name || '';
                o.tenGD.value = d.transaction_name || '';
                o.mst.value = d.tax_code || '';
                o.diaChi.value = d.address || '';
                o.thanhPho.value = d.city || '';
                o.quocGia.value = d.country || '';
                o.viTri.value = d.location || '';
                o.phamVi.value = d.area_scope || '';
                o.dienThoai.value = d.phone || '';
                o.email.value = d.email || '';
                o.link.value = d.access_link || '';
                o.header.value = d.header_invoice_info || '';
                o.wifi.value = d.wifi_invoice_info || '';
                o.footer.value = d.footer_invoice_info || '';

                var loai = Number(d.branch_type || 1);
                var radio = form.querySelector('input[name="branch_type"][value="' + loai + '"]');
                if (radio) radio.checked = true;

                o.nguoiTao.value = id > 0 ? (d.created_by_name || '—') : TOI;
                o.ngayTao.value = id > 0 ? gioNgay(d.created_at) : '';

                o.bat.checked = d.isActive !== false;
                veCongTac();
                veAnh(d.image || '');
                demHet();

                submit.disabled = false;
                overlay.style.display = 'flex';
                (d.oDau || o.ten).focus();
            }

            function dongForm() { overlay.style.display = 'none'; }

            document.querySelector('[data-cn-add]').addEventListener('click', function () {
                moForm({ tieuDe: 'Thêm chi nhánh' });
            });

            document.querySelectorAll('[data-cn-edit]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var cn = JSON.parse(btn.dataset.cn);
                    cn.tieuDe = 'Sửa chi nhánh';
                    cn.isActive = !!cn.is_active;
                    moForm(cn);
                });
            });

            document.querySelectorAll('#cnOverlay [data-close]').forEach(function (el) {
                el.addEventListener('click', dongForm);
            });
            overlay.addEventListener('click', function (e) { if (e.target === overlay) dongForm(); });
            document.addEventListener('keydown', function (e) {
                if (e.key !== 'Escape') return;
                if (hopHoi.style.display === 'flex') { dongHoi(false); return; }
                if (etaxHop.style.display === 'flex') { etaxDong(); return; }
                if (xem.style.display === 'flex') { dongXem(); return; }
                if (overlay.style.display === 'flex') dongForm();
            });

            // Khoá nút Lưu khi gửi: bấm hai lần lúc mạng chậm là hai chi nhánh trùng tên.
            form.addEventListener('submit', function () { submit.disabled = true; });

            @if($moLaiForm)
                moForm({
                    tieuDe: @json((int) old('cn_id', 0) > 0 ? 'Sửa chi nhánh' : 'Thêm chi nhánh'),
                    id: @json((int) old('cn_id', 0)),
                    code: @json(old('code', '')),
                    name: @json(old('name', '')),
                    transaction_name: @json(old('transaction_name', '')),
                    tax_code: @json(old('tax_code', '')),
                    address: @json(old('address', '')),
                    city: @json(old('city', '')),
                    country: @json(old('country', '')),
                    location: @json(old('location', '')),
                    area_scope: @json(old('area_scope', '')),
                    phone: @json(old('phone', '')),
                    email: @json(old('email', '')),
                    access_link: @json(old('access_link', '')),
                    branch_type: @json((int) old('branch_type', 1)),
                    image: @json(old('image', '')),
                    header_invoice_info: @json(old('header_invoice_info', '')),
                    wifi_invoice_info: @json(old('wifi_invoice_info', '')),
                    footer_invoice_info: @json(old('footer_invoice_info', '')),
                    isActive: @json((bool) old('is_active', 1)),
                });
            @endif

            // ---------- Hoá đơn điện tử ----------
            //
            // Một hộp thoại, hai mặt: chưa nối thì bày form khai tài khoản, đã nối
            // thì bày cài đặt phát hành. Đọc trạng thái bằng fetch chứ không nhét
            // sẵn vào bảng — mật khẩu và token không được đi qua HTML.
            var etaxHop = document.getElementById('cnEtaxOverlay');
            var etaxTen = document.getElementById('cnEtaxTenCN');
            var etaxTai = document.getElementById('cnEtaxTai');
            var etaxFormNoi = document.getElementById('cnEtaxFormNoi');
            var etaxFormLuu = document.getElementById('cnEtaxFormLuu');
            var etaxNutNoi = document.getElementById('cnEtaxNoi');
            var etaxNutLuu = document.getElementById('cnEtaxLuu');
            var etaxFormDongBo = document.getElementById('cnEtaxFormDongBo');
            var etaxFormNgat = document.getElementById('cnEtaxFormNgat');
            var etaxKyHieu = document.getElementById('cnEtaxKyHieu');

            var NHA_CC = @json(\App\Http\Controllers\ChiNhanhController::NHA_CUNG_CAP_ETAX);
            // MỘT khuôn đường cho cả nhóm: xem / kết nối / lưu / ngắt dùng chung
            // đúng một địa chỉ, chỉ khác động từ HTTP; đồng bộ mẫu là địa chỉ đó
            // cộng "/sync". Dựng bằng route() để prefix nhóm đổi thì vẫn đúng.
            var ETAX_KHUON = @json(route('admin.chi-nhanh.etax', 0));

            function etaxDuong(id, duoi) {
                return ETAX_KHUON.replace(/\/0\/etax$/, '/' + id + '/etax') + (duoi || '');
            }

            // Mặt nào cũng ẩn hết trước, rồi bật đúng một cái: thiếu bước này thì
            // hộp thoại giữ lại mặt của chi nhánh vừa xem.
            function etaxDonMat() {
                etaxTai.hidden = true;
                etaxFormNoi.hidden = true;
                etaxFormLuu.hidden = true;
                etaxNutNoi.hidden = true;
                etaxNutLuu.hidden = true;
            }

            function etaxMoMatNoi(id) {
                etaxDonMat();
                etaxFormNoi.action = etaxDuong(id);
                etaxFormNoi.reset();
                document.getElementById('cnEtaxDVCS').value = 'VP';
                etaxFormNoi.hidden = false;
                etaxNutNoi.hidden = false;
                etaxNutNoi.disabled = false;
                document.getElementById('cnEtaxMST').focus();
            }

            function etaxMoMatLuu(id, d) {
                etaxDonMat();
                etaxFormLuu.action = etaxDuong(id);
                etaxFormDongBo.action = etaxDuong(id, '/sync');
                etaxFormNgat.action = etaxDuong(id);

                document.getElementById('cnEtaxNhaCC').textContent = NHA_CC[d.provider] || d.provider || '—';
                document.getElementById('cnEtaxVMST').textContent = chu(d.tax_code);
                document.getElementById('cnEtaxVUser').textContent = chu(d.username);
                document.getElementById('cnEtaxVGio').textContent = gioNgay(d.token_synced_at);

                // Dựng ô chọn bằng Option(): ký hiệu và tên loại là chữ của nhà cung
                // cấp, nối thẳng vào innerHTML là mở cửa cho thẻ script.
                etaxKyHieu.innerHTML = '';
                var mau = d.templates || [];
                if (!mau.length) {
                    etaxKyHieu.appendChild(new Option('Chưa kéo được mẫu nào — bấm Đồng bộ mẫu', ''));
                } else {
                    etaxKyHieu.appendChild(new Option('— Chọn ký hiệu —', ''));
                    mau.forEach(function (m) {
                        var chuOption = m.symbol
                            + (m.form_no ? ' · Mẫu số ' + m.form_no : '')
                            + (m.type_name ? ' · ' + m.type_name : '');
                        etaxKyHieu.appendChild(new Option(chuOption, m.symbol));
                    });
                }
                etaxKyHieu.value = d.template_symbol || '';

                document.getElementById('cnEtaxTuPhat').checked = !!d.auto_release;
                document.getElementById('cnEtaxTuIn').checked = !!d.auto_print;

                etaxFormLuu.hidden = false;
                etaxNutLuu.hidden = false;
                etaxNutLuu.disabled = false;
            }

            document.querySelectorAll('[data-cn-etax]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var id = Number(btn.dataset.id);
                    etaxTen.textContent = btn.dataset.ten ? '— ' + btn.dataset.ten : '';
                    etaxDonMat();
                    etaxTai.hidden = false;
                    etaxTai.textContent = 'Đang đọc kết nối…';
                    etaxHop.style.display = 'flex';

                    fetch(etaxDuong(id), { headers: { Accept: 'application/json' } })
                        .then(function (r) { return r.json().then(function (j) { if (!r.ok) throw j; return j; }); })
                        .then(function (j) {
                            if (j.data) {
                                etaxMoMatLuu(id, j.data);
                            } else {
                                etaxMoMatNoi(id);
                            }
                        })
                        .catch(function (j) {
                            etaxDonMat();
                            etaxTai.hidden = false;
                            etaxTai.textContent = (j && j.message) || 'Không đọc được kết nối hoá đơn điện tử.';
                        });
                });
            });

            function etaxDong() { etaxHop.style.display = 'none'; }
            document.querySelectorAll('[data-cn-etax-close]').forEach(function (el) {
                el.addEventListener('click', etaxDong);
            });
            etaxHop.addEventListener('click', function (e) { if (e.target === etaxHop) etaxDong(); });

            // Khoá nút khi gửi: đăng nhập vào cổng HĐĐT mất vài giây, bấm lại lần
            // nữa là hai lượt đăng nhập chồng nhau.
            etaxFormNoi.addEventListener('submit', function () {
                etaxNutNoi.disabled = true;
                etaxNutNoi.textContent = 'Đang kết nối…';
            });
            etaxFormLuu.addEventListener('submit', function () { etaxNutLuu.disabled = true; });

            document.getElementById('cnEtaxDongBo').addEventListener('click', function () {
                this.disabled = true;
                this.textContent = 'Đang đồng bộ…';
                etaxFormDongBo.submit();
            });

            document.getElementById('cnEtaxNgat').addEventListener('click', function () {
                hoi({
                    tieuDe: 'Ngắt kết nối hoá đơn điện tử?',
                    doan: [
                        'Xoá hẳn tài khoản cổng hoá đơn khỏi chi nhánh này.',
                        'Hoá đơn đã phát hành vẫn nằm bên nhà cung cấp. Nối lại thì phải gõ lại mật khẩu.',
                    ],
                }, function (dongY) {
                    if (dongY) etaxFormNgat.submit();
                });
            });

            // ---------- Công tắc trạng thái trên bảng ----------
            document.querySelectorAll('[data-cn-status]').forEach(function (f) {
                var sw = f.querySelector('.cn-switch');
                if (sw.disabled) return;
                sw.addEventListener('change', function () {
                    sw.disabled = true;
                    f.submit();
                });
            });

            // ---------- Xoá một dòng ----------
            document.querySelectorAll('[data-cn-xoa]').forEach(function (f) {
                f.addEventListener('submit', function (e) {
                    e.preventDefault();
                    hoi({
                        tieuDe: 'Xoá chi nhánh?',
                        doan: [
                            'Xoá chi nhánh "' + f.dataset.ten + '".',
                            'Đơn hàng, phiếu kho và dữ liệu cũ phát sinh tại đây vẫn tra lại được. '
                                + 'Chỉ muốn ngừng bán ở điểm này thì ĐÓNG nó lại, đừng xoá.',
                        ],
                    }, function (dongY) {
                        if (dongY) f.submit();
                    });
                });
            });
        })();
    </script>
@endsection
