@extends('layouts.app')

@section('title', \App\Http\Controllers\ViTriController::TITLE_PAGE)

@section('content')
    {{--
        Trang "Vị trí" — chỗ để hàng trong cửa hàng/kho:
        [ header ] + [ thanh lọc ] + [ bảng mã/tên/trạng thái ] + [ modal thêm/sửa ].

        Bản cũ v2 KHÔNG có màn này — Menu QR bên đó chỉ tới Hoa hồng là hết, còn
        menu/hrm-position là CHỨC VỤ nhân sự, không dính gì tới hàng hoá. Nên màn
        này lấy nguyên khuôn trang Đơn vị tính (cùng là bảng tra mã + tên) và giữ
        cả bốn điều đã sửa ở đó:

        - Lọc realtime, không có nút kính lúp (quy tắc chung của trang danh sách).
        - Nút "Thêm vị trí" nằm CUỐI thanh lọc, không nằm cạnh tiêu đề.
        - Ô Trạng thái sửa được ngay trong hộp thoại, không phải đóng ra gạt ngoài bảng.
        - Mọi cột khai bề rộng theo %, tổng đúng 100% — không cột nào bỏ trống.
    --}}
    @php
        $C = \App\Http\Controllers\ViTriController::class;
        $TITLE = $C::TITLE_PAGE;

        $so = fn ($n) => number_format((float) $n, 0, ',', '.');
        $hasFilter = $filters['keyword'] !== '' || $filters['status'] !== '';

        // Bấm Lưu mà hỏng thì mở lại hộp thoại kèm dữ liệu vừa gõ.
        $moLaiForm = filled(old('name'));
    @endphp

    <div class="vt">
        {{-- Header: tiêu đề + một dòng tổng. Nút "Thêm" nằm cuối thanh lọc. --}}
        <div class="vt-head">
            <h1 class="vt-title">{{ $TITLE }}</h1>
            <span class="vt-sum">
                Đang dùng: <b>{{ $so($dangDung) }}</b>/{{ $so($tong) }} vị trí
            </span>
        </div>

        @if(!empty($error))
            <p class="vt-callout is-error">{{ $error }}</p>
        @endif

        {{-- Bộ lọc realtime: đổi select chạy ngay, gõ thì chờ 400ms. Không có nút
             "Lọc" — quy tắc chung của mọi trang danh sách trong dự án. --}}
        <form method="GET" action="{{ route('admin.vi-tri.index') }}" id="vtFilter" class="vt-filter">
            <div class="vt-toolbar">
                <div class="vt-searchbox">
                    <input type="text" name="keyword" value="{{ $filters['keyword'] }}" class="vt-search-input"
                           placeholder="Tìm theo tên hoặc mã vị trí" autocomplete="off">
                    <button type="submit" class="vt-search-btn" title="Tìm kiếm">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                    </button>
                </div>

                <select name="status" class="vt-select" title="Lọc theo trạng thái">
                    <option value="">Tất cả trạng thái</option>
                    @foreach($C::TRANG_THAI as $ma => $ten)
                        <option value="{{ $ma }}" @selected($filters['status'] === $ma)>{{ $ten }}</option>
                    @endforeach
                </select>

                {{-- "Xoá lọc" ở hàng chính: một điều kiện đang ẩn mà không thấy đường
                     gỡ ra là cách nhanh nhất để tưởng danh sách mất dữ liệu. --}}
                @if($hasFilter)
                    <a href="{{ route('admin.vi-tri.index') }}" class="vt-clear">Xoá lọc</a>
                @endif

                <div class="vt-toolbar-actions">
                    <button type="button" class="vt-btn-primary" data-vt-add>
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M12 5v14"/></svg>
                        Thêm vị trí
                    </button>
                </div>
            </div>
        </form>

        {{-- Form RỖNG, đứng ngoài bảng. Ô tick trong bảng nối vào nó bằng thuộc tính
             `form="vtBulkForm"` chứ KHÔNG bọc bảng lại: mỗi dòng đã có form riêng
             cho công tắc trạng thái và nút xoá, mà form lồng form thì HTML không
             cho — trình duyệt bỏ cái bên trong, và hai nút ấy chết. --}}
        <form method="POST" id="vtBulkForm" action="{{ route('admin.vi-tri.bulkDestroy') }}">
            @csrf
        </form>

        {{-- Thanh hành động hàng loạt — chỉ hiện khi đã tick ít nhất một dòng. --}}
        <div class="vt-bulkbar" id="vtBulkBar" style="display:none;">
            <span class="vt-bulkbar__so">Đã chọn <b id="vtBulkSo">0</b> vị trí</span>
            <button type="button" class="vt-bulkbar__bo" id="vtBulkBo">Bỏ chọn</button>
            <button type="button" class="vt-btn-ghost is-danger" id="vtBulkXoa">
                <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M6 6v14a1 1 0 0 0 1 1h10a1 1 0 0 0 1-1V6"/><path d="M10 11v6M14 11v6"/></svg>
                Xoá
            </button>
        </div>

        {{-- Bảng --}}
        <div class="vt-table-wrap">
            <table class="vt-table">
                <thead>
                    <tr>
                        <th class="vt-c-tick">
                            {{-- Chọn hết những dòng ĐANG HIỆN, không phải cả cửa hàng:
                                 bảng đang lọc thì "hết" nghĩa là hết phần đang xem. --}}
                            <input type="checkbox" id="vtChonHet" title="Chọn hết dòng đang hiện">
                        </th>
                        <th class="vt-c-stt">STT</th>
                        <th class="vt-c-code">Mã vị trí</th>
                        <th class="vt-c-name">Tên vị trí</th>
                        <th class="vt-c-status">Trạng thái</th>
                        <th class="vt-c-act">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($list as $i => $vt)
                        @php
                            $id = (int) ($vt['id'] ?? 0);
                            $bat = (bool) ($vt['is_active'] ?? false);
                            $dangDungRow = (bool) ($vt['in_use'] ?? false);
                        @endphp
                        <tr>
                            <td class="vt-c-tick">
                                <input type="checkbox" form="vtBulkForm" name="ids[]" value="{{ $id }}" data-vt-chon>
                            </td>
                            <td class="vt-c-stt vt-muted">{{ $stt + $i + 1 }}</td>
                            <td class="vt-c-code"><code class="vt-code">{{ $vt['code'] ?? '—' }}</code></td>
                            <td class="vt-c-name"><span class="vt-name">{{ $vt['name'] ?? '—' }}</span></td>
                            <td class="vt-c-status">
                                {{-- Công tắc: mỗi dòng một form riêng, gửi ĐÚNG một trường
                                     sang /trang-thai. JS hỏng thì vẫn còn nút submit. --}}
                                <form method="POST" action="{{ route('admin.vi-tri.toggleStatus', $id) }}"
                                      class="vt-switch-form" data-vt-status>
                                    @csrf
                                    @method('PUT')
                                    <input type="hidden" name="is_active" value="{{ $bat ? 0 : 1 }}">
                                    <input type="checkbox" class="vt-switch" @checked($bat)
                                           title="{{ $bat ? 'Đang dùng — bấm để tắt' : 'Đã tắt — bấm để bật lại' }}">
                                    <noscript><button type="submit" class="vt-btn-ghost">Đổi</button></noscript>
                                </form>
                            </td>
                            <td class="vt-c-act">
                                <div class="vt-rowacts">
                                    <button type="button" class="vt-btn-icon" title="Sửa"
                                            data-vt-edit data-vi-tri="{{ json_encode($vt, JSON_UNESCAPED_UNICODE) }}">
                                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                                    </button>

                                    {{-- Nhân bản: mở hộp thoại THÊM đã điền sẵn tên, mã để
                                         trống. Mã bắt buộc phải khác nên chép sang chỉ tổ
                                         ăn lỗi trùng ngay lượt Lưu đầu tiên. --}}
                                    <button type="button" class="vt-btn-icon" title="Nhân bản"
                                            data-vt-copy data-vi-tri="{{ json_encode($vt, JSON_UNESCAPED_UNICODE) }}">
                                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15H4a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1v1"/></svg>
                                    </button>

                                    @if($dangDungRow)
                                        {{-- Còn mặt hàng để ở đây: nút xoá xám hẳn và nói LÝ DO
                                             ngay ở tooltip, thay vì cho bấm rồi mới báo lỗi. --}}
                                        <button type="button" class="vt-btn-icon is-off" disabled
                                                title="Còn mặt hàng để ở vị trí này — không xoá được. Chuyển chúng sang chỗ khác trước, hoặc tắt vị trí này đi nếu chỉ muốn thôi bày ra.">
                                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M6 6v14a1 1 0 0 0 1 1h10a1 1 0 0 0 1-1V6"/><path d="M10 11v6M14 11v6"/></svg>
                                        </button>
                                    @else
                                        {{-- Form riêng từng dòng: nút dùng chung + id nhét bằng JS
                                             thì lúc JS hỏng sẽ xoá nhầm dòng cuối cùng đã gán. --}}
                                        <form method="POST" action="{{ route('admin.vi-tri.destroy', $id) }}"
                                              class="vt-inline-form" data-vt-xoa data-ten="{{ $vt['name'] ?? '' }}">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="vt-btn-icon is-danger" title="Xoá">
                                                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M6 6v14a1 1 0 0 0 1 1h10a1 1 0 0 0 1-1V6"/><path d="M10 11v6M14 11v6"/></svg>
                                            </button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="vt-empty">
                                {{ $hasFilter ? 'Không có vị trí nào khớp bộ lọc đang bật.' : $C::EMPTY_TEXT }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @include('partials.pagination-v2', [
            'meta' => $meta,
            'noun' => 'vị trí',
            'perPageOptions' => \App\Http\Controllers\ViTriController::MUC_SO_DONG,
        ])
    </div>

    {{-- Modal thêm / sửa --}}
    <div class="vt-overlay" id="vtOverlay" style="display:none;">
        <div class="vt-dialog">
            <div class="vt-modal-head">
                <h4 class="vt-modal-title" id="vtModalTitle">Thêm vị trí</h4>
                <button type="button" class="vt-modal-x" data-close>
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </div>

            <form id="vtForm" method="POST" action="{{ route('admin.vi-tri.store') }}">
                @csrf
                {{-- _method rỗng khi TẠO, "PUT" khi sửa — Laravel bỏ qua giá trị rỗng. --}}
                <input type="hidden" name="_method" id="vtMethod" value="">
                {{-- Id của dòng đang sửa. Nó nằm trên ĐƯỜNG DẪN chứ không phải trong
                     payload, nên controller bỏ qua ô này; để đây chỉ để lượt Lưu hỏng
                     còn dựng lại được đúng đường sửa khi mở lại hộp thoại. --}}
                <input type="hidden" name="vt_id" id="vtId" value="{{ old('vt_id') }}">

                <div class="vt-modal-body">
                    <div>
                        <label class="vt-field-label" for="vtMa">Mã vị trí</label>
                        {{-- KHÔNG bắt buộc: bỏ trống thì API tự đặt mã, theo quy tắc
                             đánh số của cửa hàng (Cài đặt → Thông số chung) nếu đã bật,
                             không thì dải VT001. Cửa hàng nào dán mã lên kệ thì gõ tay
                             đúng chuỗi đang dán; không thì để trống cho nhanh.

                             Và mã SỬA ĐƯỢC cả lúc sửa, giống trang Đơn vị tính: gõ
                             nhầm một chữ lúc thêm không đáng phải xoá đi khai lại. --}}
                        <input type="text" id="vtMa" name="code" class="vt-input" maxlength="20"
                               autocomplete="off" placeholder="Bỏ trống để tự đặt VT001…" value="{{ old('code') }}">
                        {{-- <p class="vt-hint">Chỉ chữ không dấu và số, không khoảng trắng. Tự viết hoa khi lưu.</p> --}}
                    </div>

                    <div>
                        <label class="vt-field-label" for="vtTen">Tên vị trí <span class="vt-req">*</span></label>
                        <input type="text" id="vtTen" name="name" class="vt-input" maxlength="100" required
                               autocomplete="off" placeholder="Ví dụ: Kệ A - Tầng 1, Kho lạnh, Quầy trước" value="{{ old('name') }}">
                    </div>

                    <div>
                        <label class="vt-field-label">Trạng thái</label>
                        <label class="vt-switch-row">
                            <input type="checkbox" id="vtTrangThai" class="vt-switch" @checked(old('is_active', 1))>
                            <span class="vt-switch-label" id="vtTrangThaiChu">Đang dùng</span>
                        </label>
                        <input type="hidden" name="is_active" id="vtTrangThaiValue" value="{{ old('is_active', 1) }}">
                        {{-- <p class="vt-hint">Tắt đi thì ô chọn vị trí lúc khai mặt hàng thôi bày nó ra. Mặt hàng cũ vẫn giữ nguyên vị trí.</p> --}}
                    </div>
                </div>

                <div class="vt-modal-foot">
                    <button type="button" class="vt-btn-ghost" data-close>Đóng</button>
                    <button type="submit" class="vt-btn-primary" id="vtSubmit">Lưu</button>
                </div>
            </form>
        </div>
    </div>

    {{-- Hộp xác nhận dùng chung cho xoá một dòng và xoá hàng loạt --}}
    <div class="vt-overlay" id="vtConfirm" style="display:none;">
        <div class="vt-dialog is-confirm">
            <div class="vt-modal-head">
                <h4 class="vt-modal-title" id="vtConfirmTitle">Xoá vị trí?</h4>
                <button type="button" class="vt-modal-x" data-vt-confirm-huy>
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </div>
            <div class="vt-modal-body" id="vtConfirmBody"></div>
            <div class="vt-modal-foot">
                <button type="button" class="vt-btn-ghost" data-vt-confirm-huy>Huỷ</button>
                <button type="button" class="vt-btn-primary is-danger" id="vtConfirmOk">Xoá</button>
            </div>
        </div>
    </div>

    <style>
        .vt {
            /* Phá padding p-4 của <main> để tràn viền như các trang danh sách khác */
            margin: -1.5rem;
            min-height: calc(100vh - 56px);
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            color: #262626; background: #fff;
        }

        /* Header — cùng khuôn trang Nhân sự / Quản lý thuế. */
        .vt-head { display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; padding: 14px 20px; }
        .vt-title { margin: 0; font-size: 16px; font-weight: 700; color: #262626; line-height: 34px; }
        .vt-sum { font-size: 13px; color: #595959; }
        .vt-sum b { color: #262626; }

        .vt-callout {
            display: flex; align-items: flex-start; gap: 8px;
            margin: 0 20px 12px; padding: 10px 12px; border-radius: 4px;
            background: #fff7e6; border: 1px solid #ffd591; color: #874d00; font-size: 13px;
        }
        .vt-callout.is-error { background: #fff1f0; border-color: #ffa39e; color: #a8071a; }

        /* Bộ lọc */
        .vt-filter { padding: 0 20px 12px; margin-bottom: 12px; border-bottom: 1px solid #eee; }
        .vt-toolbar { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; }
        .vt-toolbar-actions { margin-left: auto; display: flex; align-items: center; gap: 8px; }

        .vt-searchbox { display: flex; border-radius: 4px; }
        .vt-searchbox:focus-within { box-shadow: 0 0 0 4px rgba(13,110,253,.25); }
        .vt-search-input {
            height: 34px; width: 280px; border: 1px solid #d9d9d9; border-right: 0;
            border-radius: 4px 0 0 4px; background: #fff; padding: 0 12px; font-size: 13px; outline: none;
        }
        .vt-search-input::placeholder { color: #bfbfbf; }
        /* Vòng focus vẽ ở .vt-searchbox, ô trong không vẽ lại (luật chung ở layout). */
        .vt-searchbox:focus-within .vt-search-input,
        .vt-searchbox:focus-within .vt-search-btn { border-color: #86b7fe; }
        .vt-search-input:focus { box-shadow: none !important; }
        .vt-search-btn {
            height: 34px; width: 40px; display: inline-flex; align-items: center; justify-content: center;
            border: 1px solid #d9d9d9; border-radius: 0 4px 4px 0; background: #fafafa; color: #595959; cursor: pointer;
        }
        .vt-search-btn:hover { color: #1890ff; }

        .vt-select {
            height: 34px; border: 1px solid #d9d9d9; border-radius: 4px; background-color: #fff;
            padding: 0 30px 0 12px; font-size: 13px; color: #262626; cursor: pointer; outline: none; max-width: 220px;
            appearance: none; -webkit-appearance: none;
            background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23595959' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><path d='m6 9 6 6 6-6'/></svg>");
            background-repeat: no-repeat; background-position: right 8px center;
        }

        .vt-clear {
            display: inline-flex; align-items: center; height: 34px; padding: 0 10px; border-radius: 4px;
            font-size: 13px; color: #8c8c8c; text-decoration: none; transition: background .15s, color .15s;
        }
        .vt-clear:hover { background: #fff1f0; color: #ff4d4f; }

        /* Thanh hành động hàng loạt — NỔI ở đáy màn hình, cùng khuôn trang Nhân sự.
           left: 230px bù đúng bề ngang thanh trái để nó căn giữa VÙNG NỘI DUNG. */
        .vt-bulkbar {
            position: fixed; bottom: 24px; left: 230px; right: 0; margin-inline: auto; z-index: 1070;
            width: max-content; max-width: calc(100vw - 260px);
            display: flex; align-items: center; gap: 16px;
            border: 1px solid #e6e6e6; border-radius: 9999px;
            background: #fff; padding: 12px 20px; box-shadow: 0 6px 24px rgba(0,0,0,.15);
        }
        body:has(.jh-sidebar.collapsed) .vt-bulkbar { left: 48px; }
        @media (max-width: 820px) { .vt-bulkbar { left: 0; } }
        .vt-bulkbar__so { font-size: 13px; font-weight: 500; color: #262626; }
        .vt-bulkbar__bo {
            border: 0; background: none; padding: 0;
            font-size: 13px; color: #8c8c8c; cursor: pointer; transition: color .15s;
        }
        .vt-bulkbar__bo:hover { color: #262626; }
        .vt-bulkbar .vt-btn-ghost { border-radius: 9999px; height: 32px; }
        .vt-btn-ghost.is-danger { border-color: transparent; background: #ff4d4f; color: #fff; }
        .vt-btn-ghost.is-danger:hover { background: #ff7875; color: #fff; border-color: transparent; }

        /* Bảng — cách sắp xếp của bản cũ v2 (mọi ô canh giữa, ô rộng rãi), MÀU giữ
           của bản hiện tại. */
        .vt-table-wrap { padding: 0 20px 24px; overflow-x: auto; }
        .vt-table { width: 100%; min-width: 720px; border-collapse: collapse; font-size: 13.5px; table-layout: fixed; }
        .vt-table th {
            font-weight: 600; color: #595959; background: #fafafa;
            padding: 12px; border-bottom: 1px solid #f0f0f0; white-space: nowrap;
        }
        .vt-table th, .vt-table td { text-align: center; vertical-align: middle; white-space: nowrap; }
        /* Ô nằm một dòng; cột chữ dài cắt bằng dấu ba chấm, không thì
           chữ tràn sang ô bên cạnh. Riêng dòng "chưa có dữ liệu" là một
           câu dài nên cho xuống hàng. */
        .vt-table td.vt-c-name { overflow: hidden; text-overflow: ellipsis; }
        .vt-empty { white-space: normal; }

        .vt-table td { padding: 14px 12px; border-bottom: 1px solid #f5f5f5; }
        .vt-table tbody tr:hover td { background: #fafafa; }

        /* Bề rộng theo TỈ LỆ, tổng đúng 100% — không cột nào bỏ trống, nếu không
           phần dư dồn hết vào một cột và bảng hở ra một khoảng chết. */
        .vt-table th.vt-c-tick,   .vt-table td.vt-c-tick   { width: 5%; }
        .vt-table th.vt-c-stt,    .vt-table td.vt-c-stt    { width: 7%; }
        .vt-table th.vt-c-code,   .vt-table td.vt-c-code   { width: 20%; }
        .vt-table th.vt-c-name,   .vt-table td.vt-c-name   { width: 40%; }
        .vt-table th.vt-c-status, .vt-table td.vt-c-status { width: 13%; }
        .vt-table th.vt-c-act,    .vt-table td.vt-c-act    { width: 15%; }

        .vt-name { font-weight: 600; }
        .vt-muted { color: #8c8c8c; }
        .vt-code { font-size: 12px; background: #f5f5f5; border-radius: 3px; padding: 2px 8px; color: #595959; }
        .vt-empty { text-align: center; color: #8c8c8c; padding: 32px 12px; }

        /* Nút thao tác: ô vuông bo góc có viền, lúc thường xám, rê chuột mới ăn màu. */
        .vt-rowacts { display: flex; align-items: center; justify-content: center; gap: 6px; }
        .vt-inline-form { display: inline; margin: 0; }
        .vt-btn-icon {
            display: inline-flex; align-items: center; justify-content: center;
            width: 30px; height: 30px; border-radius: 4px;
            border: 1px solid #d9d9d9; background: #fff; color: #595959; cursor: pointer;
        }
        .vt-btn-icon:hover { border-color: #0d6efd; color: #0d6efd; }
        .vt-btn-icon.is-danger:hover { border-color: #ff4d4f; color: #ff4d4f; }
        .vt-btn-icon.is-off { color: #d9d9d9; border-color: #ebebeb; cursor: not-allowed; }
        .vt-btn-icon.is-off:hover { border-color: #ebebeb; color: #d9d9d9; }

        .vt-btn-primary {
            display: inline-flex; align-items: center; gap: 6px;
            height: 34px; padding: 0 14px; border-radius: 4px; border: 0;
            background: #0d6efd; color: #fff; font-size: 13px; font-weight: 600; cursor: pointer;
        }
        .vt-btn-primary:hover { background: #0b5ed7; }
        .vt-btn-primary:disabled { opacity: .55; cursor: default; }
        /* Nút Đồng ý của hộp xác nhận tô ĐỎ: hai nút xanh giống hệt nhau thì người
           đọc bấm theo vị trí, không theo chữ. */
        .vt-btn-primary.is-danger { background: #ff4d4f; }
        .vt-btn-primary.is-danger:hover { background: #ff7875; }
        .vt-btn-ghost {
            display: inline-flex; align-items: center; gap: 6px;
            height: 34px; padding: 0 14px; border-radius: 4px; text-decoration: none;
            border: 1px solid #d9d9d9; background: #fff; color: #595959; font-size: 13px; cursor: pointer;
            white-space: nowrap;
        }
        .vt-btn-ghost:hover { color: #0d6efd; border-color: #0d6efd; }

        /* Công tắc — dáng của input.switch_customer bên bản cũ, đổi màu bật. */
        .vt-switch {
            appearance: none; -webkit-appearance: none;
            width: 2.6em; height: 1.4em; margin: 0;
            background: #dcdcdc; border: 0; border-radius: 3em;
            position: relative; cursor: pointer; outline: none;
            transition: background .2s ease-in-out; flex-shrink: 0;
        }
        .vt-switch:checked { background: #0d6efd; }
        .vt-switch::after {
            content: ''; position: absolute; left: 0; top: 0;
            width: 1.4em; height: 1.4em; border-radius: 50%;
            background: #fff; box-shadow: 0 0 .25em rgba(0,0,0,.3);
            transform: scale(.72); transition: left .2s ease-in-out;
        }
        .vt-switch:checked::after { left: calc(100% - 1.4em); }
        .vt-switch:disabled { opacity: .5; cursor: not-allowed; }
        .vt-switch-row { display: inline-flex; align-items: center; gap: 10px; cursor: pointer; }
        .vt-switch-label { font-size: 13px; color: #262626; }
        /* Công tắc trong bảng nằm trong form riêng nên phải bỏ margin. */
        .vt-switch-form { display: inline-flex; margin: 0; }

        /* Modal */
        .vt-overlay { position: fixed; inset: 0; background: rgba(0,0,0,.45); z-index: 1050; display: flex; align-items: center; justify-content: center; padding: 16px; }
        .vt-dialog { width: 100%; max-width: 460px; max-height: calc(100vh - 32px); display: flex; flex-direction: column; background: #fff; border-radius: 6px; box-shadow: 0 6px 24px rgba(0,0,0,.2); }
        .vt-dialog form { display: flex; flex-direction: column; min-height: 0; }
        .vt-dialog.is-confirm { max-width: 420px; }
        .vt-modal-head { display: flex; align-items: center; justify-content: space-between; padding: 14px 20px; border-bottom: 1px solid #f0f0f0; }
        .vt-modal-title { margin: 0; font-size: 15px; font-weight: 700; }
        .vt-modal-x { border: 0; background: none; color: #8c8c8c; cursor: pointer; line-height: 0; }
        .vt-modal-x:hover { color: #262626; }
        .vt-modal-body { padding: 16px 20px; display: flex; flex-direction: column; gap: 14px; overflow-y: auto; }
        .vt-modal-body p { margin: 0; font-size: 13px; color: #595959; }
        /* Hàng nút ở chân hộp thoại luôn CANH GIỮA — quy tắc chung của dự án. */
        .vt-modal-foot { display: flex; justify-content: center; gap: 8px; padding: 12px 20px; border-top: 1px solid #f0f0f0; background: #fafafa; border-radius: 0 0 6px 6px; }

        .vt-field-label { display: block; font-size: 12.5px; font-weight: 600; color: #434343; margin-bottom: 5px; }
        .vt-req { color: #ff4d4f; }
        .vt-input {
            width: 100%; height: 36px; border: 1px solid #d9d9d9; border-radius: 4px;
            padding: 0 12px; font-size: 13px; outline: none; background-color: #fff; color: #262626;
        }
        .vt-hint { margin: 4px 0 0; font-size: 12px; color: #8c8c8c; }
    </style>

    <script>
        (function () {
            // ---------- Bộ lọc realtime: đổi select -> chạy ngay; gõ -> chờ 400ms ----------
            var filter = document.getElementById('vtFilter');
            filter.querySelectorAll('select').forEach(function (sel) {
                sel.addEventListener('change', function () { filter.submit(); });
            });
            var search = filter.querySelector('input[name="keyword"]');
            var searchTimer = null;
            search.addEventListener('input', function () {
                clearTimeout(searchTimer);
                searchTimer = setTimeout(function () { filter.submit(); }, 400);
            });

            var overlay = document.getElementById('vtOverlay');
            var form = document.getElementById('vtForm');
            var method = document.getElementById('vtMethod');
            var title = document.getElementById('vtModalTitle');
            var submit = document.getElementById('vtSubmit');

            var oId = document.getElementById('vtId');
            var oMa = document.getElementById('vtMa');
            var oTen = document.getElementById('vtTen');
            var oBat = document.getElementById('vtTrangThai');
            var oBatChu = document.getElementById('vtTrangThaiChu');
            var oBatValue = document.getElementById('vtTrangThaiValue');

            var STORE = @json(route('admin.vi-tri.store'));
            // Khuôn đường sửa: thay 0 bằng id thật. Dựng bằng route() để đường dẫn
            // vẫn đúng nếu prefix của nhóm route đổi.
            var UPDATE = @json(route('admin.vi-tri.update', 0));

            // ---------- Hộp xác nhận dùng chung ----------
            //
            // Thay chỗ confirm() của trình duyệt: đặt được chữ trên từng nút và nói
            // rõ xoá cái gì. Bất đồng bộ nên nơi gọi đưa việc cần làm vào `xong`.
            var hopHoi = document.getElementById('vtConfirm');
            var hopHoiTieuDe = document.getElementById('vtConfirmTitle');
            var hopHoiNoiDung = document.getElementById('vtConfirmBody');
            var hopHoiOk = document.getElementById('vtConfirmOk');
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

                // textContent từng đoạn: tên vị trí là chữ do người dùng gõ, nối
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
            document.querySelectorAll('[data-vt-confirm-huy]').forEach(function (el) {
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

            document.querySelector('[data-vt-add]').addEventListener('click', function () {
                moForm({ tieuDe: 'Thêm vị trí' });
            });

            document.querySelectorAll('[data-vt-edit]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var vt = JSON.parse(btn.dataset.viTri);
                    moForm({
                        tieuDe: 'Sửa vị trí',
                        id: vt.id,
                        code: vt.code,
                        name: vt.name,
                        isActive: !!vt.is_active,
                        oDau: oTen,
                    });
                });
            });

            // Nhân bản: chép TÊN, để trống mã. Mã bắt buộc phải khác nên chép sang
            // chỉ tổ ăn lỗi trùng ngay lượt Lưu đầu tiên.
            document.querySelectorAll('[data-vt-copy]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var vt = JSON.parse(btn.dataset.viTri);
                    moForm({
                        tieuDe: 'Nhân bản vị trí',
                        name: (vt.name || '') + ' (bản sao)',
                        isActive: !!vt.is_active,
                    });
                });
            });

            document.querySelectorAll('#vtOverlay [data-close]').forEach(function (el) {
                el.addEventListener('click', dongForm);
            });
            overlay.addEventListener('click', function (e) { if (e.target === overlay) dongForm(); });
            document.addEventListener('keydown', function (e) {
                if (e.key !== 'Escape') return;
                if (hopHoi.style.display === 'flex') { dongHoi(false); return; }
                if (overlay.style.display === 'flex') dongForm();
            });

            // Khoá nút Lưu ngay khi gửi: bấm hai lần liên tiếp lúc mạng chậm là hai
            // vị trí trùng tên.
            form.addEventListener('submit', function () { submit.disabled = true; });

            // Bấm Lưu mà API từ chối thì mở lại hộp thoại kèm dữ liệu vừa gõ.
            @if($moLaiForm)
                moForm({
                    tieuDe: @json((int) old('vt_id', 0) > 0 ? 'Sửa vị trí' : 'Thêm vị trí'),
                    id: @json((int) old('vt_id', 0)),
                    code: @json(old('code', '')),
                    name: @json(old('name', '')),
                    isActive: @json((bool) old('is_active', 1)),
                });
            @endif

            // ---------- Công tắc trạng thái trên bảng ----------
            //
            // Gạt là gửi form của chính dòng đó, rồi khoá lại để hai cú gạt liên
            // tiếp không thành hai lượt ghi ngược nhau.
            document.querySelectorAll('[data-vt-status]').forEach(function (f) {
                var sw = f.querySelector('.vt-switch');
                sw.addEventListener('change', function () {
                    sw.disabled = true;
                    f.submit();
                });
            });

            // ---------- Xoá một dòng ----------
            document.querySelectorAll('[data-vt-xoa]').forEach(function (f) {
                f.addEventListener('submit', function (e) {
                    e.preventDefault();
                    hoi({
                        tieuDe: 'Xoá vị trí?',
                        doan: [
                            'Xoá vị trí "' + f.dataset.ten + '".',
                            'Mã của nó vẫn bị giữ chỗ sau khi xoá, không đặt lại cho vị trí khác được. '
                                + 'Chỉ muốn thôi bày nó ra ở ô chọn thì TẮT đi, đừng xoá.',
                        ],
                    }, function (dongY) {
                        if (dongY) f.submit();
                    });
                });
            });

            // ---------- Chọn nhiều dòng ----------
            var bulkForm = document.getElementById('vtBulkForm');
            var bulkBar = document.getElementById('vtBulkBar');
            var bulkSo = document.getElementById('vtBulkSo');
            var chonHet = document.getElementById('vtChonHet');
            var oChon = Array.prototype.slice.call(document.querySelectorAll('[data-vt-chon]'));

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
            document.getElementById('vtBulkBo').addEventListener('click', function () {
                oChon.forEach(function (o) { o.checked = false; });
                demLai();
            });

            document.getElementById('vtBulkXoa').addEventListener('click', function () {
                var da = oChon.filter(function (o) { return o.checked; });
                if (!da.length) return;
                hoi({
                    tieuDe: 'Xoá ' + da.length + ' vị trí?',
                    doan: [
                        'Xoá ' + da.length + ' vị trí đã chọn.',
                        'Mã của chúng vẫn bị giữ chỗ sau khi xoá, không đặt lại cho vị trí khác được. '
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
