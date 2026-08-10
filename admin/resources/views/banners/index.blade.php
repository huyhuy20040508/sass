@extends('layouts.app')

@section('title', \App\Http\Controllers\BannerController::TITLE)

@section('content')
    {{--
        Trang "Banner trang chủ" — dựng theo đúng khuôn trang Thương hiệu / Khách hàng:
        [ header tiêu đề ] + [ thanh lọc realtime ] + [ bảng compact ] + [ chân trang ] + modal CRUD.
        Bộ lọc chạy realtime (đổi select -> submit ngay, gõ tìm kiếm -> debounce 400ms), không có nút "Áp dụng".

        Khác các trang khác một điểm: KHÔNG có ô chọn kiểu sắp xếp. Thứ tự của bảng
        chính là thứ tự banner chạy ngoài cửa hàng, nên nó phải cố định — nút lên/xuống
        trên mỗi dòng ghi thẳng thứ tự đó về API.
    --}}
    @php
        $POSITIONS = \App\Http\Controllers\BannerController::POSITIONS;
        $STATUSES = \App\Http\Controllers\BannerController::STATUSES;
        $PAGE_SIZES = \App\Http\Controllers\BannerController::PAGE_SIZES;

        $hasFilter = $filters['keyword'] !== ''
            || $filters['position'] !== 'all'
            || $filters['status'] !== 'all';

        // Vị trí chưa có banner nào ĐANG HIỆN — khối tương ứng trên trang chủ đang
        // trống (hoặc rơi về ảnh mặc định cài sẵn trong mã nguồn).
        $emptyPositions = collect($byPosition)->filter(fn ($n) => $n === 0)->keys()->all();
    @endphp

    <div class="bnr">
        {{-- Header --}}
        <div class="bnr-head">
            <h1 class="bnr-title">{{ \App\Http\Controllers\BannerController::TITLE }}</h1>
            <span class="bnr-head-note">
                {{ number_format($stats['total'], 0, ',', '.') }} banner ·
                {{ number_format($stats['live'], 0, ',', '.') }} đang hiện trên cửa hàng
            </span>
        </div>

        {{-- Bộ lọc --}}
        <form method="GET" action="{{ route('admin.banners.index') }}" id="bnrFilter" class="bnr-filter">
            <div class="bnr-toolbar">
                <div class="bnr-searchbox">
                    <input type="text" name="keyword" value="{{ $filters['keyword'] }}" class="bnr-search-input"
                           placeholder="Tìm theo tiêu đề hoặc liên kết" autocomplete="off">
                    <button type="submit" class="bnr-search-btn" title="Tìm kiếm">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                    </button>
                </div>

                <select name="position" class="bnr-select" title="Lọc theo vị trí hiển thị">
                    <option value="all" {{ $filters['position'] === 'all' ? 'selected' : '' }}>
                        Tất cả vị trí ({{ number_format($stats['total'], 0, ',', '.') }})
                    </option>
                    {{-- Biến lặp phải là $posMeta chứ KHÔNG phải $meta: $meta là dữ liệu
                         phân trang của cả trang, đặt trùng tên là nó bị ghi đè và phần
                         chân trang bên dưới mất sạch. --}}
                    @foreach($POSITIONS as $code => $posMeta)
                        <option value="{{ $code }}" {{ $filters['position'] === $code ? 'selected' : '' }}>
                            {{ $posMeta['label'] }} ({{ number_format($byPosition[$code] ?? 0, 0, ',', '.') }} đang hiện)
                        </option>
                    @endforeach
                </select>

                <select name="status" class="bnr-select" title="Lọc theo trạng thái hiển thị">
                    <option value="all" {{ $filters['status'] === 'all' ? 'selected' : '' }}>
                        Tất cả trạng thái ({{ number_format($stats['total'], 0, ',', '.') }})
                    </option>
                    @foreach($STATUSES as $value => $label)
                        <option value="{{ $value }}" {{ $filters['status'] === $value ? 'selected' : '' }}>
                            {{ $label }} ({{ number_format($stats[$value] ?? 0, 0, ',', '.') }})
                        </option>
                    @endforeach
                </select>

                {{-- Hành động: Thêm banner + Tiện ích (đẩy sang phải toolbar) --}}
                <div class="bnr-toolbar-actions">
                    <button type="button" class="bnr-btn-primary" id="bnrAddBtn">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M12 5v14"/></svg>
                        Thêm banner
                    </button>

                    <div class="bnr-util" id="bnrUtil">
                        <button type="button" class="bnr-util-btn" id="bnrUtilBtn" aria-haspopup="true" aria-expanded="false">
                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="1.6"/><circle cx="19" cy="12" r="1.6"/><circle cx="5" cy="12" r="1.6"/></svg>
                            Tiện ích
                            <svg class="bnr-util-caret" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                        </button>
                        <div class="bnr-util-menu">
                            <a href="{{ route('admin.banners.export', request()->query()) }}" class="bnr-util-item">
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

        {{-- Khối trang chủ đang trống: nói thẳng chỗ nào chưa có gì thay vì để người
             bán mở website ra dò. Chỉ hiện khi đang xem toàn bộ danh sách. --}}
        @if(count($emptyPositions) && ! $hasFilter)
            <div class="bnr-alert">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16h.01"/></svg>
                <span>
                    Chưa có banner nào đang hiện ở:
                    @foreach($emptyPositions as $i => $code)
                        <b>{{ $POSITIONS[$code]['label'] }}</b>{{ $i < count($emptyPositions) - 1 ? ', ' : '' }}
                    @endforeach
                    — khối này trên trang chủ đang dùng ảnh mặc định cài sẵn.
                </span>
            </div>
        @endif

        {{-- Bảng --}}
        <div class="bnr-table-wrap">
            <table class="bnr-table">
                <thead>
                    <tr>
                        <th class="bnr-c-check"><input type="checkbox" id="bnrCheckAll" class="bnr-check" title="Chọn tất cả"></th>
                        <th class="bnr-c-order">Thứ tự</th>
                        <th class="bnr-c-name">Banner</th>
                        <th class="bnr-c-pos">Vị trí</th>
                        <th class="bnr-c-time">Lịch chạy</th>
                        <th class="bnr-c-state">Trạng thái</th>
                        <th class="bnr-c-status">Hiển thị</th>
                        <th class="bnr-c-act">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($banners as $b)
                        @php
                            $id = $b['id'] ?? 0;
                            $title = trim((string) ($b['title'] ?? ''));
                            $isOn = (bool) ($b['is_active'] ?? false);
                            $position = (string) ($b['position'] ?? '');
                            $state = $b['state'] ?? 'hidden';
                            $link = trim((string) ($b['link'] ?? ''));
                            $start = ! empty($b['start_at']) ? \Illuminate\Support\Carbon::parse($b['start_at']) : null;
                            $end = ! empty($b['end_at']) ? \Illuminate\Support\Carbon::parse($b['end_at']) : null;
                        @endphp
                        <tr data-id="{{ $id }}">
                            <td class="bnr-c-check"><input type="checkbox" class="bnr-check bnr-row-check" value="{{ $id }}"></td>
                            <td class="bnr-c-order">
                                {{-- Số thứ tự trong dải của CHÍNH vị trí đó, kèm hai nút đổi chỗ.
                                     Nút không bấm được vẫn cho bấm và nói lý do (đầu/cuối dải)
                                     thay vì im lặng — xem quy ước ở CLAUDE.md. --}}
                                <div class="bnr-order">
                                    <span class="bnr-rank">{{ $b['rank'] ?? 1 }}</span>
                                    <span class="bnr-movers">
                                        <button type="button" class="bnr-move" data-move="{{ $id }}" data-dir="up" title="Đưa lên trước">
                                            <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 15 6-6 6 6"/></svg>
                                        </button>
                                        <button type="button" class="bnr-move" data-move="{{ $id }}" data-dir="down" title="Đưa xuống sau">
                                            <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                                        </button>
                                    </span>
                                </div>
                            </td>
                            <td class="bnr-c-name" data-edit="{{ $id }}" title="Bấm để sửa banner">
                                <div class="bnr-ident">
                                    @if(!empty($b['image']))
                                        <img class="bnr-thumb" src="{{ $b['image'] }}" alt="" loading="lazy">
                                    @else
                                        <span class="bnr-thumb bnr-thumb-empty">
                                            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="8.5" cy="10" r="1.5"/><path d="m4 17 4.5-4 3 2.5 3.5-3.5 5 5"/></svg>
                                        </span>
                                    @endif
                                    <span class="bnr-ident-meta">
                                        <span class="bnr-name">{{ $title !== '' ? $title : 'Banner #'.$id }}</span>
                                        @if($link !== '')
                                            <span class="bnr-sub" title="{{ $link }}">{{ $link }}</span>
                                        @else
                                            <span class="bnr-sub bnr-muted">Không gắn liên kết — bấm vào ảnh không đi đâu</span>
                                        @endif
                                    </span>
                                </div>
                            </td>
                            <td class="bnr-c-pos">
                                <span class="bnr-tag">{{ $POSITIONS[$position]['label'] ?? $position }}</span>
                            </td>
                            <td class="bnr-c-time">
                                @if($start === null && $end === null)
                                    <span class="bnr-muted">Chạy liên tục</span>
                                @else
                                    <span class="bnr-time">
                                        {{ $start?->format('d/m/Y H:i') ?? 'Từ đầu' }}
                                        <span class="bnr-muted">→</span>
                                        {{ $end?->format('d/m/Y H:i') ?? 'Không hạn' }}
                                    </span>
                                @endif
                            </td>
                            <td class="bnr-c-state">
                                <span class="bnr-state bnr-state-{{ $state }}">{{ $STATUSES[$state] ?? $state }}</span>
                            </td>
                            <td class="bnr-c-status">
                                <button type="button" class="bnr-switch {{ $isOn ? 'on' : '' }}"
                                        data-toggle="{{ $id }}" data-on="{{ $isOn ? 1 : 0 }}"
                                        title="{{ $isOn ? 'Công tắc đang bật — bấm để tắt' : 'Công tắc đang tắt — bấm để bật' }}">
                                    <span class="bnr-switch-knob"></span>
                                </button>
                            </td>
                            <td class="bnr-c-act">
                                <div class="bnr-rowacts">
                                    <button type="button" class="bnr-rowbtn bnr-edit" data-edit="{{ $id }}" title="Sửa banner">
                                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                                    </button>
                                    <button type="button" class="bnr-rowbtn bnr-del" data-remove="{{ $id }}" title="Xoá banner">
                                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/></svg>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="bnr-empty">
                                @if($hasFilter)
                                    Không tìm thấy banner nào khớp bộ lọc. Thử xoá bớt điều kiện lọc.
                                @else
                                    Chưa có banner nào. Bấm “Thêm banner” để tạo mới.
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
            'noun' => 'banner',
            'perPageName' => 'page_size',
            'perPageOptions' => $PAGE_SIZES,
        ])
    </div>

    <div id="bnrBulkMount"></div>

    {{-- Modal Thêm / Sửa banner --}}
    <div class="bnr-overlay" id="bnrFormOverlay" style="display:none;">
        <div class="bnr-dialog">
            <div class="bnr-modal-head">
                <h4 class="bnr-modal-title" id="bnrFormTitle">Thêm banner</h4>
                <button type="button" class="bnr-modal-x" data-close>
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </div>

            <form id="bnrForm" method="POST" action="{{ route('admin.banners.store') }}">
                @csrf
                <input type="hidden" name="_method" id="bnrFormMethod" value="POST">
                <input type="hidden" name="return" value="{{ request()->getRequestUri() }}">
                {{-- Id của banner đang sửa. Controller không đọc trường này; nó chỉ tồn
                     tại để lỗi validate quay lại còn mở đúng modal SỬA của đúng banner
                     thay vì mở modal thêm mới. --}}
                <input type="hidden" name="id" id="bnrFormId" value="">

                <div class="bnr-modal-body">
                    <div class="bnr-grid2">
                        <div class="bnr-col-2">
                            <label class="bnr-field-label">Ảnh banner <span class="bnr-req">*</span></label>
                            <div class="bnr-img-field">
                                <div class="bnr-img-preview" id="bnrImgPreview"></div>
                                <div class="bnr-img-actions">
                                    <div class="bnr-img-btns">
                                        <button type="button" class="bnr-btn-ghost" id="bnrImgPick">Chọn ảnh</button>
                                        <button type="button" class="bnr-btn-ghost bnr-img-remove" id="bnrImgRemove">Xoá ảnh</button>
                                    </div>
                                    <p class="bnr-hint" id="bnrImgHint">JPG, PNG, WEBP, GIF hoặc AVIF — tối đa 10MB, ảnh lớn sẽ được tự thu nhỏ.</p>
                                </div>
                                <input type="file" id="bnrImgInput" accept="image/*" hidden>
                                <input type="hidden" name="image" id="bnrImage">
                            </div>
                        </div>

                        <div>
                            <label class="bnr-field-label" for="bnrPosition">Vị trí hiển thị <span class="bnr-req">*</span></label>
                            <select id="bnrPosition" name="position" class="bnr-msel" required>
                                @foreach($POSITIONS as $code => $pmeta)
                                    <option value="{{ $code }}" data-hint="{{ $pmeta['hint'] }}">{{ $pmeta['label'] }}</option>
                                @endforeach
                            </select>
                            <p class="bnr-hint" id="bnrPositionHint"></p>
                        </div>
                        <div>
                            <label class="bnr-field-label" for="bnrStatus">Công tắc hiển thị <span class="bnr-req">*</span></label>
                            <select id="bnrStatus" name="is_active" class="bnr-msel" required>
                                <option value="1">Bật</option>
                                <option value="0">Tắt</option>
                            </select>
                            <p class="bnr-hint">Tắt là ẩn ngay khỏi cửa hàng, không phụ thuộc lịch chạy.</p>
                        </div>

                        <div class="bnr-col-2">
                            <label class="bnr-field-label" for="bnrTitle">Tiêu đề</label>
                            <input type="text" id="bnrTitle" name="title" class="bnr-input" placeholder="VD: Áo đấu World Cup 2026" autocomplete="off" maxlength="200">
                            <p class="bnr-hint">Chỉ dùng để nhận ra banner trong danh sách này và làm chữ mô tả ảnh cho máy tìm kiếm.</p>
                        </div>

                        <div class="bnr-col-2">
                            <label class="bnr-field-label" for="bnrLink">Liên kết khi bấm vào</label>
                            <input type="text" id="bnrLink" name="link" class="bnr-input" placeholder="VD: /san-pham?sort=newest" autocomplete="off" maxlength="255">
                            <p class="bnr-hint">Bỏ trống thì ảnh chỉ để xem, bấm vào không đi đâu.</p>
                        </div>

                        {{-- Hai mốc lịch chạy. Ô ngày LUÔN có sẵn một giá trị đẹp (hôm nay
                             00:00 và 30 ngày sau 23:59) để người bán không phải gõ từ số 0;
                             tick "Vô thời hạn" thì ô ngày khoá lại và trường không được gửi
                             lên, tức là mốc đó không giới hạn. --}}
                        <div>
                            <label class="bnr-field-label" for="bnrStart">Bắt đầu chạy</label>
                            <input type="datetime-local" id="bnrStart" name="start_at" class="bnr-input">
                            <label class="bnr-tick" for="bnrStartAny">
                                <input type="checkbox" id="bnrStartAny" class="bnr-check">
                                <span>Vô thời hạn</span>
                            </label>
                            <p class="bnr-hint">Không đặt mốc bắt đầu — banner chạy ngay khi bật.</p>
                        </div>
                        <div>
                            <label class="bnr-field-label" for="bnrEnd">Kết thúc</label>
                            <input type="datetime-local" id="bnrEnd" name="end_at" class="bnr-input">
                            <label class="bnr-tick" for="bnrEndAny">
                                <input type="checkbox" id="bnrEndAny" class="bnr-check">
                                <span>Vô thời hạn</span>
                            </label>
                            <p class="bnr-hint">Không đặt hạn kết thúc — chạy đến khi tự tắt.</p>
                        </div>
                    </div>

                    <p class="bnr-note" id="bnrStateNote" style="display:none;"></p>
                </div>

                <div class="bnr-modal-foot">
                    <button type="button" class="bnr-btn-ghost" data-close>Đóng</button>
                    <button type="submit" class="bnr-btn-primary" id="bnrFormSubmit">Lưu</button>
                </div>
            </form>
        </div>
    </div>

    <style>
        .bnr {
            /* Phá padding p-4 của <main> để tràn viền như trang Sản phẩm */
            margin: -1.5rem;
            min-height: calc(100vh - 56px);
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            color: #262626; background: #fff;
        }

        /* Header */
        .bnr-head {
            display: flex; align-items: center; justify-content: space-between; gap: 16px;
            padding: 14px 20px;
        }
        .bnr-title { margin: 0; font-size: 16px; font-weight: 700; color: #262626; line-height: 34px; }
        .bnr-head-note { font-size: 12px; color: #8c8c8c; }
        .bnr-btn-primary {
            height: 34px; display: inline-flex; align-items: center; gap: 6px; border: 0; border-radius: 4px;
            background: #1890ff; color: #fff; padding: 0 16px; font-size: 13px; font-weight: 500; cursor: pointer;
            transition: background .15s;
        }
        .bnr-btn-primary:hover { background: #40a9ff; }
        .bnr-btn-primary svg { flex-shrink: 0; }

        /* Bộ lọc */
        .bnr-filter { padding: 0 20px 12px; margin-bottom: 12px; border-bottom: 1px solid #eee; }
        .bnr-toolbar { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; }
        .bnr-toolbar-actions { margin-left: auto; display: flex; align-items: center; gap: 8px; }

        .bnr-searchbox { display: flex; border-radius: 4px; }
        .bnr-searchbox:focus-within { box-shadow: 0 0 0 4px rgba(13,110,253,.25); }
        .bnr-search-input {
            height: 34px; width: 260px; border: 1px solid #d9d9d9; border-right: 0; border-radius: 4px 0 0 4px;
            background: #fff; padding: 0 12px; font-size: 13px; outline: none; transition: border-color .15s;
        }
        .bnr-search-input::placeholder { color: #bfbfbf; }
        .bnr-searchbox:focus-within .bnr-search-input,
        .bnr-searchbox:focus-within .bnr-search-btn { border-color: #86b7fe; }
        .bnr-search-btn {
            height: 34px; width: 40px; display: inline-flex; align-items: center; justify-content: center;
            border: 1px solid #d9d9d9; border-radius: 0 4px 4px 0; background: #fafafa; color: #595959; cursor: pointer; transition: color .15s;
        }
        .bnr-search-btn:hover { color: #1890ff; }

        .bnr-select {
            height: 34px; border: 1px solid #d9d9d9; border-radius: 4px; background: #fff;
            padding: 0 30px 0 12px; font-size: 13px; color: #262626; cursor: pointer; outline: none;
            transition: border-color .15s; max-width: 260px;
            appearance: none; -webkit-appearance: none;
            background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23595959' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><path d='m6 9 6 6 6-6'/></svg>");
            background-repeat: no-repeat; background-position: right 8px center;
        }
        .bnr-select:focus { border-color: #86b7fe; box-shadow: 0 0 0 .25rem rgba(13,110,253,.25); }

        /* Dropdown Tiện ích */
        .bnr-util { position: relative; }
        .bnr-util-btn {
            height: 34px; display: inline-flex; align-items: center; gap: 6px; border: 1px solid #d9d9d9;
            border-radius: 4px; background: #fff; padding: 0 12px; font-size: 13px; color: #595959;
            cursor: pointer; transition: border-color .15s, color .15s;
        }
        .bnr-util-btn:hover, .bnr-util.open .bnr-util-btn { border-color: #1890ff; color: #1890ff; }
        .bnr-util-caret { transition: transform .2s; }
        .bnr-util.open .bnr-util-caret { transform: rotate(180deg); }
        .bnr-util-menu {
            position: absolute; right: 0; top: calc(100% + 4px); min-width: 200px; z-index: 1050;
            background: #fff; border: 1px solid #e6e6e6; border-radius: 6px; padding: 4px;
            box-shadow: 0 6px 24px rgba(0,0,0,.12); display: none;
        }
        .bnr-util.open .bnr-util-menu { display: block; }
        .bnr-util-item {
            display: flex; align-items: center; gap: 8px; width: 100%; padding: 8px 10px; border: 0;
            background: none; border-radius: 4px; font-size: 13px; color: #262626; text-decoration: none;
            cursor: pointer; text-align: left; transition: background .15s, color .15s;
        }
        .bnr-util-item:hover { background: #f5f7fa; color: #1890ff; }
        .bnr-util-item svg { color: #8c8c8c; flex-shrink: 0; }
        .bnr-util-item:hover svg { color: #1890ff; }

        /* Nhắc khối trang chủ đang trống */
        .bnr-alert {
            display: flex; align-items: flex-start; gap: 8px; margin: 0 20px 12px;
            border: 1px solid #ffe58f; border-radius: 4px; background: #fffbe6;
            padding: 10px 12px; font-size: 12px; line-height: 1.55; color: #614700;
        }
        .bnr-alert svg { flex-shrink: 0; margin-top: 1px; color: #d48806; }

        /* Bảng: cột "Banner" ăn phần dư để các cột còn lại dồn sát nhau bên phải */
        .bnr-table-wrap { width: 100%; overflow-x: auto; padding: 0 20px; scrollbar-width: thin; scrollbar-color: #d0d0d0 transparent; }
        .bnr-table-wrap::-webkit-scrollbar { height: 11px; }
        .bnr-table-wrap::-webkit-scrollbar-track { background: transparent; }
        .bnr-table-wrap::-webkit-scrollbar-thumb { background-color: #dcdcdc; border-radius: 8px; border: 3px solid #fff; }
        .bnr-table-wrap::-webkit-scrollbar-thumb:hover { background-color: #b3b3b3; }

        .bnr-table { width: 100%; min-width: 1300px; table-layout: fixed; border-collapse: collapse; font-size: 13px; }
        .bnr-table thead tr { background: #f0f0f0; color: #262626; }
        .bnr-table th, .bnr-table td { padding: 14px 22px; vertical-align: middle; white-space: nowrap; line-height: 1.5; }
        .bnr-table th { font-weight: 700; text-align: left; }
        .bnr-table tbody tr { border-bottom: 1px solid #f0f0f0; }
        .bnr-table tbody tr:hover { background: #fafafa; }
        .bnr-table tbody tr.is-selected, .bnr-table tbody tr.is-selected:hover { background: #e6f7ff; }

        .bnr-table th.bnr-c-check,  .bnr-table td.bnr-c-check  { width: 3%;  padding-right: 8px; }
        .bnr-table th.bnr-c-order,  .bnr-table td.bnr-c-order  { width: 8%;  text-align: center; }
        .bnr-table th.bnr-c-name,   .bnr-table td.bnr-c-name   { width: 33%; }
        .bnr-table th.bnr-c-pos,    .bnr-table td.bnr-c-pos    { width: 13%; text-align: center; }
        .bnr-table th.bnr-c-time,   .bnr-table td.bnr-c-time   { width: 18%; text-align: center; }
        .bnr-table th.bnr-c-state,  .bnr-table td.bnr-c-state  { width: 10%; text-align: center; }
        .bnr-table th.bnr-c-status, .bnr-table td.bnr-c-status { width: 8%;  text-align: center; }
        .bnr-table th.bnr-c-act,    .bnr-table td.bnr-c-act    { width: 9%;  text-align: center; }

        .bnr-check { width: 16px; height: 16px; cursor: pointer; accent-color: #1890ff; }
        .bnr-muted { color: #bfbfbf; }
        .bnr-time { font-variant-numeric: tabular-nums; color: #595959; font-size: 12px; }

        /* Thứ tự + nút đổi chỗ */
        .bnr-order { display: inline-flex; align-items: center; gap: 8px; }
        .bnr-rank {
            display: inline-flex; align-items: center; justify-content: center; min-width: 26px; height: 24px;
            border-radius: 4px; background: #f5f5f5; font-weight: 600; font-variant-numeric: tabular-nums;
        }
        .bnr-movers { display: inline-flex; flex-direction: column; gap: 2px; }
        .bnr-move {
            width: 22px; height: 15px; display: inline-flex; align-items: center; justify-content: center;
            border: 1px solid #e6e6e6; border-radius: 3px; background: #fff; color: #8c8c8c; padding: 0;
            cursor: pointer; transition: border-color .15s, color .15s;
        }
        .bnr-move:hover { border-color: #1890ff; color: #1890ff; }

        .bnr-ident { display: flex; align-items: center; gap: 10px; min-width: 0; }
        /* Ảnh banner là ảnh ngang khổ rộng — ô xem trước để dạng chữ nhật cho giống
           thứ sẽ hiện ngoài cửa hàng, để vuông thì mọi banner trông y hệt nhau. */
        .bnr-thumb {
            width: 76px; height: 40px; flex-shrink: 0; border-radius: 4px; object-fit: cover;
            border: 1px solid #f0f0f0; background: #fafafa;
        }
        .bnr-thumb-empty {
            display: inline-flex; align-items: center; justify-content: center;
            background: #f5f5f5; color: #bfbfbf;
        }
        .bnr-ident-meta { display: flex; flex-direction: column; min-width: 0; }
        .bnr-name { display: block; min-width: 0; font-weight: 500; color: #262626; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .bnr-sub {
            display: block; margin-top: 4px; font-size: 12px; color: #8c8c8c;
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
        }

        .bnr-tag {
            display: inline-block; padding: 2px 10px; border-radius: 9999px;
            background: #f0f5ff; color: #1890ff; font-size: 12px; font-weight: 500;
        }

        /* Trạng thái hiển thị thực tế — 4 mức, mỗi mức một màu riêng */
        .bnr-state { display: inline-block; padding: 2px 10px; border-radius: 9999px; font-size: 12px; font-weight: 500; }
        .bnr-state-live { background: #f6ffed; color: #389e0d; }
        .bnr-state-scheduled { background: #fff7e6; color: #d46b08; }
        .bnr-state-expired { background: #fff1f0; color: #cf1322; }
        .bnr-state-hidden { background: #fafafa; color: #8c8c8c; }

        /* Công tắc hiển thị — cùng cấu trúc & màu với trang Sản phẩm / Thương hiệu */
        .bnr-switch {
            position: relative; display: inline-flex; align-items: center; height: 22px; width: 42px; border: 0;
            border-radius: 9999px; background: #ddd; cursor: pointer; padding: 0; transition: background .15s;
        }
        .bnr-switch.on { background: #7083b6; }
        .bnr-switch-knob {
            display: inline-block; height: 16px; width: 16px; border-radius: 50%; background: #fff;
            box-shadow: 0 0 3px rgba(0,0,0,.3); transform: translateX(3px); transition: transform .15s;
        }
        .bnr-switch.on .bnr-switch-knob { transform: translateX(23px); }

        .bnr-rowacts { display: inline-flex; align-items: center; gap: 6px; }
        .bnr-rowbtn {
            width: 30px; height: 30px; border: 0; background: none; border-radius: 4px; padding: 0;
            cursor: pointer; color: #595959; display: inline-flex; align-items: center; justify-content: center;
            transition: background .15s, color .15s;
        }
        .bnr-rowbtn.bnr-edit { color: #1890ff; }
        .bnr-rowbtn.bnr-edit:hover { background: #e6f7ff; }
        .bnr-rowbtn.bnr-del { color: #ff4d4f; }
        .bnr-rowbtn.bnr-del:hover { background: #fff1f0; }

        .bnr-empty { padding: 48px 12px; text-align: center; color: #8c8c8c; }

        .bnr-c-name[data-edit] { cursor: pointer; }
        .bnr-c-name[data-edit]:hover .bnr-name { color: #1890ff; text-decoration: underline; }

        .bnr-btn-primary:focus-visible, .bnr-btn-ghost:focus-visible,
        .bnr-search-btn:focus-visible { outline: none; box-shadow: 0 0 0 .25rem rgba(13,110,253,.25); }

        /* Thanh bulk nổi */
        .bnr-bulk {
            position: fixed; bottom: 24px; left: 230px; right: 0; margin-inline: auto; z-index: 1070;
            width: max-content; max-width: calc(100vw - 260px);
            display: flex; align-items: center; gap: 16px; border: 1px solid #e6e6e6; border-radius: 9999px;
            background: #fff; padding: 12px 20px; box-shadow: 0 6px 24px rgba(0,0,0,.15);
        }
        body:has(.jh-sidebar.collapsed) .bnr-bulk { left: 48px; }
        @media (max-width: 820px) { .bnr-bulk { left: 0; } }
        .bnr-bulk-text { font-size: 13px; font-weight: 500; color: #262626; }
        .bnr-bulk-clear { border: 0; background: none; font-size: 13px; color: #8c8c8c; cursor: pointer; transition: color .15s; }
        .bnr-bulk-clear:hover { color: #262626; }
        .bnr-bulk-del {
            display: inline-flex; align-items: center; gap: 6px; border: 0; border-radius: 9999px; background: #ff4d4f;
            padding: 6px 16px; font-size: 13px; font-weight: 500; color: #fff; cursor: pointer; transition: background .15s;
        }
        .bnr-bulk-del:hover { background: #ff7875; }

        /* ---- Modal ---- */
        .bnr-overlay { position: fixed; inset: 0; z-index: 1080; display: flex; align-items: center; justify-content: center; padding: 16px; background: rgba(0,0,0,.4); }
        .bnr-dialog {
            max-height: 92vh; width: 100%; max-width: 680px; overflow-y: auto; border-radius: 6px;
            background: #fff; box-shadow: 0 10px 40px rgba(0,0,0,.2);
            scrollbar-width: thin; scrollbar-color: #d0d0d0 transparent;
        }
        .bnr-dialog::-webkit-scrollbar { width: 11px; }
        .bnr-dialog::-webkit-scrollbar-track { background: transparent; }
        .bnr-dialog::-webkit-scrollbar-thumb { background-color: #dcdcdc; border-radius: 8px; border: 3px solid #fff; }
        .bnr-dialog::-webkit-scrollbar-thumb:hover { background-color: #b3b3b3; }

        .bnr-modal-head {
            position: sticky; top: 0; z-index: 2; display: flex; align-items: center; justify-content: space-between;
            border-bottom: 1px solid #f0f0f0; background: #fff; padding: 12px 20px;
        }
        .bnr-modal-title { margin: 0; font-size: 15px; font-weight: 700; color: #262626; }
        .bnr-modal-x { border: 0; background: none; padding: 0; color: #8c8c8c; cursor: pointer; display: inline-flex; transition: color .15s; }
        .bnr-modal-x:hover { color: #262626; }
        .bnr-modal-body { padding: 16px 20px; display: flex; flex-direction: column; gap: 14px; }
        .bnr-modal-foot {
            position: sticky; bottom: 0; display: flex; justify-content: center; gap: 8px;
            border-top: 1px solid #f0f0f0; background: #fff; padding: 12px 20px;
        }
        .bnr-btn-ghost {
            height: 34px; border: 1px solid #d9d9d9; border-radius: 4px; padding: 0 16px; font-size: 13px; font-weight: 500;
            color: #595959; background: #fff; cursor: pointer; transition: border-color .15s;
        }
        .bnr-btn-ghost:hover { border-color: #bfbfbf; }

        .bnr-grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .bnr-col-2 { grid-column: span 2; }
        .bnr-field-label { display: block; margin-bottom: 4px; font-size: 13px; font-weight: 500; color: #262626; }
        .bnr-req { color: #ff4d4f; }
        .bnr-input, .bnr-msel {
            width: 100%; border: 1px solid #d9d9d9; border-radius: 4px; padding: 0 12px; font-size: 13px; outline: none;
            transition: border-color .15s; font-family: inherit; color: #262626; background: #fff;
        }
        .bnr-input { height: 36px; }
        .bnr-input::placeholder { color: #bfbfbf; }
        .bnr-input:focus, .bnr-msel:focus { border-color: #86b7fe; box-shadow: 0 0 0 .25rem rgba(13,110,253,.25); }
        .bnr-msel {
            height: 36px; cursor: pointer; padding-right: 32px;
            appearance: none; -webkit-appearance: none;
            background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23595959' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><path d='m6 9 6 6 6-6'/></svg>");
            background-repeat: no-repeat; background-position: right 10px center;
        }
        .bnr-hint { margin: 4px 0 0; font-size: 11px; color: #8c8c8c; }

        /* Ô tick "Vô thời hạn" đứng ngay dưới ô ngày nó điều khiển */
        .bnr-tick {
            display: inline-flex; align-items: center; gap: 6px; margin-top: 6px;
            font-size: 12px; color: #595959; cursor: pointer; user-select: none;
        }
        .bnr-tick:hover { color: #262626; }
        .bnr-tick .bnr-check { margin: 0; }
        /* Ô ngày bị khoá vẫn đọc được ngày bên trong (chỉ mờ đi), để người bán thấy
           mốc sẽ dùng nếu bỏ tick chứ không phải nhìn vào một ô trắng trơn. */
        .bnr-input:disabled {
            background: #f5f5f5; color: #a6a6a6; cursor: not-allowed; border-color: #e6e6e6;
        }
        .bnr-note {
            margin: 0; padding: 10px 12px; border: 1px solid #e6f0fb; border-radius: 4px; background: #f5f9ff;
            font-size: 12px; line-height: 1.55; color: #595959;
        }

        /* Ô ảnh trong modal — khung ngang vì banner là ảnh khổ rộng */
        .bnr-img-field { display: flex; align-items: center; gap: 14px; }
        .bnr-img-preview {
            width: 180px; height: 84px; flex-shrink: 0; border: 1px solid #d9d9d9; border-radius: 6px;
            overflow: hidden; display: flex; align-items: center; justify-content: center;
            background: #fafafa; color: #bfbfbf; position: relative; transition: opacity .15s;
        }
        .bnr-img-preview img { width: 100%; height: 100%; object-fit: cover; }
        .bnr-img-preview.is-loading { opacity: .5; }
        .bnr-img-preview.is-loading::after {
            content: ''; position: absolute; width: 22px; height: 22px; border-radius: 50%;
            border: 2px solid #d9d9d9; border-top-color: #1890ff; animation: bnrspin .7s linear infinite;
        }
        @keyframes bnrspin { to { transform: rotate(360deg); } }
        .bnr-img-actions { display: flex; flex-direction: column; gap: 8px; align-items: flex-start; }
        .bnr-img-btns { display: flex; gap: 8px; }
        .bnr-img-btns .bnr-btn-ghost { height: 32px; padding: 0 12px; }
        .bnr-img-remove { color: #ff4d4f; }
        .bnr-img-remove:hover { border-color: #ffa39e; }
        .bnr-img-actions .bnr-hint { margin: 0; }

        @media (max-width: 560px) {
            .bnr-grid2 { grid-template-columns: 1fr; }
            .bnr-col-2 { grid-column: span 1; }
            .bnr-img-field { flex-direction: column; align-items: flex-start; }
        }
    </style>

    <script>
        (function () {
            const CSRF = @json(csrf_token());

            // Trần phía trình duyệt, khớp với ImageStore::MAX_UPLOAD_KB bên PHP. Chặn ở
            // đây chỉ để khỏi tải một file khổng lồ lên rồi mới bị từ chối; ảnh vẫn được
            // máy chủ thu nhỏ lại sau khi nhận.
            const MAX_IMG_BYTES = 10 * 1024 * 1024;
            const URL_BASE = @json(url('admin/banners'));
            const URL_STORE = @json(route('admin.banners.store'));
            const URL_BULK = @json(route('admin.banners.bulkDestroy'));
            const URL_UPLOAD = @json(route('admin.banners.uploadImage'));
            const RETURN_URL = @json(request()->getRequestUri());
            const BANNERS = @json($banners);
            const POSITIONS = @json($POSITIONS);
            const FIRST_POSITION = Object.keys(POSITIONS)[0];
            const BY_ID = new Map(BANNERS.map((b) => [b.id, b]));

            const $filter = document.getElementById('bnrFilter');
            const $bulkMount = document.getElementById('bnrBulkMount');

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

            function toggleStatus(btn, b) {
                postForm(`${URL_BASE}/${b.id}/toggle-status`, 'PUT', {
                    is_active: btn.dataset.on === '1' ? 0 : 1,
                });
            }

            // Đổi chỗ với banner liền kề trong CÙNG vị trí. Việc kiểm tra "đã ở đầu /
            // cuối dải chưa" nằm ở controller vì trang đang xem có thể bị cắt trang.
            function move(id, direction) {
                postForm(`${URL_BASE}/${id}/move`, 'PUT', { direction });
            }

            function removeBanner(b) {
                const label = b.title && b.title.trim() !== '' ? b.title : `Banner #${b.id}`;
                sysDelete({
                    title: 'Xác nhận xoá banner',
                    message: `Bạn có chắc chắn muốn xoá "${label}"? Ảnh sẽ biến mất khỏi trang chủ ngay. Hành động này không thể hoàn tác.`,
                    highlightText: label
                }).then((confirmed) => {
                    if (confirmed) postForm(`${URL_BASE}/${b.id}`, 'DELETE', {});
                });
            }

            // ---------- Modal thêm/sửa ----------
            const $formOverlay = document.getElementById('bnrFormOverlay');
            const openOverlay = () => { $formOverlay.style.display = 'flex'; };
            const closeOverlay = () => { $formOverlay.style.display = 'none'; };

            $formOverlay.querySelectorAll('[data-close]').forEach((b) => b.addEventListener('click', closeOverlay));
            document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeOverlay(); });

            const form = document.getElementById('bnrForm');
            const fields = {
                title: document.getElementById('bnrTitle'),
                link: document.getElementById('bnrLink'),
                position: document.getElementById('bnrPosition'),
                is_active: document.getElementById('bnrStatus'),
                start_at: document.getElementById('bnrStart'),
                end_at: document.getElementById('bnrEnd'),
            };
            const stateNote = document.getElementById('bnrStateNote');
            const positionHint = document.getElementById('bnrPositionHint');

            // Ô <input type="datetime-local"> chỉ nhận "YYYY-MM-DDTHH:mm"; API trả về
            // chuỗi ISO kèm giây và offset múi giờ nên phải cắt đúng 16 ký tự đầu.
            const toLocalInput = (v) => (v ? String(v).slice(0, 16) : '');

            // ---------- Lịch chạy: ô ngày + ô tick "Vô thời hạn" ----------
            const startAny = document.getElementById('bnrStartAny');
            const endAny = document.getElementById('bnrEndAny');

            const pad = (n) => String(n).padStart(2, '0');
            const fmtLocal = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
                + `T${pad(d.getHours())}:${pad(d.getMinutes())}`;

            // Mốc gợi ý: bắt đầu từ 00:00 hôm nay, kết thúc 23:59 của 30 ngày sau —
            // số tròn, đọc là hiểu ngay, khỏi phải tự tính.
            function defaultStart() {
                const d = new Date();
                d.setHours(0, 0, 0, 0);
                return fmtLocal(d);
            }
            function defaultEnd() {
                const d = new Date();
                d.setDate(d.getDate() + 30);
                d.setHours(23, 59, 0, 0);
                return fmtLocal(d);
            }

            // Tick = mốc không giới hạn: khoá ô ngày lại và KHÔNG gửi trường đó lên
            // (input disabled không nằm trong dữ liệu form), phía máy chủ nhận rỗng
            // và hiểu là không giới hạn.
            function syncSchedule() {
                fields.start_at.disabled = startAny.checked;
                fields.end_at.disabled = endAny.checked;
            }
            startAny.addEventListener('change', syncSchedule);
            endAny.addEventListener('change', syncSchedule);

            function syncPositionHint() {
                const opt = fields.position.selectedOptions[0];
                positionHint.textContent = opt ? (opt.dataset.hint || '') : '';
            }
            fields.position.addEventListener('change', syncPositionHint);

            function openForm(mode, b) {
                const isEdit = mode === 'edit';
                form.action = isEdit ? `${URL_BASE}/${b.id}` : URL_STORE;
                document.getElementById('bnrFormMethod').value = isEdit ? 'PUT' : 'POST';
                document.getElementById('bnrFormTitle').textContent = isEdit ? 'Sửa banner' : 'Thêm banner';
                document.getElementById('bnrFormSubmit').textContent = isEdit ? 'Cập nhật' : 'Lưu';

                const d = isEdit ? b : { is_active: true, position: FIRST_POSITION };
                document.getElementById('bnrFormId').value = isEdit ? b.id : '';
                fields.title.value = d.title || '';
                fields.link.value = d.link || '';
                fields.position.value = d.position || FIRST_POSITION;
                fields.is_active.value = d.is_active ? '1' : '0';

                // Banner chưa đặt mốc nào thì tick "Vô thời hạn", nhưng ô ngày vẫn
                // điền sẵn mốc gợi ý — bỏ tick là có ngay ngày dùng được.
                const start = toLocalInput(d.start_at);
                const end = toLocalInput(d.end_at);
                startAny.checked = start === '';
                endAny.checked = end === '';
                fields.start_at.value = start !== '' ? start : defaultStart();
                fields.end_at.value = end !== '' ? end : defaultEnd();
                syncSchedule();

                renderImage(d.image || '');
                syncPositionHint();

                // Banner đang bật nhưng chưa/không còn hiện: nói rõ vì sao, đây là thứ
                // hay bị hiểu nhầm là lỗi hệ thống nhất ở màn hình này.
                const state = isEdit ? b.state : null;
                stateNote.style.display = (state === 'scheduled' || state === 'expired') ? '' : 'none';
                if (state === 'scheduled') {
                    stateNote.innerHTML = 'Banner đang bật nhưng <b>chưa tới ngày bắt đầu</b> nên khách chưa nhìn thấy. '
                        + 'Xoá ô “Bắt đầu chạy” để cho chạy ngay.';
                } else if (state === 'expired') {
                    stateNote.innerHTML = 'Banner đang bật nhưng <b>đã qua ngày kết thúc</b> nên đã thôi hiện. '
                        + 'Dời hoặc xoá ô “Kết thúc” để cho chạy lại.';
                }

                openOverlay();
                setTimeout(() => fields.title.focus(), 30);
            }

            document.getElementById('bnrAddBtn').addEventListener('click', () => openForm('add', null));

            // ---------- Ảnh: chọn -> upload -> preview, lưu URL vào input ẩn ----------
            const imageInput = document.getElementById('bnrImage');
            const imgPreview = document.getElementById('bnrImgPreview');
            const imgInput = document.getElementById('bnrImgInput');
            const imgRemove = document.getElementById('bnrImgRemove');
            const PLACEHOLDER = '<svg viewBox="0 0 24 24" width="30" height="30" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="8.5" cy="10" r="1.5"/><path d="m4 17 4.5-4 3 2.5 3.5-3.5 5 5"/></svg>';

            function renderImage(url) {
                imageInput.value = url || '';
                imgPreview.innerHTML = url ? `<img src="${esc(url)}" alt="">` : PLACEHOLDER;
                imgRemove.style.display = url ? '' : 'none';
            }

            document.getElementById('bnrImgPick').addEventListener('click', () => imgInput.click());
            imgRemove.addEventListener('click', () => { renderImage(''); imgInput.value = ''; });
            imgInput.addEventListener('change', async () => {
                const file = imgInput.files && imgInput.files[0];
                if (!file) return;
                if (!file.type.startsWith('image/')) { toastErr('File tải lên không phải ảnh.'); imgInput.value = ''; return; }
                if (file.size > MAX_IMG_BYTES) { toastErr('Ảnh vượt quá 10MB.'); imgInput.value = ''; return; }

                imgPreview.classList.add('is-loading');
                try {
                    const fd = new FormData();
                    fd.append('image', file);
                    const res = await fetch(URL_UPLOAD, {
                        method: 'POST', body: fd,
                        headers: { 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
                    });
                    const data = await res.json().catch(() => ({}));
                    if (!res.ok) { toastErr(data.message || 'Tải ảnh thất bại, vui lòng thử lại.'); return; }
                    renderImage(data.url);
                } catch (err) {
                    toastErr('Không kết nối được máy chủ để tải ảnh.');
                } finally {
                    imgPreview.classList.remove('is-loading');
                    imgInput.value = '';
                }
            });

            // Ảnh là trường bắt buộc nhưng nằm trong input ẩn nên trình duyệt không tự
            // nhắc được — chặn tại đây kèm câu nói rõ thiếu gì.
            form.addEventListener('submit', (e) => {
                if (imageInput.value.trim() === '') {
                    e.preventDefault();
                    toastErr('Chưa chọn ảnh banner. Bấm “Chọn ảnh” để tải lên.');
                    return;
                }
                // Cả hai mốc đều đặt và khai ngược nhau thì banner không hiện ngày nào —
                // chặn ngay tại chỗ thay vì để người bán đi một vòng lên máy chủ.
                const s = startAny.checked ? '' : fields.start_at.value;
                const t = endAny.checked ? '' : fields.end_at.value;
                if (s !== '' && t !== '' && t < s) {
                    e.preventDefault();
                    toastErr('Ngày kết thúc phải sau ngày bắt đầu. Sửa lại mốc, hoặc tick “Vô thời hạn” cho một trong hai.');
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
            const tbody = document.querySelector('.bnr-table tbody');
            tbody.addEventListener('click', (e) => {
                if (e.target.closest('a')) return;

                const mv = e.target.closest('[data-move]');
                if (mv) { move(Number(mv.getAttribute('data-move')), mv.dataset.dir); return; }
                const tg = e.target.closest('[data-toggle]');
                if (tg) { const b = BY_ID.get(Number(tg.getAttribute('data-toggle'))); if (b) toggleStatus(tg, b); return; }
                const rm = e.target.closest('[data-remove]');
                if (rm) { const b = BY_ID.get(Number(rm.getAttribute('data-remove'))); if (b) removeBanner(b); return; }
                const ed = e.target.closest('[data-edit]');
                if (ed) { const b = BY_ID.get(Number(ed.getAttribute('data-edit'))); if (b) openForm('edit', b); return; }
            });

            // ---------- Chọn dòng + bulk ----------
            const selected = new Set();
            const checkAll = document.getElementById('bnrCheckAll');
            const rowChecks = () => Array.from(document.querySelectorAll('.bnr-row-check'));

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
                // Nói trước bao nhiêu cái đang hiện ngoài cửa hàng sẽ biến mất.
                const live = [...selected].filter((id) => BY_ID.get(id)?.state === 'live').length;
                $bulkMount.innerHTML = `
                    <div class="bnr-bulk">
                        <span class="bnr-bulk-text">Đã chọn <b>${n}</b> banner${live ? ` · ${live} đang hiện trên cửa hàng` : ''}</span>
                        <button type="button" class="bnr-bulk-clear" id="bnrBulkClear">Bỏ chọn</button>
                        <button type="button" class="bnr-bulk-del" id="bnrBulkDel">
                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/></svg>
                            Xoá (${n})
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
                if (e.target.closest('#bnrBulkClear')) {
                    selected.clear();
                    rowChecks().forEach((cb) => { cb.checked = false; cb.closest('tr').classList.remove('is-selected'); });
                    syncHeader(); renderBulk();
                } else if (e.target.closest('#bnrBulkDel')) {
                    const ids = [...selected];
                    const live = ids.filter((id) => BY_ID.get(id)?.state === 'live').length;
                    sysDelete({
                        title: 'Xác nhận xoá hàng loạt',
                        message: `Bạn có chắc chắn muốn xoá ${ids.length} banner đã chọn?`
                            + (live ? ` ${live} banner đang hiện trên cửa hàng sẽ biến mất ngay.` : '')
                            + ' Hành động này không thể hoàn tác.',
                        highlightText: `Số lượng: ${ids.length} banner`
                    }).then((confirmed) => {
                        if (confirmed) {
                            const fields = {};
                            ids.forEach((id, i) => { fields[`ids[${i}]`] = id; });
                            postForm(URL_BULK, 'POST', fields);
                        }
                    });
                }
            });

            // ---------- Dropdown Tiện ích ----------
            const util = document.getElementById('bnrUtil');
            document.getElementById('bnrUtilBtn').addEventListener('click', (e) => {
                e.stopPropagation();
                const open = !util.classList.contains('open');
                util.classList.toggle('open', open);
                e.currentTarget.setAttribute('aria-expanded', open ? 'true' : 'false');
            });
            document.addEventListener('click', () => {
                util.classList.remove('open');
                document.getElementById('bnrUtilBtn').setAttribute('aria-expanded', 'false');
            });

            // Lưu hỏng (API từ chối, mất kết nối) thì mở lại modal kèm nguyên thứ vừa
            // gõ — bắt người bán chọn lại ảnh và gõ lại lịch chạy từ đầu là mất công
            // nhất trong cả màn hình này.
            @php $old = old(); @endphp
            @if(!empty($old['image']))
                openForm(@json(!empty($old['id']) ? 'edit' : 'add'), {
                    id: @json($old['id'] ?? null),
                    title: @json($old['title'] ?? ''),
                    link: @json($old['link'] ?? ''),
                    position: @json($old['position'] ?? ''),
                    is_active: @json((bool) ($old['is_active'] ?? false)),
                    start_at: @json($old['start_at'] ?? ''),
                    end_at: @json($old['end_at'] ?? ''),
                    image: @json($old['image']),
                });
            @endif
        })();
    </script>
@endsection
