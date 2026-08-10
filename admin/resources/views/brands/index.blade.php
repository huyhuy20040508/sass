@extends('layouts.app')

@section('title', 'Danh sách thương hiệu')

@section('content')
    {{--
        Trang "Danh sách thương hiệu" — dựng theo đúng khuôn trang Khách hàng / Sản phẩm:
        [ header tiêu đề ] + [ thanh lọc: tìm kiếm + select + hành động ] + [ bảng compact ] + [ chân trang ].
        Bộ lọc chạy realtime (đổi select -> submit ngay, gõ tìm kiếm -> debounce 400ms), không có nút "Áp dụng".
        Thêm/sửa dùng chung một modal; bật/tắt hiển thị dùng công tắc; xoá & xoá hàng loạt dựng form POST động (CSRF).
    --}}
    @php
        $STATUSES = \App\Http\Controllers\BrandController::STATUSES;
        $SORTS = \App\Http\Controllers\BrandController::SORTS;
        $PAGE_SIZES = \App\Http\Controllers\BrandController::PAGE_SIZES;

        $firstRank = ($meta['page'] - 1) * $meta['page_size'];
        $hasFilter = $filters['keyword'] !== ''
            || $filters['status'] !== 'all'
            || $filters['sort'] !== 'name_asc';
    @endphp

    <div class="brd">
        {{-- Header --}}
        <div class="brd-head">
            <h1 class="brd-title">Danh sách thương hiệu</h1>
            <span class="brd-head-note">
                {{ number_format($stats['total'], 0, ',', '.') }} thương hiệu ·
                {{ number_format($stats['products'], 0, ',', '.') }} sản phẩm đã gắn thương hiệu
            </span>
        </div>

        {{-- Bộ lọc --}}
        <form method="GET" action="{{ route('admin.brands.index') }}" id="brdFilter" class="brd-filter">
            <div class="brd-toolbar">
                <div class="brd-searchbox">
                    <input type="text" name="keyword" value="{{ $filters['keyword'] }}" class="brd-search-input"
                           placeholder="Tìm theo tên, đường dẫn hoặc mô tả" autocomplete="off">
                    <button type="submit" class="brd-search-btn" title="Tìm kiếm">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                    </button>
                </div>

                <select name="status" class="brd-select" title="Lọc theo trạng thái">
                    <option value="all" {{ $filters['status'] === 'all' ? 'selected' : '' }}>
                        Tất cả trạng thái ({{ number_format($stats['total'], 0, ',', '.') }})
                    </option>
                    @foreach($STATUSES as $value => $label)
                        <option value="{{ $value }}" {{ $filters['status'] === $value ? 'selected' : '' }}>
                            {{ $label }} ({{ number_format($stats[$value] ?? 0, 0, ',', '.') }})
                        </option>
                    @endforeach
                </select>

                <select name="sort" class="brd-select" title="Sắp xếp">
                    @foreach($SORTS as $value => $label)
                        <option value="{{ $value }}" {{ $filters['sort'] === $value ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>

                {{-- Hành động: Thêm thương hiệu + Tiện ích (đẩy sang phải toolbar) --}}
                <div class="brd-toolbar-actions">
                    <button type="button" class="brd-btn-primary" id="brdAddBtn">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M12 5v14"/></svg>
                        Thêm thương hiệu
                    </button>

                    <div class="brd-util" id="brdUtil">
                        <button type="button" class="brd-util-btn" id="brdUtilBtn" aria-haspopup="true" aria-expanded="false">
                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="1.6"/><circle cx="19" cy="12" r="1.6"/><circle cx="5" cy="12" r="1.6"/></svg>
                            Tiện ích
                            <svg class="brd-util-caret" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                        </button>
                        <div class="brd-util-menu">
                            <a href="{{ route('admin.brands.export', request()->query()) }}" class="brd-util-item">
                                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5"/><path d="M12 15V3"/></svg>
                                Xuất file (CSV)
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Giữ số dòng/trang khi đổi bộ lọc --}}
            <input type="hidden" name="page_size" value="{{ $meta['page_size'] }}">
        </form>

        {{-- Bảng --}}
        <div class="brd-table-wrap">
            <table class="brd-table">
                <thead>
                    <tr>
                        <th class="brd-c-check"><input type="checkbox" id="brdCheckAll" class="brd-check" title="Chọn tất cả"></th>
                        <th class="brd-c-stt">STT</th>
                        <th class="brd-c-name">Thương hiệu</th>
                        <th class="brd-c-slug">Đường dẫn</th>
                        <th class="brd-c-count">Sản phẩm</th>
                        <th class="brd-c-status">Hiển thị</th>
                        <th class="brd-c-date">Ngày tạo</th>
                        <th class="brd-c-act">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($brands as $i => $b)
                        @php
                            $id = $b['id'] ?? 0;
                            $name = $b['name'] ?? '';
                            $isOn = (bool) ($b['is_active'] ?? false);
                            $count = (int) ($b['product_count'] ?? 0);
                        @endphp
                        <tr data-id="{{ $id }}">
                            <td class="brd-c-check"><input type="checkbox" class="brd-check brd-row-check" value="{{ $id }}"></td>
                            <td class="brd-c-stt">{{ $firstRank + $i + 1 }}</td>
                            {{-- Mô tả nằm thành dòng phụ dưới tên (khuôn giống cột "Khách hàng"
                                 bên trang Khách hàng): mô tả thường ngắn hoặc bỏ trống nên tách
                                 riêng một cột chỉ tạo khoảng trống lớn giữa các cột. --}}
                            <td class="brd-c-name" data-edit="{{ $id }}" title="Bấm để sửa thương hiệu">
                                <div class="brd-ident">
                                    @if(!empty($b['logo']))
                                        <img class="brd-logo" src="{{ $b['logo'] }}" alt="" loading="lazy">
                                    @else
                                        <span class="brd-logo brd-logo-empty">{{ mb_strtoupper(mb_substr($name !== '' ? $name : 'T', 0, 1)) }}</span>
                                    @endif
                                    <span class="brd-ident-meta">
                                        <span class="brd-name">{{ $name !== '' ? $name : '—' }}</span>
                                        <span class="brd-sub" title="{{ $b['description'] ?? '' }}">
                                            {{ !empty($b['description']) ? $b['description'] : 'Chưa có mô tả' }}
                                        </span>
                                    </span>
                                </div>
                            </td>
                            <td class="brd-c-slug"><span class="brd-slug">{{ $b['slug'] ?? '—' }}</span></td>
                            <td class="brd-c-count">
                                @if($count > 0)
                                    <a class="brd-count" href="{{ route('admin.products.index', ['brand_id' => $id]) }}"
                                       title="Xem {{ $count }} sản phẩm của thương hiệu này">
                                        {{ number_format($count, 0, ',', '.') }}
                                    </a>
                                @else
                                    <span class="brd-muted">0</span>
                                @endif
                            </td>
                            <td class="brd-c-status">
                                <button type="button" class="brd-switch {{ $isOn ? 'on' : '' }}"
                                        data-toggle="{{ $id }}" data-on="{{ $isOn ? 1 : 0 }}"
                                        title="{{ $isOn ? 'Đang hiện trên cửa hàng — bấm để ẩn' : 'Đang ẩn — bấm để hiện trên cửa hàng' }}">
                                    <span class="brd-switch-knob"></span>
                                </button>
                            </td>
                            <td class="brd-c-date">
                                {{-- Carbon::parse giữ nguyên offset +07:00 API trả về (date()/strtotime đổi về UTC làm lệch ngày) --}}
                                {{ !empty($b['created_at']) ? \Illuminate\Support\Carbon::parse($b['created_at'])->format('d/m/Y') : '—' }}
                            </td>
                            <td class="brd-c-act">
                                <div class="brd-rowacts">
                                    <button type="button" class="brd-rowbtn brd-edit" data-edit="{{ $id }}" title="Sửa thương hiệu">
                                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                                    </button>
                                    <button type="button" class="brd-rowbtn brd-del" data-remove="{{ $id }}" title="Xoá thương hiệu">
                                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/></svg>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="brd-empty">
                                @if($hasFilter)
                                    Không tìm thấy thương hiệu nào khớp bộ lọc. Thử xoá bớt điều kiện lọc.
                                @else
                                    Chưa có thương hiệu nào. Bấm “Thêm thương hiệu” để tạo mới.
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Chân trang: phân trang dùng chung toàn hệ thống --}}
        @include('partials.pagination', [
            'meta' => $meta,
            'noun' => 'thương hiệu',
            'perPageName' => 'page_size',
            'perPageOptions' => $PAGE_SIZES,
        ])
    </div>

    <div id="brdBulkMount"></div>

    {{-- Modal Thêm / Sửa thương hiệu --}}
    <div class="brd-overlay" id="brdFormOverlay" style="display:none;">
        <div class="brd-dialog">
            <div class="brd-modal-head">
                <h4 class="brd-modal-title" id="brdFormTitle">Thêm thương hiệu</h4>
                <button type="button" class="brd-modal-x" data-close>
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </div>

            <form id="brdForm" method="POST" action="{{ route('admin.brands.store') }}">
                @csrf
                <input type="hidden" name="_method" id="brdFormMethod" value="POST">
                <input type="hidden" name="return" value="{{ request()->getRequestUri() }}">

                <div class="brd-modal-body">
                    <div class="brd-grid2">
                        <div>
                            <label class="brd-field-label" for="brdName">Tên thương hiệu <span class="brd-req">*</span></label>
                            <input type="text" id="brdName" name="name" class="brd-input" placeholder="VD: Nike" required autocomplete="off" maxlength="150">
                        </div>
                        <div>
                            <label class="brd-field-label" for="brdStatus">Hiển thị trên cửa hàng <span class="brd-req">*</span></label>
                            <select id="brdStatus" name="is_active" class="brd-msel" required>
                                <option value="1">{{ $STATUSES['active'] }}</option>
                                <option value="0">{{ $STATUSES['inactive'] }}</option>
                            </select>
                        </div>

                        <div class="brd-col-2">
                            <label class="brd-field-label" for="brdSlug">Đường dẫn (slug)</label>
                            <input type="text" id="brdSlug" name="slug" class="brd-input" placeholder="Bỏ trống để tự sinh từ tên" autocomplete="off" maxlength="191">
                            <p class="brd-hint">Dùng trong địa chỉ trang thương hiệu trên cửa hàng. Bỏ trống thì hệ thống tự sinh từ tên.</p>
                        </div>

                        <div class="brd-col-2">
                            <label class="brd-field-label">Logo thương hiệu</label>
                            <div class="brd-img-field">
                                <div class="brd-img-preview" id="brdImgPreview"></div>
                                <div class="brd-img-actions">
                                    <div class="brd-img-btns">
                                        <button type="button" class="brd-btn-ghost" id="brdImgPick">Chọn ảnh</button>
                                        <button type="button" class="brd-btn-ghost brd-img-remove" id="brdImgRemove">Xoá ảnh</button>
                                    </div>
                                    <p class="brd-hint">JPG, PNG, WEBP, GIF, AVIF hoặc SVG — tối đa 10MB, ảnh lớn sẽ được tự thu nhỏ.</p>
                                </div>
                                <input type="file" id="brdImgInput" accept="image/*" hidden>
                                <input type="hidden" name="logo" id="brdLogo">
                            </div>
                        </div>

                        <div class="brd-col-2">
                            <label class="brd-field-label" for="brdDesc">Mô tả</label>
                            <textarea id="brdDesc" name="description" class="brd-textarea" placeholder="Giới thiệu ngắn về thương hiệu" maxlength="500"></textarea>
                        </div>
                    </div>

                    {{-- Chỉ hiện khi sửa thương hiệu đang có sản phẩm --}}
                    <p class="brd-note" id="brdUsedNote" style="display:none;"></p>
                </div>

                <div class="brd-modal-foot">
                    <button type="button" class="brd-btn-ghost" data-close>Đóng</button>
                    <button type="submit" class="brd-btn-primary" id="brdFormSubmit">Lưu</button>
                </div>
            </form>
        </div>
    </div>

    <style>
        .brd {
            /* Phá padding p-4 của <main> để tràn viền như trang Sản phẩm */
            margin: -1.5rem;
            min-height: calc(100vh - 56px);
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            color: #262626; background: #fff;
        }

        /* Header */
        .brd-head {
            display: flex; align-items: center; justify-content: space-between; gap: 16px;
            padding: 14px 20px;
        }
        .brd-title { margin: 0; font-size: 16px; font-weight: 700; color: #262626; line-height: 34px; }
        .brd-head-note { font-size: 12px; color: #8c8c8c; }
        .brd-btn-primary {
            height: 34px; display: inline-flex; align-items: center; gap: 6px; border: 0; border-radius: 4px;
            background: #1890ff; color: #fff; padding: 0 16px; font-size: 13px; font-weight: 500; cursor: pointer;
            transition: background .15s;
        }
        .brd-btn-primary:hover { background: #40a9ff; }
        .brd-btn-primary svg { flex-shrink: 0; }

        /* Bộ lọc */
        .brd-filter { padding: 0 20px 12px; margin-bottom: 12px; border-bottom: 1px solid #eee; }
        .brd-toolbar { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; }
        .brd-toolbar-actions { margin-left: auto; display: flex; align-items: center; gap: 8px; }

        .brd-searchbox { display: flex; border-radius: 4px; }
        .brd-searchbox:focus-within { box-shadow: 0 0 0 4px rgba(13,110,253,.25); }
        .brd-search-input {
            height: 34px; width: 260px; border: 1px solid #d9d9d9; border-right: 0; border-radius: 4px 0 0 4px;
            background: #fff; padding: 0 12px; font-size: 13px; outline: none; transition: border-color .15s;
        }
        .brd-search-input::placeholder { color: #bfbfbf; }
        .brd-searchbox:focus-within .brd-search-input,
        .brd-searchbox:focus-within .brd-search-btn { border-color: #86b7fe; }
        .brd-search-btn {
            height: 34px; width: 40px; display: inline-flex; align-items: center; justify-content: center;
            border: 1px solid #d9d9d9; border-radius: 0 4px 4px 0; background: #fafafa; color: #595959; cursor: pointer; transition: color .15s;
        }
        .brd-search-btn:hover { color: #1890ff; }

        .brd-select {
            height: 34px; border: 1px solid #d9d9d9; border-radius: 4px; background: #fff;
            padding: 0 30px 0 12px; font-size: 13px; color: #262626; cursor: pointer; outline: none;
            transition: border-color .15s; max-width: 220px;
            appearance: none; -webkit-appearance: none;
            background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23595959' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><path d='m6 9 6 6 6-6'/></svg>");
            background-repeat: no-repeat; background-position: right 8px center;
        }
        .brd-select:focus { border-color: #86b7fe; box-shadow: 0 0 0 .25rem rgba(13,110,253,.25); }

        /* Dropdown Tiện ích */
        .brd-util { position: relative; }
        .brd-util-btn {
            height: 34px; display: inline-flex; align-items: center; gap: 6px; border: 1px solid #d9d9d9;
            border-radius: 4px; background: #fff; padding: 0 12px; font-size: 13px; color: #595959;
            cursor: pointer; transition: border-color .15s, color .15s;
        }
        .brd-util-btn:hover, .brd-util.open .brd-util-btn { border-color: #1890ff; color: #1890ff; }
        .brd-util-caret { transition: transform .2s; }
        .brd-util.open .brd-util-caret { transform: rotate(180deg); }
        .brd-util-menu {
            position: absolute; right: 0; top: calc(100% + 4px); min-width: 200px; z-index: 1050;
            background: #fff; border: 1px solid #e6e6e6; border-radius: 6px; padding: 4px;
            box-shadow: 0 6px 24px rgba(0,0,0,.12); display: none;
        }
        .brd-util.open .brd-util-menu { display: block; }
        .brd-util-item {
            display: flex; align-items: center; gap: 8px; width: 100%; padding: 8px 10px; border: 0;
            background: none; border-radius: 4px; font-size: 13px; color: #262626; text-decoration: none;
            cursor: pointer; text-align: left; transition: background .15s, color .15s;
        }
        .brd-util-item:hover { background: #f5f7fa; color: #1890ff; }
        .brd-util-item svg { color: #8c8c8c; flex-shrink: 0; }
        .brd-util-item:hover svg { color: #1890ff; }

        /* Bảng trải kín bề ngang khung nội dung; bề rộng cột lấy theo tỉ lệ % bên dưới.
           Cột "Thương hiệu" ăn phần dư (43%) để nhóm Đường dẫn → Sản phẩm → Hiển thị
           → Ngày tạo nằm dồn sát nhau ngay cạnh "Thao tác" — đọc/thao tác một dòng
           chỉ liếc trong một vùng hẹp bên phải, không phải rê mắt qua cả màn hình. */
        .brd-table-wrap { width: 100%; overflow-x: auto; padding: 0 20px; scrollbar-width: thin; scrollbar-color: #d0d0d0 transparent; }
        .brd-table-wrap::-webkit-scrollbar { height: 11px; }
        .brd-table-wrap::-webkit-scrollbar-track { background: transparent; }
        .brd-table-wrap::-webkit-scrollbar-thumb { background-color: #dcdcdc; border-radius: 8px; border: 3px solid #fff; }
        .brd-table-wrap::-webkit-scrollbar-thumb:hover { background-color: #b3b3b3; }

        /* table-layout: fixed -> bề rộng cột lấy đúng % khai báo bên dưới, nội dung
           dài tự cắt bằng "…" thay vì kéo giãn cột làm tràn các cột phía sau.
           min-width giữ bảng không bị bóp nát trên màn hẹp (khung ngoài tự cuộn ngang). */
        .brd-table { width: 100%; min-width: 1300px; table-layout: fixed; border-collapse: collapse; font-size: 13px; }
        .brd-table thead tr { background: #f0f0f0; color: #262626; }
        .brd-table th, .brd-table td { padding: 14px 22px; vertical-align: middle; white-space: nowrap; line-height: 1.5; }
        .brd-table th { font-weight: 700; text-align: left; }
        .brd-table tbody tr { border-bottom: 1px solid #f0f0f0; }
        .brd-table tbody tr:hover { background: #fafafa; }
        .brd-table tbody tr.is-selected, .brd-table tbody tr.is-selected:hover { background: #e6f7ff; }

        .brd-table th.brd-c-check,  .brd-table td.brd-c-check  { width: 3%;  padding-right: 8px; }
        .brd-table th.brd-c-stt,    .brd-table td.brd-c-stt    { width: 5%;  text-align: center; }
        .brd-table th.brd-c-name,   .brd-table td.brd-c-name   { width: 42%; }
        .brd-table th.brd-c-slug,   .brd-table td.brd-c-slug   { width: 9%;  overflow: hidden; text-overflow: ellipsis; }
        .brd-table th.brd-c-count,  .brd-table td.brd-c-count  { width: 9%;  text-align: center; }
        .brd-table th.brd-c-status, .brd-table td.brd-c-status { width: 9%;  text-align: center; }
        .brd-table th.brd-c-date,   .brd-table td.brd-c-date   { width: 12%; text-align: center; }
        .brd-table th.brd-c-act,    .brd-table td.brd-c-act    { width: 11%; text-align: center; }

        .brd-check { width: 16px; height: 16px; cursor: pointer; accent-color: #1890ff; }
        .brd-muted { color: #bfbfbf; }
        .brd-slug { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 12px; color: #595959; }

        .brd-ident { display: flex; align-items: center; gap: 10px; min-width: 0; }
        .brd-logo {
            width: 34px; height: 34px; flex-shrink: 0; border-radius: 6px; object-fit: contain;
            border: 1px solid #f0f0f0; background: #fff;
        }
        .brd-logo-empty {
            display: inline-flex; align-items: center; justify-content: center;
            background: #f5f5f5; color: #595959; font-size: 13px; font-weight: 600;
        }
        /* min-width:0 để tên/mô tả dài cắt bằng "…" theo bề rộng cột thay vì nong
           cột ra; nội dung đầy đủ vẫn xem được ở tooltip. */
        .brd-ident-meta { display: flex; flex-direction: column; min-width: 0; }
        .brd-name { display: block; min-width: 0; font-weight: 500; color: #262626; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .brd-sub {
            display: block; margin-top: 4px; font-size: 12px; color: #8c8c8c;
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
        }

        .brd-count {
            display: inline-block; min-width: 34px; padding: 2px 8px; border-radius: 9999px;
            background: #f0f5ff; color: #1890ff; font-weight: 600; text-decoration: none;
            font-variant-numeric: tabular-nums; transition: background .15s;
        }
        .brd-count:hover { background: #d6e8ff; }

        /* Công tắc hiển thị — cùng cấu trúc & màu với trang Sản phẩm / Khách hàng */
        .brd-switch {
            position: relative; display: inline-flex; align-items: center; height: 22px; width: 42px; border: 0;
            border-radius: 9999px; background: #ddd; cursor: pointer; padding: 0; transition: background .15s;
        }
        .brd-switch.on { background: #7083b6; }
        .brd-switch-knob {
            display: inline-block; height: 16px; width: 16px; border-radius: 50%; background: #fff;
            box-shadow: 0 0 3px rgba(0,0,0,.3); transform: translateX(3px); transition: transform .15s;
        }
        .brd-switch.on .brd-switch-knob { transform: translateX(23px); }

        .brd-rowacts { display: inline-flex; align-items: center; gap: 6px; }
        .brd-rowbtn {
            width: 30px; height: 30px; border: 0; background: none; border-radius: 4px; padding: 0;
            cursor: pointer; color: #595959; display: inline-flex; align-items: center; justify-content: center;
            transition: background .15s, color .15s;
        }
        .brd-rowbtn.brd-edit { color: #1890ff; }
        .brd-rowbtn.brd-edit:hover { background: #e6f7ff; }
        .brd-rowbtn.brd-del { color: #ff4d4f; }
        .brd-rowbtn.brd-del:hover { background: #fff1f0; }

        .brd-empty { padding: 48px 12px; text-align: center; color: #8c8c8c; }

        /* Tên thương hiệu bấm được để sửa (giống trang Sản phẩm / Khách hàng) */
        .brd-c-name[data-edit] { cursor: pointer; }
        .brd-c-name[data-edit]:hover .brd-name { color: #1890ff; text-decoration: underline; }

        .brd-btn-primary:focus-visible, .brd-btn-ghost:focus-visible,
        .brd-search-btn:focus-visible { outline: none; box-shadow: 0 0 0 .25rem rgba(13,110,253,.25); }

        /* Thanh bulk nổi */
        .brd-bulk {
            position: fixed; bottom: 24px; left: 230px; right: 0; margin-inline: auto; z-index: 1070;
            width: max-content; max-width: calc(100vw - 260px);
            display: flex; align-items: center; gap: 16px; border: 1px solid #e6e6e6; border-radius: 9999px;
            background: #fff; padding: 12px 20px; box-shadow: 0 6px 24px rgba(0,0,0,.15);
        }
        body:has(.jh-sidebar.collapsed) .brd-bulk { left: 48px; }
        @media (max-width: 820px) { .brd-bulk { left: 0; } }
        .brd-bulk-text { font-size: 13px; font-weight: 500; color: #262626; }
        .brd-bulk-clear { border: 0; background: none; font-size: 13px; color: #8c8c8c; cursor: pointer; transition: color .15s; }
        .brd-bulk-clear:hover { color: #262626; }
        .brd-bulk-del {
            display: inline-flex; align-items: center; gap: 6px; border: 0; border-radius: 9999px; background: #ff4d4f;
            padding: 6px 16px; font-size: 13px; font-weight: 500; color: #fff; cursor: pointer; transition: background .15s;
        }
        .brd-bulk-del:hover { background: #ff7875; }

        /* ---- Modal ---- */
        .brd-overlay { position: fixed; inset: 0; z-index: 1080; display: flex; align-items: center; justify-content: center; padding: 16px; background: rgba(0,0,0,.4); }
        .brd-dialog {
            max-height: 92vh; width: 100%; max-width: 640px; overflow-y: auto; border-radius: 6px;
            background: #fff; box-shadow: 0 10px 40px rgba(0,0,0,.2);
            scrollbar-width: thin; scrollbar-color: #d0d0d0 transparent;
        }
        .brd-dialog::-webkit-scrollbar { width: 11px; }
        .brd-dialog::-webkit-scrollbar-track { background: transparent; }
        .brd-dialog::-webkit-scrollbar-thumb { background-color: #dcdcdc; border-radius: 8px; border: 3px solid #fff; }
        .brd-dialog::-webkit-scrollbar-thumb:hover { background-color: #b3b3b3; }

        .brd-modal-head {
            position: sticky; top: 0; z-index: 2; display: flex; align-items: center; justify-content: space-between;
            border-bottom: 1px solid #f0f0f0; background: #fff; padding: 12px 20px;
        }
        .brd-modal-title { margin: 0; font-size: 15px; font-weight: 700; color: #262626; }
        .brd-modal-x { border: 0; background: none; padding: 0; color: #8c8c8c; cursor: pointer; display: inline-flex; transition: color .15s; }
        .brd-modal-x:hover { color: #262626; }
        .brd-modal-body { padding: 16px 20px; display: flex; flex-direction: column; gap: 14px; }
        .brd-modal-foot {
            position: sticky; bottom: 0; display: flex; justify-content: center; gap: 8px;
            border-top: 1px solid #f0f0f0; background: #fff; padding: 12px 20px;
        }
        .brd-btn-ghost {
            height: 34px; border: 1px solid #d9d9d9; border-radius: 4px; padding: 0 16px; font-size: 13px; font-weight: 500;
            color: #595959; background: #fff; cursor: pointer; transition: border-color .15s;
        }
        .brd-btn-ghost:hover { border-color: #bfbfbf; }

        .brd-grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .brd-col-2 { grid-column: span 2; }
        .brd-field-label { display: block; margin-bottom: 4px; font-size: 13px; font-weight: 500; color: #262626; }
        .brd-req { color: #ff4d4f; }
        .brd-input, .brd-textarea, .brd-msel {
            width: 100%; border: 1px solid #d9d9d9; border-radius: 4px; padding: 0 12px; font-size: 13px; outline: none;
            transition: border-color .15s; font-family: inherit; color: #262626; background: #fff;
        }
        .brd-input { height: 36px; }
        .brd-textarea { padding: 8px 12px; min-height: 72px; resize: vertical; line-height: 1.5; }
        .brd-input::placeholder, .brd-textarea::placeholder { color: #bfbfbf; }
        .brd-input:focus, .brd-textarea:focus, .brd-msel:focus { border-color: #86b7fe; box-shadow: 0 0 0 .25rem rgba(13,110,253,.25); }
        .brd-msel {
            height: 36px; cursor: pointer; padding-right: 32px;
            appearance: none; -webkit-appearance: none;
            background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23595959' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><path d='m6 9 6 6 6-6'/></svg>");
            background-repeat: no-repeat; background-position: right 10px center;
        }
        .brd-hint { margin: 4px 0 0; font-size: 11px; color: #8c8c8c; }
        .brd-note {
            margin: 0; padding: 10px 12px; border: 1px solid #e6f0fb; border-radius: 4px; background: #f5f9ff;
            font-size: 12px; line-height: 1.55; color: #595959;
        }

        /* Ô logo trong modal (đồng bộ trang Danh mục & Khách hàng) */
        .brd-img-field { display: flex; align-items: center; gap: 14px; }
        .brd-img-preview {
            width: 84px; height: 84px; flex-shrink: 0; border: 1px solid #d9d9d9; border-radius: 6px;
            overflow: hidden; display: flex; align-items: center; justify-content: center;
            background: #fafafa; color: #bfbfbf; position: relative; transition: opacity .15s;
        }
        .brd-img-preview img { width: 100%; height: 100%; object-fit: contain; }
        .brd-img-preview.is-loading { opacity: .5; }
        .brd-img-preview.is-loading::after {
            content: ''; position: absolute; width: 22px; height: 22px; border-radius: 50%;
            border: 2px solid #d9d9d9; border-top-color: #1890ff; animation: brdspin .7s linear infinite;
        }
        @keyframes brdspin { to { transform: rotate(360deg); } }
        .brd-img-actions { display: flex; flex-direction: column; gap: 8px; align-items: flex-start; }
        .brd-img-btns { display: flex; gap: 8px; }
        .brd-img-btns .brd-btn-ghost { height: 32px; padding: 0 12px; }
        .brd-img-remove { color: #ff4d4f; }
        .brd-img-remove:hover { border-color: #ffa39e; }
        .brd-img-actions .brd-hint { margin: 0; }

        @media (max-width: 560px) {
            .brd-grid2 { grid-template-columns: 1fr; }
            .brd-col-2 { grid-column: span 1; }
        }
    </style>

    <script>
        (function () {
            const CSRF = @json(csrf_token());

        // Trần phía trình duyệt, khớp với ImageStore::MAX_UPLOAD_KB bên PHP. Chặn ở
        // đây chỉ để khỏi tải một file khổng lồ lên rồi mới bị từ chối; ảnh vẫn được
        // máy chủ thu nhỏ lại sau khi nhận.
        const MAX_IMG_BYTES = 10 * 1024 * 1024;
            const URL_BASE = @json(url('admin/brands'));
            const URL_STORE = @json(route('admin.brands.store'));
            const URL_BULK = @json(route('admin.brands.bulkDestroy'));
            const URL_UPLOAD_LOGO = @json(route('admin.brands.uploadLogo'));
            const RETURN_URL = @json(request()->getRequestUri());
            const BRANDS = @json($brands);
            const BY_ID = new Map(BRANDS.map((b) => [b.id, b]));

            const $filter = document.getElementById('brdFilter');
            const $bulkMount = document.getElementById('brdBulkMount');

            const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (m) => (
                { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]
            ));

            // ---------- Bộ lọc: đổi select -> submit ngay; gõ tìm kiếm -> debounce 400ms ----------
            $filter.querySelectorAll('select').forEach((sel) => {
                sel.addEventListener('change', () => $filter.submit());
            });
            const search = $filter.querySelector('input[name="keyword"]');
            let searchTimer = null;
            search.addEventListener('input', () => {
                clearTimeout(searchTimer);
                searchTimer = setTimeout(() => $filter.submit(), 400);
            });

            // ---------- Form POST động (mutation) ----------
            function postForm(action, method, fields) {
                const f = document.createElement('form');
                f.method = 'POST';
                f.action = action;
                f.style.display = 'none';
                const add = (name, val) => {
                    const i = document.createElement('input');
                    i.type = 'hidden';
                    i.name = name;
                    i.value = typeof val === 'boolean' ? (val ? 1 : 0) : (val == null ? '' : val);
                    f.appendChild(i);
                };
                add('_token', CSRF);
                if (method && method !== 'POST') add('_method', method);
                add('return', RETURN_URL);
                for (const [k, v] of Object.entries(fields)) add(k, v);
                document.body.appendChild(f);
                f.submit();
            }

            // Bật/tắt hiển thị: bật = hiện trên cửa hàng, tắt = ẩn.
            function toggleStatus(btn, b) {
                postForm(`${URL_BASE}/${b.id}/toggle-status`, 'PUT', {
                    is_active: btn.dataset.on === '1' ? 0 : 1,
                });
            }

            function removeBrand(b) {
                const used = Number(b.product_count) || 0;

                // Còn sản phẩm thì API/controller sẽ chặn — báo ngay tại chỗ cho đỡ mất công.
                if (used > 0) {
                    toastErr(`Không xoá được "${b.name}": còn ${used} sản phẩm đang gắn thương hiệu này. `
                        + 'Hãy chuyển các sản phẩm sang thương hiệu khác trước, hoặc tắt hiển thị thay vì xoá.');
                    return;
                }

                sysDelete({
                    title: 'Xác nhận xoá thương hiệu',
                    message: `Bạn có chắc chắn muốn xoá thương hiệu "${b.name}"? Hành động này không thể hoàn tác.`,
                    highlightText: b.name
                }).then((confirmed) => {
                    if (confirmed) postForm(`${URL_BASE}/${b.id}`, 'DELETE', {});
                });
            }

            // ---------- Modal thêm/sửa ----------
            const $formOverlay = document.getElementById('brdFormOverlay');
            const openOverlay = () => { $formOverlay.style.display = 'flex'; };
            const closeOverlay = () => { $formOverlay.style.display = 'none'; };

            $formOverlay.querySelectorAll('[data-close]').forEach((b) => b.addEventListener('click', closeOverlay));
            document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeOverlay(); });

            const form = document.getElementById('brdForm');
            const fields = {
                name: document.getElementById('brdName'),
                slug: document.getElementById('brdSlug'),
                description: document.getElementById('brdDesc'),
                is_active: document.getElementById('brdStatus'),
            };
            const usedNote = document.getElementById('brdUsedNote');

            function openForm(mode, b) {
                const isEdit = mode === 'edit';
                form.action = isEdit ? `${URL_BASE}/${b.id}` : URL_STORE;
                document.getElementById('brdFormMethod').value = isEdit ? 'PUT' : 'POST';
                document.getElementById('brdFormTitle').textContent = isEdit ? 'Sửa thương hiệu' : 'Thêm thương hiệu';
                document.getElementById('brdFormSubmit').textContent = isEdit ? 'Cập nhật' : 'Lưu';

                const d = isEdit ? b : { is_active: true };
                fields.name.value = d.name || '';
                fields.slug.value = d.slug || '';
                fields.description.value = d.description || '';
                fields.is_active.value = d.is_active ? '1' : '0';
                renderLogo(d.logo || '');

                const used = isEdit ? (Number(b.product_count) || 0) : 0;
                usedNote.style.display = used > 0 ? '' : 'none';
                if (used > 0) {
                    usedNote.innerHTML = `Thương hiệu này đang gắn với <b>${used}</b> sản phẩm. `
                        + 'Đổi đường dẫn sẽ làm thay đổi địa chỉ trang thương hiệu trên cửa hàng; '
                        + 'chuyển sang <b>Ngừng hoạt động</b> sẽ ẩn thương hiệu khỏi bộ lọc của khách.';
                }

                openOverlay();
                setTimeout(() => fields.name.focus(), 30);
            }

            document.getElementById('brdAddBtn').addEventListener('click', () => openForm('add', null));

            // ---------- Logo: chọn -> upload -> preview, lưu URL vào input ẩn ----------
            const logoInput = document.getElementById('brdLogo');
            const imgPreview = document.getElementById('brdImgPreview');
            const imgInput = document.getElementById('brdImgInput');
            const imgRemove = document.getElementById('brdImgRemove');
            const PLACEHOLDER = '<svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3h7l11 11-7 7L3 10V3Z"/><circle cx="7.5" cy="7.5" r="1.5"/></svg>';

            function renderLogo(url) {
                logoInput.value = url || '';
                imgPreview.innerHTML = url ? `<img src="${esc(url)}" alt="">` : PLACEHOLDER;
                imgRemove.style.display = url ? '' : 'none';
            }

            document.getElementById('brdImgPick').addEventListener('click', () => imgInput.click());
            imgRemove.addEventListener('click', () => { renderLogo(''); imgInput.value = ''; });
            imgInput.addEventListener('change', async () => {
                const file = imgInput.files && imgInput.files[0];
                if (!file) return;
                if (!file.type.startsWith('image/')) { toastErr('File tải lên không phải ảnh.'); imgInput.value = ''; return; }
                if (file.size > MAX_IMG_BYTES) { toastErr('Ảnh vượt quá 10MB.'); imgInput.value = ''; return; }

                imgPreview.classList.add('is-loading');
                try {
                    const fd = new FormData();
                    fd.append('image', file);
                    const res = await fetch(URL_UPLOAD_LOGO, {
                        method: 'POST', body: fd,
                        headers: { 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
                    });
                    const data = await res.json().catch(() => ({}));
                    if (!res.ok) { toastErr(data.message || 'Tải ảnh thất bại, vui lòng thử lại.'); return; }
                    renderLogo(data.url);
                } catch (err) {
                    toastErr('Không kết nối được máy chủ để tải ảnh.');
                } finally {
                    imgPreview.classList.remove('is-loading');
                    imgInput.value = '';
                }
            });

            // Toast lỗi phía client (dùng chung hạ tầng toast của layout).
            function toastErr(msg) {
                const cont = document.querySelector('.toast-container');
                if (!cont || typeof bootstrap === 'undefined') { alert(msg); return; }
                const el = document.createElement('div');
                el.className = 'toast align-items-center text-bg-danger border-0';
                el.setAttribute('role', 'alert');
                el.innerHTML = `<div class="d-flex"><div class="toast-body"><i class="bi bi-exclamation-circle-fill me-2"></i>${esc(msg)}</div>`
                    + '<button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>';
                cont.appendChild(el);
                const t = new bootstrap.Toast(el, { delay: 6000 });
                el.addEventListener('hidden.bs.toast', () => el.remove());
                t.show();
            }

            // ---------- Sự kiện bảng ----------
            const tbody = document.querySelector('.brd-table tbody');
            tbody.addEventListener('click', (e) => {
                // Số sản phẩm là link sang trang Sản phẩm — để trình duyệt xử lý.
                if (e.target.closest('a')) return;

                const tg = e.target.closest('[data-toggle]');
                if (tg) { const b = BY_ID.get(Number(tg.getAttribute('data-toggle'))); if (b) toggleStatus(tg, b); return; }
                const rm = e.target.closest('[data-remove]');
                if (rm) { const b = BY_ID.get(Number(rm.getAttribute('data-remove'))); if (b) removeBrand(b); return; }
                const ed = e.target.closest('[data-edit]');
                if (ed) { const b = BY_ID.get(Number(ed.getAttribute('data-edit'))); if (b) openForm('edit', b); return; }
            });

            // ---------- Chọn dòng + bulk ----------
            const selected = new Set();
            const checkAll = document.getElementById('brdCheckAll');
            const rowChecks = () => Array.from(document.querySelectorAll('.brd-row-check'));

            function syncRow(cb) {
                const tr = cb.closest('tr');
                if (cb.checked) { selected.add(Number(cb.value)); tr.classList.add('is-selected'); }
                else { selected.delete(Number(cb.value)); tr.classList.remove('is-selected'); }
            }
            function syncHeader() {
                const all = rowChecks();
                const on = all.filter((c) => c.checked).length;
                checkAll.checked = on > 0 && on === all.length;
                checkAll.indeterminate = on > 0 && on < all.length;
            }
            function renderBulk() {
                const n = selected.size;
                if (n === 0) { $bulkMount.innerHTML = ''; return; }
                // Thương hiệu còn sản phẩm sẽ bị bỏ qua khi xoá — nói trước con số cho rõ.
                const blocked = [...selected].filter((id) => (Number(BY_ID.get(id)?.product_count) || 0) > 0).length;
                $bulkMount.innerHTML = `
                    <div class="brd-bulk">
                        <span class="brd-bulk-text">Đã chọn <b>${n}</b> thương hiệu${blocked ? ` · ${blocked} còn sản phẩm nên không xoá được` : ''}</span>
                        <button type="button" class="brd-bulk-clear" id="brdBulkClear">Bỏ chọn</button>
                        <button type="button" class="brd-bulk-del" id="brdBulkDel">
                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/></svg>
                            Xoá (${n - blocked})
                        </button>
                    </div>`;
            }

            rowChecks().forEach((cb) => cb.addEventListener('change', () => { syncRow(cb); syncHeader(); renderBulk(); }));
            if (checkAll) {
                checkAll.addEventListener('change', () => {
                    rowChecks().forEach((cb) => { cb.checked = checkAll.checked; syncRow(cb); });
                    syncHeader(); renderBulk();
                });
            }

            $bulkMount.addEventListener('click', (e) => {
                if (e.target.closest('#brdBulkClear')) {
                    selected.clear();
                    rowChecks().forEach((cb) => { cb.checked = false; cb.closest('tr').classList.remove('is-selected'); });
                    syncHeader(); renderBulk();
                } else if (e.target.closest('#brdBulkDel')) {
                    const ids = [...selected];
                    const deletable = ids.filter((id) => (Number(BY_ID.get(id)?.product_count) || 0) === 0);
                    if (!deletable.length) {
                        toastErr('Tất cả thương hiệu đã chọn đều còn sản phẩm nên không xoá được.');
                        return;
                    }
                    const skipped = ids.length - deletable.length;
                    sysDelete({
                        title: 'Xác nhận xoá hàng loạt',
                        message: `Bạn có chắc chắn muốn xoá ${deletable.length} thương hiệu đã chọn?`
                            + (skipped ? ` ${skipped} thương hiệu còn sản phẩm sẽ được giữ lại.` : '')
                            + ' Hành động này không thể hoàn tác.',
                        highlightText: `Số lượng: ${deletable.length} thương hiệu`
                    }).then((confirmed) => {
                        if (confirmed) {
                            const fields = {};
                            deletable.forEach((id, i) => { fields[`ids[${i}]`] = id; });
                            postForm(URL_BULK, 'POST', fields);
                        }
                    });
                }
            });

            // ---------- Dropdown Tiện ích ----------
            const util = document.getElementById('brdUtil');
            document.getElementById('brdUtilBtn').addEventListener('click', (e) => {
                e.stopPropagation();
                const open = !util.classList.contains('open');
                util.classList.toggle('open', open);
                e.currentTarget.setAttribute('aria-expanded', open ? 'true' : 'false');
            });
            document.addEventListener('click', () => {
                util.classList.remove('open');
                document.getElementById('brdUtilBtn').setAttribute('aria-expanded', 'false');
            });
        })();
    </script>
@endsection
