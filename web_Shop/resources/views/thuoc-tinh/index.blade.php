@extends('layouts.app')

@section('title', \App\Http\Controllers\ThuocTinhController::TITLE_PAGE)

@section('content')
    {{--
        Trang "Thuộc tính" — dựng theo màn Quản lý thuộc tính của bản cũ v2
        (menu/menu-attribute): [ header ] + [ thanh lọc ] + [ bảng ] + [ hộp thoại
        thêm/sửa gồm phần thuộc tính và phần giá trị con ].

        Giữ của bản cũ: cột chọn dòng, STT, mã, tên, chuỗi giá trị con, cờ định
        lượng NVL, công tắc trạng thái ngay trên dòng, ba nút Sửa / Nhân bản /
        Xoá, xoá nhiều dòng một lượt, và chặn xoá thứ đang được dùng.

        Làm khác bản cũ bốn chỗ:
        - Lọc realtime, không có nút kính lúp (quy tắc chung của trang danh sách).
        - Nút "Thêm thuộc tính" nằm CUỐI thanh lọc, không nằm cạnh tiêu đề.
        - Ô mã sửa được cả lúc sửa; bản cũ khoá cứng.
        - Xoá một giá trị chỉ bỏ dòng trên form, bấm Lưu mới ghi; bản cũ bắn AJAX
          xoá ngay lúc bấm dấu ×.
        - Mọi cột khai bề rộng theo %, tổng đúng 100% — không cột nào bỏ trống.
    --}}
    @php
        $C = \App\Http\Controllers\ThuocTinhController::class;
        $TITLE = $C::TITLE_PAGE;

        $so = fn ($n) => number_format((float) $n, 0, ',', '.');
        $hasFilter = $filters['keyword'] !== '' || $filters['status'] !== '' || $filters['raw_material'] !== '';

        // Bấm Lưu mà hỏng thì mở lại hộp thoại kèm dữ liệu vừa gõ.
        $moLaiForm = filled(old('name'));
    @endphp

    <div class="tt">
        {{-- Header: tiêu đề + một dòng tổng. Nút "Thêm" nằm cuối thanh lọc. --}}
        <div class="tt-head">
            <h1 class="tt-title">{{ $TITLE }}</h1>
            <span class="tt-sum">
                Đang dùng: <b>{{ $so($dangDung) }}</b>/{{ $so($tong) }} thuộc tính
            </span>
        </div>

        @if(!empty($error))
            <p class="tt-callout is-error">{{ $error }}</p>
        @endif

        {{-- Bộ lọc realtime: đổi select chạy ngay, gõ thì chờ 400ms. Không có nút
             "Lọc" — quy tắc chung của mọi trang danh sách trong dự án. --}}
        <form method="GET" action="{{ route('admin.thuoc-tinh.index') }}" id="ttFilter" class="tt-filter">
            <div class="tt-toolbar">
                <div class="tt-searchbox">
                    <input type="text" name="keyword" value="{{ $filters['keyword'] }}" class="tt-search-input"
                           placeholder="Tìm theo tên hoặc mã thuộc tính" autocomplete="off">
                    <button type="submit" class="tt-search-btn" title="Tìm kiếm">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                    </button>
                </div>

                <select name="status" class="tt-select" title="Lọc theo trạng thái">
                    <option value="">Tất cả trạng thái</option>
                    @foreach($C::TRANG_THAI as $ma => $ten)
                        <option value="{{ $ma }}" @selected($filters['status'] === $ma)>{{ $ten }}</option>
                    @endforeach
                </select>

                <select name="raw_material" class="tt-select" title="Lọc theo cờ định lượng nguyên vật liệu">
                    <option value="">Tất cả (định lượng NVL)</option>
                    @foreach($C::LOC_DINH_LUONG as $ma => $ten)
                        <option value="{{ $ma }}" @selected($filters['raw_material'] === $ma)>{{ $ten }}</option>
                    @endforeach
                </select>

                {{-- "Xoá lọc" ở hàng chính: một điều kiện đang ẩn mà không thấy đường
                     gỡ ra là cách nhanh nhất để tưởng danh sách mất dữ liệu. --}}
                @if($hasFilter)
                    <a href="{{ route('admin.thuoc-tinh.index') }}" class="tt-clear">Xoá lọc</a>
                @endif

                <div class="tt-toolbar-actions">
                    <button type="button" class="tt-btn-primary" data-tt-add>
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M12 5v14"/></svg>
                        Thêm thuộc tính
                    </button>
                </div>
            </div>
        </form>

        {{-- Form RỖNG, đứng ngoài bảng. Ô tick trong bảng nối vào nó bằng thuộc tính
             `form="ttBulkForm"` chứ KHÔNG bọc bảng lại: mỗi dòng đã có form riêng
             cho công tắc trạng thái và nút xoá, mà form lồng form thì HTML không
             cho — trình duyệt bỏ cái bên trong, và hai nút ấy chết. --}}
        <form method="POST" id="ttBulkForm" action="{{ route('admin.thuoc-tinh.bulkDestroy') }}">
            @csrf
        </form>

        {{-- Thanh hành động hàng loạt — chỉ hiện khi đã tick ít nhất một dòng. --}}
        <div class="tt-bulkbar" id="ttBulkBar" style="display:none;">
            <span class="tt-bulkbar__so">Đã chọn <b id="ttBulkSo">0</b> thuộc tính</span>
            <button type="button" class="tt-bulkbar__bo" id="ttBulkBo">Bỏ chọn</button>
            <button type="button" class="tt-btn-ghost is-danger" id="ttBulkXoa">
                <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M6 6v14a1 1 0 0 0 1 1h10a1 1 0 0 0 1-1V6"/><path d="M10 11v6M14 11v6"/></svg>
                Xoá
            </button>
        </div>

        {{-- Bảng --}}
        <div class="tt-table-wrap">
            <table class="tt-table">
                <thead>
                    <tr>
                        <th class="tt-c-tick">
                            {{-- Chọn hết những dòng ĐANG HIỆN, không phải cả cửa hàng:
                                 bảng đang lọc thì "hết" nghĩa là hết phần đang xem. --}}
                            <input type="checkbox" id="ttChonHet" title="Chọn hết dòng đang hiện">
                        </th>
                        <th class="tt-c-stt">STT</th>
                        <th class="tt-c-code">Mã thuộc tính</th>
                        <th class="tt-c-name">Tên thuộc tính</th>
                        <th class="tt-c-vals">Giá trị</th>
                        <th class="tt-c-raw">Định lượng NVL</th>
                        <th class="tt-c-status">Trạng thái</th>
                        <th class="tt-c-act">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($list as $i => $tt)
                        @php
                            $id = (int) ($tt['id'] ?? 0);
                            $bat = (bool) ($tt['is_active'] ?? false);
                            $dinhLuong = (bool) ($tt['raw_material'] ?? false);
                            $dangDungRow = (bool) ($tt['in_use'] ?? false);
                            $giaTri = $tt['values'] ?? [];
                            $hien = array_slice($giaTri, 0, $C::SO_GIA_TRI_HIEN);
                            $con = max(0, count($giaTri) - count($hien));
                        @endphp
                        <tr>
                            <td class="tt-c-tick">
                                <input type="checkbox" form="ttBulkForm" name="ids[]" value="{{ $id }}" data-tt-chon>
                            </td>
                            <td class="tt-c-stt tt-muted">{{ $stt + $i + 1 }}</td>
                            <td class="tt-c-code"><code class="tt-code">{{ $tt['code'] ?? '—' }}</code></td>
                            <td class="tt-c-name"><span class="tt-name">{{ $tt['name'] ?? '—' }}</span></td>
                            <td class="tt-c-vals">
                                {{-- Giá trị con bày thành chip. Nhiều quá thì cắt bớt và
                                     gom phần dư thành "+n" — một dòng bảng phải đọc lướt
                                     được, muốn xem đủ thì mở hộp thoại sửa. --}}
                                @if($hien)
                                    <span class="tt-chips">
                                        @foreach($hien as $gt)
                                            <span class="tt-chip">{{ $gt['name'] ?? '' }}</span>
                                        @endforeach
                                        @if($con > 0)
                                            <span class="tt-chip is-more" title="Còn {{ $con }} giá trị nữa">+{{ $con }}</span>
                                        @endif
                                    </span>
                                @else
                                    <span class="tt-muted">Chưa khai giá trị</span>
                                @endif
                            </td>
                            <td class="tt-c-raw">
                                @if($dinhLuong)
                                    <span class="tt-tag is-on">Có</span>
                                @else
                                    <span class="tt-tag">Không</span>
                                @endif
                            </td>
                            <td class="tt-c-status">
                                {{-- Công tắc: mỗi dòng một form riêng, gửi ĐÚNG một trường
                                     sang /status. JS hỏng thì vẫn còn nút submit. --}}
                                <form method="POST" action="{{ route('admin.thuoc-tinh.toggleStatus', $id) }}"
                                      class="tt-switch-form" data-tt-status>
                                    @csrf
                                    @method('PUT')
                                    <input type="hidden" name="is_active" value="{{ $bat ? 0 : 1 }}">
                                    <input type="checkbox" class="tt-switch" @checked($bat)
                                           title="{{ $bat ? 'Đang dùng — bấm để tắt' : 'Đã tắt — bấm để bật lại' }}">
                                    <noscript><button type="submit" class="tt-btn-ghost">Đổi</button></noscript>
                                </form>
                            </td>
                            <td class="tt-c-act">
                                <div class="tt-rowacts">
                                    <button type="button" class="tt-btn-icon" title="Sửa"
                                            data-tt-edit data-tt="{{ json_encode($tt, JSON_UNESCAPED_UNICODE) }}">
                                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                                    </button>

                                    {{-- Nhân bản: mở hộp thoại THÊM đã điền sẵn tên và cả bộ
                                         giá trị, mã để trống. Bản cũ chép luôn cả mã sang, nên
                                         bấm Lưu là ăn ngay lỗi trùng mã — chép cái chắc chắn
                                         sai thì thà đừng chép. --}}
                                    <button type="button" class="tt-btn-icon" title="Nhân bản"
                                            data-tt-copy data-tt="{{ json_encode($tt, JSON_UNESCAPED_UNICODE) }}">
                                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15H4a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1v1"/></svg>
                                    </button>

                                    @if($dangDungRow)
                                        {{-- Đang được biến thể / định lượng dùng: nút xoá xám
                                             hẳn và nói LÝ DO ngay ở tooltip, thay vì cho bấm
                                             rồi mới báo lỗi như bản cũ. --}}
                                        <button type="button" class="tt-btn-icon is-off" disabled
                                                title="Đang được biến thể hoặc định lượng dùng — không xoá được. Muốn thôi bày ra thì tắt đi.">
                                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M6 6v14a1 1 0 0 0 1 1h10a1 1 0 0 0 1-1V6"/><path d="M10 11v6M14 11v6"/></svg>
                                        </button>
                                    @else
                                        {{-- Form riêng từng dòng: nút dùng chung + id nhét bằng JS
                                             thì lúc JS hỏng sẽ xoá nhầm dòng cuối cùng đã gán. --}}
                                        <form method="POST" action="{{ route('admin.thuoc-tinh.destroy', $id) }}"
                                              class="tt-inline-form" data-tt-xoa
                                              data-ten="{{ $tt['name'] ?? '' }}" data-so-gia-tri="{{ count($giaTri) }}">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="tt-btn-icon is-danger" title="Xoá">
                                                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M6 6v14a1 1 0 0 0 1 1h10a1 1 0 0 0 1-1V6"/><path d="M10 11v6M14 11v6"/></svg>
                                            </button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="tt-empty">
                                {{ $hasFilter ? 'Không có thuộc tính nào khớp bộ lọc đang bật.' : $C::EMPTY_TEXT }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @include('partials.pagination-v2', [
            'meta' => $meta,
            'noun' => 'thuộc tính',
            'perPageOptions' => \App\Http\Controllers\ThuocTinhController::MUC_SO_DONG,
        ])
    </div>

    {{-- Modal thêm / sửa --}}
    <div class="tt-overlay" id="ttOverlay" style="display:none;">
        <div class="tt-dialog">
            <div class="tt-modal-head">
                <h4 class="tt-modal-title" id="ttModalTitle">Thêm thuộc tính</h4>
                <button type="button" class="tt-modal-x" data-close>
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </div>

            <form id="ttForm" method="POST" action="{{ route('admin.thuoc-tinh.store') }}">
                @csrf
                {{-- _method rỗng khi TẠO, "PUT" khi sửa — Laravel bỏ qua giá trị rỗng. --}}
                <input type="hidden" name="_method" id="ttMethod" value="">
                {{-- Id của dòng đang sửa. Nó nằm trên ĐƯỜNG DẪN chứ không phải trong
                     payload, nên controller bỏ qua ô này; để đây chỉ để lượt Lưu hỏng
                     còn dựng lại được đúng đường sửa khi mở lại hộp thoại. --}}
                <input type="hidden" name="tt_id" id="ttId" value="{{ old('tt_id') }}">

                <div class="tt-modal-body">
                    <div class="tt-grid2">
                        <div>
                            <label class="tt-field-label" for="ttMa">Mã thuộc tính</label>
                            {{-- KHÔNG bắt buộc: bỏ trống thì API tự đặt mã theo quy tắc
                                 đánh số của cửa hàng (Cài đặt → Thông số chung) nếu đã
                                 bật, không thì dải TT001. Và mã SỬA ĐƯỢC cả lúc sửa —
                                 bản cũ khoá ô này lại, gõ nhầm là phải xoá đi khai lại. --}}
                            <input type="text" id="ttMa" name="code" class="tt-input" maxlength="20"
                                   autocomplete="off" placeholder="Bỏ trống để tự đặt TT001…" value="{{ old('code') }}">
                        </div>

                        <div>
                            <label class="tt-field-label" for="ttTen">Tên thuộc tính <span class="tt-req">*</span></label>
                            <input type="text" id="ttTen" name="name" class="tt-input" maxlength="100" required
                                   autocomplete="off" placeholder="Ví dụ: Kích cỡ, Mức đá" value="{{ old('name') }}">
                        </div>
                    </div>

                    <div class="tt-grid2">
                        <div>
                            <label class="tt-field-label">Trạng thái</label>
                            <label class="tt-switch-row">
                                <input type="checkbox" id="ttTrangThai" class="tt-switch" @checked(old('is_active', 1))>
                                <span class="tt-switch-label" id="ttTrangThaiChu">Đang dùng</span>
                            </label>
                            <input type="hidden" name="is_active" id="ttTrangThaiValue" value="{{ old('is_active', 1) }}">
                        </div>

                        <div>
                            <label class="tt-field-label">Định lượng nguyên vật liệu</label>
                            <label class="tt-switch-row">
                                <input type="checkbox" id="ttDinhLuong" class="tt-switch" @checked(old('raw_material'))>
                                <span class="tt-switch-label" id="ttDinhLuongChu">Không</span>
                            </label>
                            <input type="hidden" name="raw_material" id="ttDinhLuongValue" value="{{ old('raw_material', 0) }}">
                            <p class="tt-hint" id="ttDinhLuongGhiChu">Bật thì thuộc tính này được dùng để khai định lượng nguyên liệu cho món.</p>
                        </div>
                    </div>

                    {{-- Phần giá trị con: bảng nhập tay, thêm/xoá dòng ngay tại chỗ.
                         Xoá dòng CHỈ bỏ khỏi form — bấm Lưu mới thật sự ghi. --}}
                    <div class="tt-vals">
                        <div class="tt-vals-head">
                            <span class="tt-field-label" style="margin:0;">Giá trị của thuộc tính</span>
                            <button type="button" class="tt-btn-ghost tt-btn-sm" id="ttThemGiaTri">
                                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M12 5v14"/></svg>
                                Thêm giá trị
                            </button>
                        </div>

                        <table class="tt-vals-table">
                            <thead>
                                <tr>
                                    <th class="tt-v-code">Mã giá trị</th>
                                    <th class="tt-v-name">Tên giá trị</th>
                                    <th class="tt-v-act"></th>
                                </tr>
                            </thead>
                            <tbody id="ttValsBody"></tbody>
                        </table>

                        <p class="tt-vals-empty" id="ttValsEmpty">Chưa có giá trị nào. Ví dụ thuộc tính "Kích cỡ" thì giá trị là Nhỏ / Vừa / Lớn.</p>
                    </div>
                </div>

                <div class="tt-modal-foot">
                    <button type="button" class="tt-btn-ghost" data-close>Đóng</button>
                    <button type="submit" class="tt-btn-primary" id="ttSubmit">Lưu</button>
                </div>
            </form>
        </div>
    </div>

    {{-- Hộp xác nhận dùng chung cho xoá một dòng và xoá hàng loạt --}}
    <div class="tt-overlay" id="ttConfirm" style="display:none;">
        <div class="tt-dialog is-confirm">
            <div class="tt-modal-head">
                <h4 class="tt-modal-title" id="ttConfirmTitle">Xoá thuộc tính?</h4>
                <button type="button" class="tt-modal-x" data-tt-confirm-huy>
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </div>
            <div class="tt-modal-body" id="ttConfirmBody"></div>
            <div class="tt-modal-foot">
                <button type="button" class="tt-btn-ghost" data-tt-confirm-huy>Huỷ</button>
                <button type="button" class="tt-btn-primary is-danger" id="ttConfirmOk">Xoá</button>
            </div>
        </div>
    </div>

    <style>
        .tt {
            /* Phá padding p-4 của <main> để tràn viền như các trang danh sách khác */
            margin: -1.5rem;
            min-height: calc(100vh - 56px);
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            color: #262626; background: #fff;
        }

        /* Header — cùng khuôn trang Đơn vị tính / Quản lý thuế. */
        .tt-head { display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; padding: 14px 20px; }
        .tt-title { margin: 0; font-size: 16px; font-weight: 700; color: #262626; line-height: 34px; }
        .tt-sum { font-size: 13px; color: #595959; }
        .tt-sum b { color: #262626; }

        .tt-callout {
            display: flex; align-items: flex-start; gap: 8px;
            margin: 0 20px 12px; padding: 10px 12px; border-radius: 4px;
            background: #fff7e6; border: 1px solid #ffd591; color: #874d00; font-size: 13px;
        }
        .tt-callout.is-error { background: #fff1f0; border-color: #ffa39e; color: #a8071a; }

        /* Bộ lọc */
        .tt-filter { padding: 0 20px 12px; margin-bottom: 12px; border-bottom: 1px solid #eee; }
        .tt-toolbar { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; }
        .tt-toolbar-actions { margin-left: auto; display: flex; align-items: center; gap: 8px; }

        .tt-searchbox { display: flex; border-radius: 4px; }
        .tt-searchbox:focus-within { box-shadow: 0 0 0 4px rgba(13,110,253,.25); }
        .tt-search-input {
            height: 34px; width: 280px; border: 1px solid #d9d9d9; border-right: 0;
            border-radius: 4px 0 0 4px; background: #fff; padding: 0 12px; font-size: 13px; outline: none;
        }
        .tt-search-input::placeholder { color: #bfbfbf; }
        /* Vòng focus vẽ ở .tt-searchbox, ô trong không vẽ lại (luật chung ở layout). */
        .tt-searchbox:focus-within .tt-search-input,
        .tt-searchbox:focus-within .tt-search-btn { border-color: #86b7fe; }
        .tt-search-input:focus { box-shadow: none !important; }
        .tt-search-btn {
            height: 34px; width: 40px; display: inline-flex; align-items: center; justify-content: center;
            border: 1px solid #d9d9d9; border-radius: 0 4px 4px 0; background: #fafafa; color: #595959; cursor: pointer;
        }
        .tt-search-btn:hover { color: #1890ff; }

        .tt-select {
            height: 34px; border: 1px solid #d9d9d9; border-radius: 4px; background-color: #fff;
            padding: 0 30px 0 12px; font-size: 13px; color: #262626; cursor: pointer; outline: none; max-width: 220px;
            appearance: none; -webkit-appearance: none;
            background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23595959' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><path d='m6 9 6 6 6-6'/></svg>");
            background-repeat: no-repeat; background-position: right 8px center;
        }

        .tt-clear {
            display: inline-flex; align-items: center; height: 34px; padding: 0 10px; border-radius: 4px;
            font-size: 13px; color: #8c8c8c; text-decoration: none; transition: background .15s, color .15s;
        }
        .tt-clear:hover { background: #fff1f0; color: #ff4d4f; }

        /* Thanh hành động hàng loạt — NỔI ở đáy màn hình, cùng khuôn trang Đơn vị tính.
           left: 230px bù đúng bề ngang thanh trái để nó căn giữa VÙNG NỘI DUNG. */
        .tt-bulkbar {
            position: fixed; bottom: 24px; left: 230px; right: 0; margin-inline: auto; z-index: 1070;
            width: max-content; max-width: calc(100vw - 260px);
            display: flex; align-items: center; gap: 16px;
            border: 1px solid #e6e6e6; border-radius: 9999px;
            background: #fff; padding: 12px 20px; box-shadow: 0 6px 24px rgba(0,0,0,.15);
        }
        body:has(.jh-sidebar.collapsed) .tt-bulkbar { left: 48px; }
        @media (max-width: 820px) { .tt-bulkbar { left: 0; } }
        .tt-bulkbar__so { font-size: 13px; font-weight: 500; color: #262626; }
        .tt-bulkbar__bo {
            border: 0; background: none; padding: 0;
            font-size: 13px; color: #8c8c8c; cursor: pointer; transition: color .15s;
        }
        .tt-bulkbar__bo:hover { color: #262626; }
        .tt-bulkbar .tt-btn-ghost { border-radius: 9999px; height: 32px; }
        .tt-btn-ghost.is-danger { border-color: transparent; background: #ff4d4f; color: #fff; }
        .tt-btn-ghost.is-danger:hover { background: #ff7875; color: #fff; border-color: transparent; }

        /* Bảng — cách sắp xếp của bản cũ v2 (mọi ô canh giữa, ô rộng rãi), MÀU giữ
           của bản hiện tại. */
        .tt-table-wrap { padding: 0 20px 24px; overflow-x: auto; }
        .tt-table { width: 100%; min-width: 980px; border-collapse: collapse; font-size: 13.5px; table-layout: fixed; }
        .tt-table th {
            font-weight: 600; color: #595959; background: #fafafa;
            padding: 12px; border-bottom: 1px solid #f0f0f0; white-space: nowrap;
        }
        .tt-table th, .tt-table td { text-align: center; vertical-align: middle; white-space: nowrap; }
        /* Ô nằm một dòng; cột chữ dài cắt bằng dấu ba chấm, không thì
           chữ tràn sang ô bên cạnh. Riêng dòng "chưa có dữ liệu" là một
           câu dài nên cho xuống hàng. */
        .tt-table td.tt-c-name,
        .tt-table td.tt-c-vals { overflow: hidden; text-overflow: ellipsis; }
        .tt-empty { white-space: normal; }

        .tt-table td { padding: 14px 12px; border-bottom: 1px solid #f5f5f5; }
        .tt-table tbody tr:hover td { background: #fafafa; }

        /* Bề rộng theo TỈ LỆ, tổng đúng 100% — không cột nào bỏ trống, nếu không
           phần dư dồn hết vào một cột và bảng hở ra một khoảng chết. */
        .tt-table th.tt-c-tick,   .tt-table td.tt-c-tick   { width: 4%; }
        .tt-table th.tt-c-stt,    .tt-table td.tt-c-stt    { width: 5%; }
        .tt-table th.tt-c-code,   .tt-table td.tt-c-code   { width: 12%; }
        .tt-table th.tt-c-name,   .tt-table td.tt-c-name   { width: 18%; }
        .tt-table th.tt-c-vals,   .tt-table td.tt-c-vals   { width: 30%; }
        .tt-table th.tt-c-raw,    .tt-table td.tt-c-raw    { width: 12%; }
        .tt-table th.tt-c-status, .tt-table td.tt-c-status { width: 9%; }
        .tt-table th.tt-c-act,    .tt-table td.tt-c-act    { width: 10%; }

        .tt-name { font-weight: 600; }
        .tt-muted { color: #8c8c8c; }
        .tt-code { font-size: 12px; background: #f5f5f5; border-radius: 3px; padding: 2px 8px; color: #595959; }
        .tt-empty { text-align: center; color: #8c8c8c; padding: 32px 12px; }

        /* Giá trị con bày thành chip, xuống dòng chứ không cắt cụt bằng "…". */
        .tt-chips { display: flex; flex-wrap: wrap; gap: 4px; justify-content: center; }
        .tt-chip {
            display: inline-flex; align-items: center; height: 22px; padding: 0 8px;
            border: 1px solid #e6e6e6; border-radius: 3px; background: #fafafa;
            font-size: 12px; color: #595959; white-space: nowrap;
        }
        .tt-chip.is-more { background: #fff; color: #8c8c8c; border-style: dashed; }

        .tt-tag {
            display: inline-flex; align-items: center; height: 22px; padding: 0 8px;
            border-radius: 3px; font-size: 12px;
            background: #f5f5f5; color: #8c8c8c; border: 1px solid #ebebeb;
        }
        .tt-tag.is-on { background: #e6f4ff; color: #0958d9; border-color: #91caff; }

        /* Nút thao tác: ô vuông bo góc có viền, lúc thường xám, rê chuột mới ăn màu. */
        .tt-rowacts { display: flex; align-items: center; justify-content: center; gap: 6px; }
        .tt-inline-form { display: inline; margin: 0; }
        .tt-btn-icon {
            display: inline-flex; align-items: center; justify-content: center;
            width: 30px; height: 30px; border-radius: 4px;
            border: 1px solid #d9d9d9; background: #fff; color: #595959; cursor: pointer;
        }
        .tt-btn-icon:hover { border-color: #0d6efd; color: #0d6efd; }
        .tt-btn-icon.is-danger:hover { border-color: #ff4d4f; color: #ff4d4f; }
        .tt-btn-icon.is-off { color: #d9d9d9; border-color: #ebebeb; cursor: not-allowed; }
        .tt-btn-icon.is-off:hover { border-color: #ebebeb; color: #d9d9d9; }

        .tt-btn-primary {
            display: inline-flex; align-items: center; gap: 6px;
            height: 34px; padding: 0 14px; border-radius: 4px; border: 0;
            background: #0d6efd; color: #fff; font-size: 13px; font-weight: 600; cursor: pointer;
        }
        .tt-btn-primary:hover { background: #0b5ed7; }
        .tt-btn-primary:disabled { opacity: .55; cursor: default; }
        /* Nút Đồng ý của hộp xác nhận tô ĐỎ: hai nút xanh giống hệt nhau thì người
           đọc bấm theo vị trí, không theo chữ. */
        .tt-btn-primary.is-danger { background: #ff4d4f; }
        .tt-btn-primary.is-danger:hover { background: #ff7875; }
        .tt-btn-ghost {
            display: inline-flex; align-items: center; gap: 6px;
            height: 34px; padding: 0 14px; border-radius: 4px; text-decoration: none;
            border: 1px solid #d9d9d9; background: #fff; color: #595959; font-size: 13px; cursor: pointer;
            white-space: nowrap;
        }
        .tt-btn-ghost:hover { color: #0d6efd; border-color: #0d6efd; }
        .tt-btn-sm { height: 28px; padding: 0 10px; font-size: 12.5px; }

        /* Công tắc — dáng của input.switch_customer bên bản cũ, đổi màu bật. */
        .tt-switch {
            appearance: none; -webkit-appearance: none;
            width: 2.6em; height: 1.4em; margin: 0;
            background: #dcdcdc; border: 0; border-radius: 3em;
            position: relative; cursor: pointer; outline: none;
            transition: background .2s ease-in-out; flex-shrink: 0;
        }
        .tt-switch:checked { background: #0d6efd; }
        .tt-switch::after {
            content: ''; position: absolute; left: 0; top: 0;
            width: 1.4em; height: 1.4em; border-radius: 50%;
            background: #fff; box-shadow: 0 0 .25em rgba(0,0,0,.3);
            transform: scale(.72); transition: left .2s ease-in-out;
        }
        .tt-switch:checked::after { left: calc(100% - 1.4em); }
        .tt-switch:disabled { opacity: .5; cursor: not-allowed; }
        .tt-switch-row { display: inline-flex; align-items: center; gap: 10px; cursor: pointer; height: 36px; }
        .tt-switch-label { font-size: 13px; color: #262626; }
        /* Công tắc trong bảng nằm trong form riêng nên phải bỏ margin. */
        .tt-switch-form { display: inline-flex; margin: 0; }

        /* Modal */
        .tt-overlay { position: fixed; inset: 0; background: rgba(0,0,0,.45); z-index: 1050; display: flex; align-items: center; justify-content: center; padding: 16px; }
        .tt-dialog { width: 100%; max-width: 640px; max-height: calc(100vh - 32px); display: flex; flex-direction: column; background: #fff; border-radius: 6px; box-shadow: 0 6px 24px rgba(0,0,0,.2); }
        .tt-dialog form { display: flex; flex-direction: column; min-height: 0; }
        .tt-dialog.is-confirm { max-width: 420px; }
        .tt-modal-head { display: flex; align-items: center; justify-content: space-between; padding: 14px 20px; border-bottom: 1px solid #f0f0f0; }
        .tt-modal-title { margin: 0; font-size: 15px; font-weight: 700; }
        .tt-modal-x { border: 0; background: none; color: #8c8c8c; cursor: pointer; line-height: 0; }
        .tt-modal-x:hover { color: #262626; }
        .tt-modal-body { padding: 16px 20px; display: flex; flex-direction: column; gap: 14px; overflow-y: auto; }
        .tt-modal-body p { margin: 0; font-size: 13px; color: #595959; }
        /* Hàng nút ở chân hộp thoại luôn CANH GIỮA — quy tắc chung của dự án. */
        .tt-modal-foot { display: flex; justify-content: center; gap: 8px; padding: 12px 20px; border-top: 1px solid #f0f0f0; background: #fafafa; border-radius: 0 0 6px 6px; }

        .tt-grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
        @media (max-width: 560px) { .tt-grid2 { grid-template-columns: 1fr; } }

        .tt-field-label { display: block; font-size: 12.5px; font-weight: 600; color: #434343; margin-bottom: 5px; }
        .tt-req { color: #ff4d4f; }
        .tt-input {
            width: 100%; height: 36px; border: 1px solid #d9d9d9; border-radius: 4px;
            padding: 0 12px; font-size: 13px; outline: none; background-color: #fff; color: #262626;
        }
        .tt-input:disabled { background: #f5f5f5; color: #8c8c8c; }
        .tt-hint { margin: 4px 0 0; font-size: 12px; color: #8c8c8c; }

        /* Bảng giá trị con trong hộp thoại */
        .tt-vals { border: 1px solid #f0f0f0; border-radius: 4px; }
        .tt-vals-head { display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 10px 12px; border-bottom: 1px solid #f0f0f0; background: #fafafa; }
        .tt-vals-table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .tt-vals-table th { font-size: 12px; font-weight: 600; color: #8c8c8c; text-align: left; padding: 8px 12px; border-bottom: 1px solid #f5f5f5; }
        .tt-vals-table td { padding: 6px 12px; border-bottom: 1px solid #f7f7f7; vertical-align: middle; }
        .tt-vals-table tr:last-child td { border-bottom: 0; }
        .tt-vals-table th.tt-v-code, .tt-vals-table td.tt-v-code { width: 32%; }
        .tt-vals-table th.tt-v-name, .tt-vals-table td.tt-v-name { width: 56%; }
        .tt-vals-table th.tt-v-act,  .tt-vals-table td.tt-v-act  { width: 12%; text-align: center; }
        .tt-vals-table .tt-input { height: 32px; }
        .tt-vals-empty { padding: 14px 12px; font-size: 12.5px; color: #8c8c8c; }
    </style>

    <script>
        (function () {
            // ---------- Bộ lọc realtime: đổi select -> chạy ngay; gõ -> chờ 400ms ----------
            var filter = document.getElementById('ttFilter');
            filter.querySelectorAll('select').forEach(function (sel) {
                sel.addEventListener('change', function () { filter.submit(); });
            });
            var search = filter.querySelector('input[name="keyword"]');
            var searchTimer = null;
            search.addEventListener('input', function () {
                clearTimeout(searchTimer);
                searchTimer = setTimeout(function () { filter.submit(); }, 400);
            });

            var overlay = document.getElementById('ttOverlay');
            var form = document.getElementById('ttForm');
            var method = document.getElementById('ttMethod');
            var title = document.getElementById('ttModalTitle');
            var submit = document.getElementById('ttSubmit');

            var oId = document.getElementById('ttId');
            var oMa = document.getElementById('ttMa');
            var oTen = document.getElementById('ttTen');
            var oBat = document.getElementById('ttTrangThai');
            var oBatChu = document.getElementById('ttTrangThaiChu');
            var oBatValue = document.getElementById('ttTrangThaiValue');
            var oNvl = document.getElementById('ttDinhLuong');
            var oNvlChu = document.getElementById('ttDinhLuongChu');
            var oNvlValue = document.getElementById('ttDinhLuongValue');
            var oNvlGhiChu = document.getElementById('ttDinhLuongGhiChu');

            var valsBody = document.getElementById('ttValsBody');
            var valsEmpty = document.getElementById('ttValsEmpty');
            // Số thứ tự dòng giá trị, chỉ tăng: xoá dòng giữa rồi thêm dòng mới mà
            // dùng lại số cũ thì hai dòng cùng tên trường, PHP giữ đúng một cái.
            var valsDem = 0;

            var STORE = @json(route('admin.thuoc-tinh.store'));
            // Khuôn đường sửa: thay 0 bằng id thật. Dựng bằng route() để đường dẫn
            // vẫn đúng nếu prefix của nhóm route đổi.
            var UPDATE = @json(route('admin.thuoc-tinh.update', 0));

            // ---------- Hộp xác nhận dùng chung ----------
            //
            // Thay chỗ confirm() của trình duyệt: đặt được chữ trên từng nút và nói
            // rõ xoá cái gì. Bất đồng bộ nên nơi gọi đưa việc cần làm vào `xong`.
            var hopHoi = document.getElementById('ttConfirm');
            var hopHoiTieuDe = document.getElementById('ttConfirmTitle');
            var hopHoiNoiDung = document.getElementById('ttConfirmBody');
            var hopHoiOk = document.getElementById('ttConfirmOk');
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

                // textContent từng đoạn: tên thuộc tính là chữ do người dùng gõ, nối
                // thẳng vào innerHTML là mở cửa cho thẻ script.
                hopHoiNoiDung.innerHTML = '';
                (o.doan || []).forEach(function (d) {
                    var p = document.createElement('p');
                    p.textContent = d;
                    hopHoiNoiDung.appendChild(p);
                });

                hopHoi.style.display = 'flex';
                hopHoiOk.focus();
            }

            hopHoiOk.addEventListener('click', function () { dongHoi(true); });
            document.querySelectorAll('[data-tt-confirm-huy]').forEach(function (el) {
                el.addEventListener('click', function () { dongHoi(false); });
            });
            // Bấm ra ngoài = Huỷ, y như hộp thêm/sửa.
            hopHoi.addEventListener('click', function (e) { if (e.target === hopHoi) dongHoi(false); });

            // ---------- Bảng giá trị con trong hộp thoại ----------

            function veValsEmpty() {
                valsEmpty.style.display = valsBody.children.length ? 'none' : 'block';
            }

            // Một dòng giá trị. `khoaMa` = giá trị CŨ (đã có id): mã của nó đã in lên
            // đơn và biến thể rồi, sửa ở đây là đổi nghĩa dữ liệu cũ.
            function themDongGiaTri(gt) {
                gt = gt || {};
                var i = valsDem++;
                var tr = document.createElement('tr');

                var tdCode = document.createElement('td');
                tdCode.className = 'tt-v-code';
                var hid = document.createElement('input');
                hid.type = 'hidden';
                hid.name = 'values[' + i + '][id]';
                hid.value = gt.id ? String(gt.id) : '';
                var ma = document.createElement('input');
                ma.type = 'text';
                ma.className = 'tt-input';
                ma.maxLength = 20;
                ma.autocomplete = 'off';
                ma.name = 'values[' + i + '][code]';
                ma.placeholder = 'Tự đặt';
                ma.value = gt.code || '';
                tdCode.appendChild(hid);
                tdCode.appendChild(ma);

                var tdName = document.createElement('td');
                tdName.className = 'tt-v-name';
                var ten = document.createElement('input');
                ten.type = 'text';
                ten.className = 'tt-input';
                ten.maxLength = 100;
                ten.autocomplete = 'off';
                ten.name = 'values[' + i + '][name]';
                ten.placeholder = 'Ví dụ: Nhỏ';
                ten.value = gt.name || '';
                tdName.appendChild(ten);

                var tdAct = document.createElement('td');
                tdAct.className = 'tt-v-act';
                var xoa = document.createElement('button');
                xoa.type = 'button';
                xoa.className = 'tt-btn-icon is-danger';
                xoa.title = 'Bỏ dòng này';
                xoa.innerHTML = '<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>';
                // Chỉ bỏ khỏi FORM. Bấm Lưu mới thật sự xoá dưới cơ sở dữ liệu —
                // bản cũ bắn AJAX xoá ngay tại đây nên bấm nhầm là mất luôn.
                xoa.addEventListener('click', function () {
                    tr.remove();
                    veValsEmpty();
                });
                tdAct.appendChild(xoa);

                tr.appendChild(tdCode);
                tr.appendChild(tdName);
                tr.appendChild(tdAct);
                valsBody.appendChild(tr);
                veValsEmpty();

                return ten;
            }

            document.getElementById('ttThemGiaTri').addEventListener('click', function () {
                themDongGiaTri({}).focus();
            });

            // ---------- Hộp thêm / sửa ----------

            function veCongTac() {
                oBatValue.value = oBat.checked ? '1' : '0';
                oBatChu.textContent = oBat.checked ? 'Đang dùng' : 'Đã tắt';
            }
            oBat.addEventListener('change', veCongTac);

            function veNvl() {
                oNvlValue.value = oNvl.checked ? '1' : '0';
                oNvlChu.textContent = oNvl.checked ? 'Có' : 'Không';
            }
            oNvl.addEventListener('change', veNvl);

            // id > 0 = lượt SỬA: đường gửi và _method suy ra từ chính nó, nơi gọi
            // không phải nhắc lại ba lần.
            function moForm(o) {
                var id = Number(o.id || 0);
                title.textContent = o.tieuDe;
                form.action = id > 0 ? UPDATE.replace(/0$/, String(id)) : STORE;
                method.value = id > 0 ? 'PUT' : '';
                oId.value = id > 0 ? String(id) : '';
                oMa.value = o.code || '';
                oTen.value = o.name || '';
                oBat.checked = o.isActive !== false;
                oNvl.checked = !!o.rawMaterial;

                // Đã trót dùng để định lượng thì KHOÁ công tắc lại: tắt đi là bộ định
                // lượng đã khai của món mất chỗ bám. Bản cũ cũng khoá, giữ nguyên.
                var khoaNvl = !!o.inUse && !!o.rawMaterial;
                oNvl.disabled = khoaNvl;
                oNvlGhiChu.textContent = khoaNvl
                    ? 'Đang có món khai định lượng theo thuộc tính này nên không tắt được.'
                    : 'Bật thì thuộc tính này được dùng để khai định lượng nguyên liệu cho món.';

                veCongTac();
                veNvl();

                valsBody.innerHTML = '';
                (o.values || []).forEach(function (gt) { themDongGiaTri(gt); });
                veValsEmpty();

                submit.disabled = false;
                overlay.style.display = 'flex';
                (o.oDau || oMa).focus();
            }

            function dongForm() { overlay.style.display = 'none'; }

            document.querySelector('[data-tt-add]').addEventListener('click', function () {
                moForm({ tieuDe: 'Thêm thuộc tính' });
            });

            document.querySelectorAll('[data-tt-edit]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var tt = JSON.parse(btn.dataset.tt);
                    moForm({
                        tieuDe: 'Sửa thuộc tính',
                        id: tt.id,
                        code: tt.code,
                        name: tt.name,
                        isActive: !!tt.is_active,
                        rawMaterial: !!tt.raw_material,
                        inUse: !!tt.in_use,
                        values: tt.values || [],
                        oDau: oTen,
                    });
                });
            });

            // Nhân bản: chép TÊN và cả bộ giá trị, để trống mã (cả mã thuộc tính lẫn
            // mã từng giá trị). Mã bắt buộc phải khác nên chép sang chỉ tổ ăn lỗi
            // trùng ngay lượt Lưu đầu tiên.
            document.querySelectorAll('[data-tt-copy]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var tt = JSON.parse(btn.dataset.tt);
                    moForm({
                        tieuDe: 'Nhân bản thuộc tính',
                        name: (tt.name || '') + ' (bản sao)',
                        isActive: !!tt.is_active,
                        rawMaterial: !!tt.raw_material,
                        values: (tt.values || []).map(function (gt) {
                            return { name: gt.name };
                        }),
                    });
                });
            });

            document.querySelectorAll('#ttOverlay [data-close]').forEach(function (el) {
                el.addEventListener('click', dongForm);
            });
            overlay.addEventListener('click', function (e) { if (e.target === overlay) dongForm(); });
            document.addEventListener('keydown', function (e) {
                if (e.key !== 'Escape') return;
                if (hopHoi.style.display === 'flex') { dongHoi(false); return; }
                if (overlay.style.display === 'flex') dongForm();
            });

            // Khoá nút Lưu ngay khi gửi: bấm hai lần liên tiếp lúc mạng chậm là hai
            // thuộc tính trùng tên.
            form.addEventListener('submit', function () { submit.disabled = true; });

            // Bấm Lưu mà API từ chối thì mở lại hộp thoại kèm dữ liệu vừa gõ, KỂ CẢ
            // các dòng giá trị — gõ lại mười dòng vì sai một ô là thứ không ai chịu.
            @if($moLaiForm)
                moForm({
                    tieuDe: @json((int) old('tt_id', 0) > 0 ? 'Sửa thuộc tính' : 'Thêm thuộc tính'),
                    id: @json((int) old('tt_id', 0)),
                    code: @json(old('code', '')),
                    name: @json(old('name', '')),
                    isActive: @json((bool) old('is_active', 1)),
                    rawMaterial: @json((bool) old('raw_material', 0)),
                    values: @json(array_values((array) old('values', []))),
                });
            @endif

            // ---------- Công tắc trạng thái trên bảng ----------
            //
            // Gạt là gửi form của chính dòng đó, rồi khoá lại để hai cú gạt liên
            // tiếp không thành hai lượt ghi ngược nhau.
            document.querySelectorAll('[data-tt-status]').forEach(function (f) {
                var sw = f.querySelector('.tt-switch');
                sw.addEventListener('change', function () {
                    sw.disabled = true;
                    f.submit();
                });
            });

            // ---------- Xoá một dòng ----------
            document.querySelectorAll('[data-tt-xoa]').forEach(function (f) {
                f.addEventListener('submit', function (e) {
                    e.preventDefault();
                    var soGiaTri = Number(f.dataset.soGiaTri || 0);
                    hoi({
                        tieuDe: 'Xoá thuộc tính?',
                        doan: [
                            'Xoá thuộc tính "' + f.dataset.ten + '"'
                                + (soGiaTri > 0 ? ' cùng ' + soGiaTri + ' giá trị của nó.' : '.'),
                            'Chỉ muốn thôi bày nó ra lúc khai mặt hàng thì TẮT đi, đừng xoá.',
                        ],
                    }, function (dongY) {
                        if (dongY) f.submit();
                    });
                });
            });

            // ---------- Chọn nhiều dòng ----------
            var bulkForm = document.getElementById('ttBulkForm');
            var bulkBar = document.getElementById('ttBulkBar');
            var bulkSo = document.getElementById('ttBulkSo');
            var chonHet = document.getElementById('ttChonHet');
            var oChon = Array.prototype.slice.call(document.querySelectorAll('[data-tt-chon]'));

            function demLai() {
                var da = oChon.filter(function (o) { return o.checked; });
                bulkSo.textContent = String(da.length);
                bulkBar.style.display = da.length ? 'flex' : 'none';
                chonHet.checked = da.length > 0 && da.length === oChon.length;
                chonHet.indeterminate = da.length > 0 && da.length < oChon.length;
            }

            oChon.forEach(function (o) { o.addEventListener('change', demLai); });
            chonHet.addEventListener('change', function () {
                oChon.forEach(function (o) { o.checked = chonHet.checked; });
                demLai();
            });
            document.getElementById('ttBulkBo').addEventListener('click', function () {
                oChon.forEach(function (o) { o.checked = false; });
                demLai();
            });

            document.getElementById('ttBulkXoa').addEventListener('click', function () {
                var da = oChon.filter(function (o) { return o.checked; });
                if (!da.length) return;
                hoi({
                    tieuDe: 'Xoá ' + da.length + ' thuộc tính?',
                    doan: [
                        'Xoá ' + da.length + ' thuộc tính đã chọn, cùng toàn bộ giá trị của chúng.',
                        'Chỉ muốn thôi bày chúng ra lúc khai mặt hàng thì TẮT đi, đừng xoá.',
                    ],
                }, function (dongY) {
                    if (dongY) bulkForm.submit();
                });
            });

            demLai();
            veValsEmpty();
        })();
    </script>
@endsection
