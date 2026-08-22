@extends('layouts.app')

@section('title', \App\Http\Controllers\DonViTinhController::TITLE_PAGE)

@section('content')
    {{--
        Trang "Đơn vị tính" — dựng theo màn Đơn vị của bản cũ v2 (menu/menu-unit):
        [ header ] + [ thanh lọc ] + [ bảng mã/tên/trạng thái ] + [ modal thêm/sửa ].

        Giữ của bản cũ: cột chọn dòng, STT, mã, tên, công tắc trạng thái ngay trên
        dòng, ba nút Sửa / Nhân bản / Xoá, và xoá nhiều dòng một lượt.

        Làm khác bản cũ bốn chỗ:
        - Lọc realtime, không có nút kính lúp (quy tắc chung của trang danh sách).
        - Nút "Thêm đơn vị" nằm CUỐI thanh lọc, không nằm cạnh tiêu đề.
        - Ô Trạng thái trong hộp thoại sửa được. Bản cũ ẩn hẳn ô đó lúc sửa.
        - Mọi cột khai bề rộng theo %, tổng đúng 100% — không cột nào bỏ trống.
    --}}
    @php
        $C = \App\Http\Controllers\DonViTinhController::class;
        $TITLE = $C::TITLE_PAGE;

        $so = fn ($n) => number_format((float) $n, 0, ',', '.');
        $hasFilter = $filters['keyword'] !== '' || $filters['status'] !== '';

        // Bấm Lưu mà hỏng thì mở lại hộp thoại kèm dữ liệu vừa gõ.
        $moLaiForm = filled(old('name'));
    @endphp

    <div class="dvt">
        {{-- Header: tiêu đề + một dòng tổng. Nút "Thêm" nằm cuối thanh lọc. --}}
        <div class="dvt-head">
            <h1 class="dvt-title">{{ $TITLE }}</h1>
            <span class="dvt-sum">
                Đang dùng: <b>{{ $so($dangDung) }}</b>/{{ $so($tong) }} đơn vị
            </span>
        </div>

        @if(!empty($error))
            <p class="dvt-callout is-error">{{ $error }}</p>
        @endif

        {{-- Bộ lọc realtime: đổi select chạy ngay, gõ thì chờ 400ms. Không có nút
             "Lọc" — quy tắc chung của mọi trang danh sách trong dự án. --}}
        <form method="GET" action="{{ route('admin.don-vi-tinh.index') }}" id="dvtFilter" class="dvt-filter">
            <div class="dvt-toolbar">
                <div class="dvt-searchbox">
                    <input type="text" name="keyword" value="{{ $filters['keyword'] }}" class="dvt-search-input"
                           placeholder="Tìm theo tên hoặc mã đơn vị" autocomplete="off">
                    <button type="submit" class="dvt-search-btn" title="Tìm kiếm">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                    </button>
                </div>

                <select name="status" class="dvt-select" title="Lọc theo trạng thái">
                    <option value="">Tất cả trạng thái</option>
                    @foreach($C::TRANG_THAI as $ma => $ten)
                        <option value="{{ $ma }}" @selected($filters['status'] === $ma)>{{ $ten }}</option>
                    @endforeach
                </select>

                {{-- "Xoá lọc" ở hàng chính: một điều kiện đang ẩn mà không thấy đường
                     gỡ ra là cách nhanh nhất để tưởng danh sách mất dữ liệu. --}}
                @if($hasFilter)
                    <a href="{{ route('admin.don-vi-tinh.index') }}" class="dvt-clear">Xoá lọc</a>
                @endif

                <div class="dvt-toolbar-actions">
                    <button type="button" class="dvt-btn-primary" data-dvt-add>
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M12 5v14"/></svg>
                        Thêm đơn vị
                    </button>
                </div>
            </div>
        </form>

        {{-- Form RỖNG, đứng ngoài bảng. Ô tick trong bảng nối vào nó bằng thuộc tính
             `form="dvtBulkForm"` chứ KHÔNG bọc bảng lại: mỗi dòng đã có form riêng
             cho công tắc trạng thái và nút xoá, mà form lồng form thì HTML không
             cho — trình duyệt bỏ cái bên trong, và hai nút ấy chết. --}}
        <form method="POST" id="dvtBulkForm" action="{{ route('admin.don-vi-tinh.bulkDestroy') }}">
            @csrf
        </form>

        {{-- Thanh hành động hàng loạt — chỉ hiện khi đã tick ít nhất một dòng. --}}
        <div class="dvt-bulkbar" id="dvtBulkBar" style="display:none;">
            <span class="dvt-bulkbar__so">Đã chọn <b id="dvtBulkSo">0</b> đơn vị</span>
            <button type="button" class="dvt-bulkbar__bo" id="dvtBulkBo">Bỏ chọn</button>
            <button type="button" class="dvt-btn-ghost is-danger" id="dvtBulkXoa">
                <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M6 6v14a1 1 0 0 0 1 1h10a1 1 0 0 0 1-1V6"/><path d="M10 11v6M14 11v6"/></svg>
                Xoá
            </button>
        </div>

        {{-- Bảng --}}
        <div class="dvt-table-wrap">
            <table class="dvt-table">
                <thead>
                    <tr>
                        <th class="dvt-c-tick">
                            {{-- Chọn hết những dòng ĐANG HIỆN, không phải cả cửa hàng:
                                 bảng đang lọc thì "hết" nghĩa là hết phần đang xem. --}}
                            <input type="checkbox" id="dvtChonHet" title="Chọn hết dòng đang hiện">
                        </th>
                        <th class="dvt-c-stt">STT</th>
                        <th class="dvt-c-code">Mã đơn vị</th>
                        <th class="dvt-c-name">Tên đơn vị</th>
                        <th class="dvt-c-status">Trạng thái</th>
                        <th class="dvt-c-act">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($list as $i => $dv)
                        @php
                            $id = (int) ($dv['id'] ?? 0);
                            $bat = (bool) ($dv['is_active'] ?? false);
                        @endphp
                        <tr>
                            <td class="dvt-c-tick">
                                <input type="checkbox" form="dvtBulkForm" name="ids[]" value="{{ $id }}" data-dvt-chon>
                            </td>
                            <td class="dvt-c-stt dvt-muted">{{ $stt + $i + 1 }}</td>
                            <td class="dvt-c-code"><code class="dvt-code">{{ $dv['code'] ?? '—' }}</code></td>
                            <td class="dvt-c-name"><span class="dvt-name">{{ $dv['name'] ?? '—' }}</span></td>
                            <td class="dvt-c-status">
                                {{-- Công tắc: mỗi dòng một form riêng, gửi ĐÚNG một trường
                                     sang /trang-thai. JS hỏng thì vẫn còn nút submit. --}}
                                <form method="POST" action="{{ route('admin.don-vi-tinh.toggleStatus', $id) }}"
                                      class="dvt-switch-form" data-dvt-status>
                                    @csrf
                                    @method('PUT')
                                    <input type="hidden" name="is_active" value="{{ $bat ? 0 : 1 }}">
                                    <input type="checkbox" class="dvt-switch" @checked($bat)
                                           title="{{ $bat ? 'Đang dùng — bấm để tắt' : 'Đã tắt — bấm để bật lại' }}">
                                    <noscript><button type="submit" class="dvt-btn-ghost">Đổi</button></noscript>
                                </form>
                            </td>
                            <td class="dvt-c-act">
                                <div class="dvt-rowacts">
                                    <button type="button" class="dvt-btn-icon" title="Sửa"
                                            data-dvt-edit data-don-vi="{{ json_encode($dv, JSON_UNESCAPED_UNICODE) }}">
                                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                                    </button>

                                    {{-- Nhân bản: mở hộp thoại THÊM đã điền sẵn tên, mã để
                                         trống. Bản cũ chép luôn cả mã sang, nên bấm Lưu là
                                         ăn ngay lỗi trùng mã — chép cái chắc chắn sai thì
                                         thà đừng chép. --}}
                                    <button type="button" class="dvt-btn-icon" title="Nhân bản"
                                            data-dvt-copy data-don-vi="{{ json_encode($dv, JSON_UNESCAPED_UNICODE) }}">
                                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15H4a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1v1"/></svg>
                                    </button>

                                    {{-- Form riêng từng dòng: nút dùng chung + id nhét bằng JS
                                         thì lúc JS hỏng sẽ xoá nhầm dòng cuối cùng đã gán. --}}
                                    <form method="POST" action="{{ route('admin.don-vi-tinh.destroy', $id) }}"
                                          class="dvt-inline-form" data-dvt-xoa data-ten="{{ $dv['name'] ?? '' }}">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="dvt-btn-icon is-danger" title="Xoá">
                                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M6 6v14a1 1 0 0 0 1 1h10a1 1 0 0 0 1-1V6"/><path d="M10 11v6M14 11v6"/></svg>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="dvt-empty">
                                {{ $hasFilter ? 'Không có đơn vị nào khớp bộ lọc đang bật.' : $C::EMPTY_TEXT }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @include('partials.pagination-v2', [
            'meta' => $meta,
            'noun' => 'đơn vị',
            'perPageOptions' => \App\Http\Controllers\DonViTinhController::MUC_SO_DONG,
        ])
    </div>

    {{-- Modal thêm / sửa --}}
    <div class="dvt-overlay" id="dvtOverlay" style="display:none;">
        <div class="dvt-dialog">
            <div class="dvt-modal-head">
                <h4 class="dvt-modal-title" id="dvtModalTitle">Thêm đơn vị</h4>
                <button type="button" class="dvt-modal-x" data-close>
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </div>

            <form id="dvtForm" method="POST" action="{{ route('admin.don-vi-tinh.store') }}">
                @csrf
                {{-- _method rỗng khi TẠO, "PUT" khi sửa — Laravel bỏ qua giá trị rỗng. --}}
                <input type="hidden" name="_method" id="dvtMethod" value="">
                {{-- Id của dòng đang sửa. Nó nằm trên ĐƯỜNG DẪN chứ không phải trong
                     payload, nên controller bỏ qua ô này; để đây chỉ để lượt Lưu hỏng
                     còn dựng lại được đúng đường sửa khi mở lại hộp thoại. --}}
                <input type="hidden" name="dvt_id" id="dvtId" value="{{ old('dvt_id') }}">

                <div class="dvt-modal-body">
                    <div>
                        <label class="dvt-field-label" for="dvtMa">Mã đơn vị</label>
                        {{-- KHÔNG bắt buộc: bỏ trống thì API tự đặt mã, theo quy tắc
                             đánh số của cửa hàng (Cài đặt → Thông số chung) nếu đã bật,
                             không thì dải DV001. Bản cũ bắt gõ tay, mà đơn vị tính là
                             thứ khai một lần rồi thôi — người khai phải tự nghĩ ra một
                             chuỗi viết tắt vừa không trùng vừa đọc được, cho một ô gần
                             như không ai tra.

                             Và mã SỬA ĐƯỢC cả lúc sửa. Bản cũ khoá ô này lại khi sửa,
                             nên gõ nhầm một chữ lúc thêm là phải xoá đi khai lại. --}}
                        <input type="text" id="dvtMa" name="code" class="dvt-input" maxlength="20"
                               autocomplete="off" placeholder="Bỏ trống để tự đặt DV001…" value="{{ old('code') }}">
                        {{-- <p class="dvt-hint">Chỉ chữ không dấu và số, không khoảng trắng. Tự viết hoa khi lưu.</p> --}}
                    </div>

                    <div>
                        <label class="dvt-field-label" for="dvtTen">Tên đơn vị <span class="dvt-req">*</span></label>
                        <input type="text" id="dvtTen" name="name" class="dvt-input" maxlength="100" required
                               autocomplete="off" placeholder="Ví dụ: Kilogam, Cái, Thùng" value="{{ old('name') }}">
                    </div>

                    <div>
                        <label class="dvt-field-label">Trạng thái</label>
                        <label class="dvt-switch-row">
                            <input type="checkbox" id="dvtTrangThai" class="dvt-switch" @checked(old('is_active', 1))>
                            <span class="dvt-switch-label" id="dvtTrangThaiChu">Đang dùng</span>
                        </label>
                        <input type="hidden" name="is_active" id="dvtTrangThaiValue" value="{{ old('is_active', 1) }}">
                        {{-- <p class="dvt-hint">Tắt đi thì ô chọn đơn vị lúc khai mặt hàng thôi bày nó ra. Mặt hàng cũ vẫn giữ nguyên đơn vị.</p> --}}
                    </div>
                </div>

                <div class="dvt-modal-foot">
                    <button type="button" class="dvt-btn-ghost" data-close>Đóng</button>
                    <button type="submit" class="dvt-btn-primary" id="dvtSubmit">Lưu</button>
                </div>
            </form>
        </div>
    </div>

    {{-- Hộp xác nhận dùng chung cho xoá một dòng và xoá hàng loạt --}}
    <div class="dvt-overlay" id="dvtConfirm" style="display:none;">
        <div class="dvt-dialog is-confirm">
            <div class="dvt-modal-head">
                <h4 class="dvt-modal-title" id="dvtConfirmTitle">Xoá đơn vị?</h4>
                <button type="button" class="dvt-modal-x" data-dvt-confirm-huy>
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </div>
            <div class="dvt-modal-body" id="dvtConfirmBody"></div>
            <div class="dvt-modal-foot">
                <button type="button" class="dvt-btn-ghost" data-dvt-confirm-huy>Huỷ</button>
                <button type="button" class="dvt-btn-primary is-danger" id="dvtConfirmOk">Xoá</button>
            </div>
        </div>
    </div>

    <style>
        .dvt {
            /* Phá padding p-4 của <main> để tràn viền như các trang danh sách khác */
            margin: -1.5rem;
            min-height: calc(100vh - 56px);
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            color: #262626; background: #fff;
        }

        /* Header — cùng khuôn trang Nhân sự / Quản lý thuế. */
        .dvt-head { display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; padding: 14px 20px; }
        .dvt-title { margin: 0; font-size: 16px; font-weight: 700; color: #262626; line-height: 34px; }
        .dvt-sum { font-size: 13px; color: #595959; }
        .dvt-sum b { color: #262626; }

        .dvt-callout {
            display: flex; align-items: flex-start; gap: 8px;
            margin: 0 20px 12px; padding: 10px 12px; border-radius: 4px;
            background: #fff7e6; border: 1px solid #ffd591; color: #874d00; font-size: 13px;
        }
        .dvt-callout.is-error { background: #fff1f0; border-color: #ffa39e; color: #a8071a; }

        /* Bộ lọc */
        .dvt-filter { padding: 0 20px 12px; margin-bottom: 12px; border-bottom: 1px solid #eee; }
        .dvt-toolbar { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; }
        .dvt-toolbar-actions { margin-left: auto; display: flex; align-items: center; gap: 8px; }

        .dvt-searchbox { display: flex; border-radius: 4px; }
        .dvt-searchbox:focus-within { box-shadow: 0 0 0 4px rgba(13,110,253,.25); }
        .dvt-search-input {
            height: 34px; width: 280px; border: 1px solid #d9d9d9; border-right: 0;
            border-radius: 4px 0 0 4px; background: #fff; padding: 0 12px; font-size: 13px; outline: none;
        }
        .dvt-search-input::placeholder { color: #bfbfbf; }
        /* Vòng focus vẽ ở .dvt-searchbox, ô trong không vẽ lại (luật chung ở layout). */
        .dvt-searchbox:focus-within .dvt-search-input,
        .dvt-searchbox:focus-within .dvt-search-btn { border-color: #86b7fe; }
        .dvt-search-input:focus { box-shadow: none !important; }
        .dvt-search-btn {
            height: 34px; width: 40px; display: inline-flex; align-items: center; justify-content: center;
            border: 1px solid #d9d9d9; border-radius: 0 4px 4px 0; background: #fafafa; color: #595959; cursor: pointer;
        }
        .dvt-search-btn:hover { color: #1890ff; }

        .dvt-select {
            height: 34px; border: 1px solid #d9d9d9; border-radius: 4px; background-color: #fff;
            padding: 0 30px 0 12px; font-size: 13px; color: #262626; cursor: pointer; outline: none; max-width: 220px;
            appearance: none; -webkit-appearance: none;
            background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23595959' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><path d='m6 9 6 6 6-6'/></svg>");
            background-repeat: no-repeat; background-position: right 8px center;
        }

        .dvt-clear {
            display: inline-flex; align-items: center; height: 34px; padding: 0 10px; border-radius: 4px;
            font-size: 13px; color: #8c8c8c; text-decoration: none; transition: background .15s, color .15s;
        }
        .dvt-clear:hover { background: #fff1f0; color: #ff4d4f; }

        /* Thanh hành động hàng loạt — NỔI ở đáy màn hình, cùng khuôn trang Nhân sự.
           left: 230px bù đúng bề ngang thanh trái để nó căn giữa VÙNG NỘI DUNG. */
        .dvt-bulkbar {
            position: fixed; bottom: 24px; left: 230px; right: 0; margin-inline: auto; z-index: 1070;
            width: max-content; max-width: calc(100vw - 260px);
            display: flex; align-items: center; gap: 16px;
            border: 1px solid #e6e6e6; border-radius: 9999px;
            background: #fff; padding: 12px 20px; box-shadow: 0 6px 24px rgba(0,0,0,.15);
        }
        body:has(.jh-sidebar.collapsed) .dvt-bulkbar { left: 48px; }
        @media (max-width: 820px) { .dvt-bulkbar { left: 0; } }
        .dvt-bulkbar__so { font-size: 13px; font-weight: 500; color: #262626; }
        .dvt-bulkbar__bo {
            border: 0; background: none; padding: 0;
            font-size: 13px; color: #8c8c8c; cursor: pointer; transition: color .15s;
        }
        .dvt-bulkbar__bo:hover { color: #262626; }
        .dvt-bulkbar .dvt-btn-ghost { border-radius: 9999px; height: 32px; }
        .dvt-btn-ghost.is-danger { border-color: transparent; background: #ff4d4f; color: #fff; }
        .dvt-btn-ghost.is-danger:hover { background: #ff7875; color: #fff; border-color: transparent; }

        /* Bảng — cách sắp xếp của bản cũ v2 (mọi ô canh giữa, ô rộng rãi), MÀU giữ
           của bản hiện tại. */
        .dvt-table-wrap { padding: 0 20px 24px; overflow-x: auto; }
        .dvt-table { width: 100%; min-width: 720px; border-collapse: collapse; font-size: 13.5px; table-layout: fixed; }
        .dvt-table th {
            font-weight: 600; color: #595959; background: #fafafa;
            padding: 12px; border-bottom: 1px solid #f0f0f0; white-space: nowrap;
        }
        .dvt-table th, .dvt-table td { text-align: center; vertical-align: middle; }
        .dvt-table td { padding: 14px 12px; border-bottom: 1px solid #f5f5f5; }
        .dvt-table tbody tr:hover td { background: #fafafa; }

        /* Bề rộng theo TỈ LỆ, tổng đúng 100% — không cột nào bỏ trống, nếu không
           phần dư dồn hết vào một cột và bảng hở ra một khoảng chết. */
        .dvt-table th.dvt-c-tick,   .dvt-table td.dvt-c-tick   { width: 5%; }
        .dvt-table th.dvt-c-stt,    .dvt-table td.dvt-c-stt    { width: 7%; }
        .dvt-table th.dvt-c-code,   .dvt-table td.dvt-c-code   { width: 20%; }
        .dvt-table th.dvt-c-name,   .dvt-table td.dvt-c-name   { width: 40%; }
        .dvt-table th.dvt-c-status, .dvt-table td.dvt-c-status { width: 13%; }
        .dvt-table th.dvt-c-act,    .dvt-table td.dvt-c-act    { width: 15%; }

        .dvt-name { font-weight: 600; }
        .dvt-muted { color: #8c8c8c; }
        .dvt-code { font-size: 12px; background: #f5f5f5; border-radius: 3px; padding: 2px 8px; color: #595959; }
        .dvt-empty { text-align: center; color: #8c8c8c; padding: 32px 12px; }

        /* Nút thao tác: ô vuông bo góc có viền, lúc thường xám, rê chuột mới ăn màu. */
        .dvt-rowacts { display: flex; align-items: center; justify-content: center; gap: 6px; }
        .dvt-inline-form { display: inline; margin: 0; }
        .dvt-btn-icon {
            display: inline-flex; align-items: center; justify-content: center;
            width: 30px; height: 30px; border-radius: 4px;
            border: 1px solid #d9d9d9; background: #fff; color: #595959; cursor: pointer;
        }
        .dvt-btn-icon:hover { border-color: #0d6efd; color: #0d6efd; }
        .dvt-btn-icon.is-danger:hover { border-color: #ff4d4f; color: #ff4d4f; }

        .dvt-btn-primary {
            display: inline-flex; align-items: center; gap: 6px;
            height: 34px; padding: 0 14px; border-radius: 4px; border: 0;
            background: #0d6efd; color: #fff; font-size: 13px; font-weight: 600; cursor: pointer;
        }
        .dvt-btn-primary:hover { background: #0b5ed7; }
        .dvt-btn-primary:disabled { opacity: .55; cursor: default; }
        /* Nút Đồng ý của hộp xác nhận tô ĐỎ: hai nút xanh giống hệt nhau thì người
           đọc bấm theo vị trí, không theo chữ. */
        .dvt-btn-primary.is-danger { background: #ff4d4f; }
        .dvt-btn-primary.is-danger:hover { background: #ff7875; }
        .dvt-btn-ghost {
            display: inline-flex; align-items: center; gap: 6px;
            height: 34px; padding: 0 14px; border-radius: 4px; text-decoration: none;
            border: 1px solid #d9d9d9; background: #fff; color: #595959; font-size: 13px; cursor: pointer;
            white-space: nowrap;
        }
        .dvt-btn-ghost:hover { color: #0d6efd; border-color: #0d6efd; }

        /* Công tắc — dáng của input.switch_customer bên bản cũ, đổi màu bật. */
        .dvt-switch {
            appearance: none; -webkit-appearance: none;
            width: 2.6em; height: 1.4em; margin: 0;
            background: #dcdcdc; border: 0; border-radius: 3em;
            position: relative; cursor: pointer; outline: none;
            transition: background .2s ease-in-out; flex-shrink: 0;
        }
        .dvt-switch:checked { background: #0d6efd; }
        .dvt-switch::after {
            content: ''; position: absolute; left: 0; top: 0;
            width: 1.4em; height: 1.4em; border-radius: 50%;
            background: #fff; box-shadow: 0 0 .25em rgba(0,0,0,.3);
            transform: scale(.72); transition: left .2s ease-in-out;
        }
        .dvt-switch:checked::after { left: calc(100% - 1.4em); }
        .dvt-switch:disabled { opacity: .5; cursor: not-allowed; }
        .dvt-switch-row { display: inline-flex; align-items: center; gap: 10px; cursor: pointer; }
        .dvt-switch-label { font-size: 13px; color: #262626; }
        /* Công tắc trong bảng nằm trong form riêng nên phải bỏ margin. */
        .dvt-switch-form { display: inline-flex; margin: 0; }

        /* Modal */
        .dvt-overlay { position: fixed; inset: 0; background: rgba(0,0,0,.45); z-index: 1050; display: flex; align-items: center; justify-content: center; padding: 16px; }
        .dvt-dialog { width: 100%; max-width: 460px; max-height: calc(100vh - 32px); display: flex; flex-direction: column; background: #fff; border-radius: 6px; box-shadow: 0 6px 24px rgba(0,0,0,.2); }
        .dvt-dialog form { display: flex; flex-direction: column; min-height: 0; }
        .dvt-dialog.is-confirm { max-width: 420px; }
        .dvt-modal-head { display: flex; align-items: center; justify-content: space-between; padding: 14px 20px; border-bottom: 1px solid #f0f0f0; }
        .dvt-modal-title { margin: 0; font-size: 15px; font-weight: 700; }
        .dvt-modal-x { border: 0; background: none; color: #8c8c8c; cursor: pointer; line-height: 0; }
        .dvt-modal-x:hover { color: #262626; }
        .dvt-modal-body { padding: 16px 20px; display: flex; flex-direction: column; gap: 14px; overflow-y: auto; }
        .dvt-modal-body p { margin: 0; font-size: 13px; color: #595959; }
        /* Hàng nút ở chân hộp thoại luôn CANH GIỮA — quy tắc chung của dự án. */
        .dvt-modal-foot { display: flex; justify-content: center; gap: 8px; padding: 12px 20px; border-top: 1px solid #f0f0f0; background: #fafafa; border-radius: 0 0 6px 6px; }

        .dvt-field-label { display: block; font-size: 12.5px; font-weight: 600; color: #434343; margin-bottom: 5px; }
        .dvt-req { color: #ff4d4f; }
        .dvt-input {
            width: 100%; height: 36px; border: 1px solid #d9d9d9; border-radius: 4px;
            padding: 0 12px; font-size: 13px; outline: none; background-color: #fff; color: #262626;
        }
        .dvt-hint { margin: 4px 0 0; font-size: 12px; color: #8c8c8c; }
    </style>

    <script>
        (function () {
            // ---------- Bộ lọc realtime: đổi select -> chạy ngay; gõ -> chờ 400ms ----------
            var filter = document.getElementById('dvtFilter');
            filter.querySelectorAll('select').forEach(function (sel) {
                sel.addEventListener('change', function () { filter.submit(); });
            });
            var search = filter.querySelector('input[name="keyword"]');
            var searchTimer = null;
            search.addEventListener('input', function () {
                clearTimeout(searchTimer);
                searchTimer = setTimeout(function () { filter.submit(); }, 400);
            });

            var overlay = document.getElementById('dvtOverlay');
            var form = document.getElementById('dvtForm');
            var method = document.getElementById('dvtMethod');
            var title = document.getElementById('dvtModalTitle');
            var submit = document.getElementById('dvtSubmit');

            var oId = document.getElementById('dvtId');
            var oMa = document.getElementById('dvtMa');
            var oTen = document.getElementById('dvtTen');
            var oBat = document.getElementById('dvtTrangThai');
            var oBatChu = document.getElementById('dvtTrangThaiChu');
            var oBatValue = document.getElementById('dvtTrangThaiValue');

            var STORE = @json(route('admin.don-vi-tinh.store'));
            // Khuôn đường sửa: thay 0 bằng id thật. Dựng bằng route() để đường dẫn
            // vẫn đúng nếu prefix của nhóm route đổi.
            var UPDATE = @json(route('admin.don-vi-tinh.update', 0));

            // ---------- Hộp xác nhận dùng chung ----------
            //
            // Thay chỗ confirm() của trình duyệt: đặt được chữ trên từng nút và nói
            // rõ xoá cái gì. Bất đồng bộ nên nơi gọi đưa việc cần làm vào `xong`.
            var hopHoi = document.getElementById('dvtConfirm');
            var hopHoiTieuDe = document.getElementById('dvtConfirmTitle');
            var hopHoiNoiDung = document.getElementById('dvtConfirmBody');
            var hopHoiOk = document.getElementById('dvtConfirmOk');
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

                // textContent từng đoạn: tên đơn vị là chữ do người dùng gõ, nối
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
            document.querySelectorAll('[data-dvt-confirm-huy]').forEach(function (el) {
                el.addEventListener('click', function () { dongHoi(false); });
            });
            // Bấm ra ngoài = Huỷ, y như hộp thêm/sửa.
            hopHoi.addEventListener('click', function (e) { if (e.target === hopHoi) dongHoi(false); });

            // ---------- Hộp thêm / sửa ----------

            function veCongTac() {
                oBatValue.value = oBat.checked ? '1' : '0';
                oBatChu.textContent = oBat.checked ? 'Đang dùng' : 'Đã tắt';
            }
            oBat.addEventListener('change', veCongTac);

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
                veCongTac();
                submit.disabled = false;
                overlay.style.display = 'flex';
                (o.oDau || oMa).focus();
            }

            function dongForm() { overlay.style.display = 'none'; }

            document.querySelector('[data-dvt-add]').addEventListener('click', function () {
                moForm({ tieuDe: 'Thêm đơn vị' });
            });

            document.querySelectorAll('[data-dvt-edit]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var dv = JSON.parse(btn.dataset.donVi);
                    moForm({
                        tieuDe: 'Sửa đơn vị',
                        id: dv.id,
                        code: dv.code,
                        name: dv.name,
                        isActive: !!dv.is_active,
                        oDau: oTen,
                    });
                });
            });

            // Nhân bản: chép TÊN, để trống mã. Mã bắt buộc phải khác nên chép sang
            // chỉ tổ ăn lỗi trùng ngay lượt Lưu đầu tiên.
            document.querySelectorAll('[data-dvt-copy]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var dv = JSON.parse(btn.dataset.donVi);
                    moForm({
                        tieuDe: 'Nhân bản đơn vị',
                        name: (dv.name || '') + ' (bản sao)',
                        isActive: !!dv.is_active,
                    });
                });
            });

            document.querySelectorAll('#dvtOverlay [data-close]').forEach(function (el) {
                el.addEventListener('click', dongForm);
            });
            overlay.addEventListener('click', function (e) { if (e.target === overlay) dongForm(); });
            document.addEventListener('keydown', function (e) {
                if (e.key !== 'Escape') return;
                if (hopHoi.style.display === 'flex') { dongHoi(false); return; }
                if (overlay.style.display === 'flex') dongForm();
            });

            // Khoá nút Lưu ngay khi gửi: bấm hai lần liên tiếp lúc mạng chậm là hai
            // đơn vị trùng tên.
            form.addEventListener('submit', function () { submit.disabled = true; });

            // Bấm Lưu mà API từ chối thì mở lại hộp thoại kèm dữ liệu vừa gõ.
            @if($moLaiForm)
                moForm({
                    tieuDe: @json((int) old('dvt_id', 0) > 0 ? 'Sửa đơn vị' : 'Thêm đơn vị'),
                    id: @json((int) old('dvt_id', 0)),
                    code: @json(old('code', '')),
                    name: @json(old('name', '')),
                    isActive: @json((bool) old('is_active', 1)),
                });
            @endif

            // ---------- Công tắc trạng thái trên bảng ----------
            //
            // Gạt là gửi form của chính dòng đó, rồi khoá lại để hai cú gạt liên
            // tiếp không thành hai lượt ghi ngược nhau.
            document.querySelectorAll('[data-dvt-status]').forEach(function (f) {
                var sw = f.querySelector('.dvt-switch');
                sw.addEventListener('change', function () {
                    sw.disabled = true;
                    f.submit();
                });
            });

            // ---------- Xoá một dòng ----------
            document.querySelectorAll('[data-dvt-xoa]').forEach(function (f) {
                f.addEventListener('submit', function (e) {
                    e.preventDefault();
                    hoi({
                        tieuDe: 'Xoá đơn vị?',
                        doan: [
                            'Xoá đơn vị "' + f.dataset.ten + '".',
                            'Mã của nó vẫn bị giữ chỗ sau khi xoá, không đặt lại cho đơn vị khác được. '
                                + 'Chỉ muốn thôi bày nó ra ở ô chọn thì TẮT đi, đừng xoá.',
                        ],
                    }, function (dongY) {
                        if (dongY) f.submit();
                    });
                });
            });

            // ---------- Chọn nhiều dòng ----------
            var bulkForm = document.getElementById('dvtBulkForm');
            var bulkBar = document.getElementById('dvtBulkBar');
            var bulkSo = document.getElementById('dvtBulkSo');
            var chonHet = document.getElementById('dvtChonHet');
            var oChon = Array.prototype.slice.call(document.querySelectorAll('[data-dvt-chon]'));

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
            document.getElementById('dvtBulkBo').addEventListener('click', function () {
                oChon.forEach(function (o) { o.checked = false; });
                demLai();
            });

            document.getElementById('dvtBulkXoa').addEventListener('click', function () {
                var da = oChon.filter(function (o) { return o.checked; });
                if (!da.length) return;
                hoi({
                    tieuDe: 'Xoá ' + da.length + ' đơn vị?',
                    doan: [
                        'Xoá ' + da.length + ' đơn vị đã chọn.',
                        'Mã của chúng vẫn bị giữ chỗ sau khi xoá, không đặt lại cho đơn vị khác được. '
                            + 'Chỉ muốn thôi bày chúng ra ở ô chọn thì TẮT đi, đừng xoá.',
                    ],
                }, function (dongY) {
                    if (dongY) bulkForm.submit();
                });
            });

            demLai();
        })();
    </script>
@endsection
