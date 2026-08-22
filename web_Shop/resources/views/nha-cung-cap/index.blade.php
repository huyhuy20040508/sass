@extends('layouts.app')

@section('title', \App\Http\Controllers\NhaCungCapController::TITLE_PAGE)

@section('content')
    {{-- Tên ô của form = tên trường bên v2 nên controller gửi thẳng payload đi. --}}
    @php
        $C = \App\Http\Controllers\NhaCungCapController::class;
        $TITLE = $C::TITLE_PAGE;
        $EMPTY_TEXT = $C::EMPTY_TEXT;

        $so = fn ($n) => number_format((float) $n, 0, ',', '.');
        $tien = fn ($n) => ((float) $n) != 0.0 ? number_format((float) $n, 0, ',', '.').'₫' : '—';

        // Lọc phụ nằm trong "Nâng cao"; đang bật thì hàng đó tự mở kèm con số.
        $advCount = $filters['sort'] !== 'moi_nhat' ? 1 : 0;
        $advOpen = $advCount > 0;
        $hasFilter = $advCount > 0 || $filters['keyword'] !== '' || $filters['status'] !== '';

        $stt = ($meta['page'] - 1) * $meta['page_size'];
    @endphp

    <div class="ncc">
        <div class="ncc-head">
            <h1 class="ncc-title">{{ $TITLE }}</h1>
            <span class="ncc-sum">
                Đang hợp tác: <b>{{ $so($thongKe['dang_hop_tac']) }}</b>/{{ $so($thongKe['tong']) }}
            </span>
        </div>

        @if(!empty($error))
            <p class="ncc-callout is-error">{{ $error }}</p>
        @endif

        {{-- Lọc realtime: đổi select chạy ngay, gõ thì chờ 400ms. --}}
        <form method="GET" action="{{ route('admin.nha-cung-cap.index') }}" id="nccFilter" class="ncc-filter">
            <div class="ncc-toolbar">
                <div class="ncc-searchbox">
                    <input type="text" name="keyword" value="{{ $filters['keyword'] }}" class="ncc-search-input"
                           placeholder="Tìm theo mã, tên, số điện thoại hoặc MST" autocomplete="off">
                    <button type="submit" class="ncc-search-btn" title="Tìm kiếm">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                    </button>
                </div>

                <select name="status" class="ncc-select" title="Lọc theo tình trạng hợp tác">
                    <option value="">Tất cả trạng thái</option>
                    @foreach($C::TRANG_THAI as $ma => $ten)
                        <option value="{{ $ma }}" @selected($filters['status'] === (string) $ma)>{{ $ten }}</option>
                    @endforeach
                </select>

                <button type="button" id="nccAdvToggle" class="ncc-adv-btn {{ $advOpen ? 'is-open' : '' }}"
                        aria-expanded="{{ $advOpen ? 'true' : 'false' }}">
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M7 12h10M11 18h2"/></svg>
                    Nâng cao
                    <svg class="ncc-adv-caret" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                    @if($advCount > 0)<span class="ncc-adv-count">{{ $advCount }}</span>@endif
                </button>

                @if($hasFilter)
                    <a href="{{ route('admin.nha-cung-cap.index') }}" class="ncc-clear">Xoá lọc</a>
                @endif

                <div class="ncc-toolbar-actions">
                    <button type="button" class="ncc-btn-primary" id="nccAddBtn">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M12 5v14"/></svg>
                        Thêm nhà cung cấp
                    </button>

                    <div class="ncc-util" id="nccUtil">
                        <button type="button" class="ncc-util-btn" id="nccUtilBtn" aria-haspopup="true" aria-expanded="false">
                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="1.6"/><circle cx="19" cy="12" r="1.6"/><circle cx="5" cy="12" r="1.6"/></svg>
                            Tiện ích
                            <svg class="ncc-util-caret" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                        </button>
                        <div class="ncc-util-menu">
                            <button type="button" class="ncc-util-item" id="nccImportBtn">
                                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M17 8l-5-5-5 5"/><path d="M12 3v12"/></svg>
                                Nhập file
                            </button>
                            <a href="{{ route('admin.nha-cung-cap.mauNhap') }}" class="ncc-util-item">
                                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6"/><path d="M12 18v-6"/><path d="m9 15 3 3 3-3"/></svg>
                                Tải file mẫu
                            </a>
                            {{-- Xuất mang theo đúng bộ lọc đang xem. --}}
                            <a href="{{ route('admin.nha-cung-cap.export', request()->query()) }}" class="ncc-util-item">
                                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5"/><path d="M12 15V3"/></svg>
                                Xuất file (CSV)
                            </a>
                        </div>
                    </div>

                    {{-- Xem cột — lựa chọn lưu ở localStorage. --}}
                    <div class="ncc-util" id="nccCotBox">
                        <button type="button" class="ncc-util-btn ncc-btn-sq" id="nccCotBtn" title="Xem cột" aria-expanded="false">
                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 21v-7M4 10V3M12 21v-9M12 8V3M20 21v-5M20 12V3"/><path d="M1 14h6M9 8h6M17 16h6"/></svg>
                        </button>
                        <div class="ncc-util-menu ncc-cot-menu">
                            <label class="ncc-cot-item is-all">
                                <input type="checkbox" id="nccCotAll" checked>
                                <span>Tất cả</span>
                            </label>
                            <div class="ncc-cot-line"></div>
                            @foreach($C::COT_BANG as $ma => $ten)
                                <label class="ncc-cot-item">
                                    <input type="checkbox" class="ncc-cot-cb" data-cot="{{ $ma }}" checked>
                                    <span>{{ $ten }}</span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>

            <div class="ncc-toolbar-adv {{ $advOpen ? 'is-open' : '' }}" id="nccAdvRow">
                <select name="sort" class="ncc-select" title="Sắp xếp">
                    @foreach($C::SAP_XEP as $ma => $ten)
                        <option value="{{ $ma }}" @selected($filters['sort'] === $ma)>{{ $ten }}</option>
                    @endforeach
                </select>
            </div>

            <input type="hidden" name="page_size" value="{{ $meta['page_size'] }}">
        </form>

        {{-- Bề rộng cột khai theo %, cộng đúng 100%. --}}
        <div class="ncc-table-wrap">
            <table class="ncc-table">
                <thead>
                    <tr>
                        <th class="ncc-c-check"><input type="checkbox" id="nccCheckAll" class="ncc-check" title="Chọn hết dòng đang hiện"></th>
                        <th class="ncc-c-stt">STT</th>
                        <th class="ncc-c-code">Mã NCC</th>
                        <th class="ncc-c-name">Tên nhà cung cấp</th>
                        <th class="ncc-c-tax">Mã số thuế</th>
                        <th class="ncc-c-phone">Điện thoại</th>
                        <th class="ncc-c-email">Email</th>
                        <th class="ncc-c-addr">Địa chỉ</th>
                        <th class="ncc-c-addr2">Địa chỉ 2</th>
                        <th class="ncc-c-status">Trạng thái</th>
                        <th class="ncc-c-act">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($list as $i => $ncc)
                        @php
                            $id = (int) ($ncc['id'] ?? 0);
                            $ten = $ncc['name'] ?? '';
                            $bat = (int) ($ncc['status'] ?? 1) === $C::DANG_HOP_TAC;
                        @endphp
                        <tr data-id="{{ $id }}">
                            <td class="ncc-c-check">
                                <input type="checkbox" class="ncc-check ncc-row-check" value="{{ $id }}"
                                       aria-label="Chọn nhà cung cấp {{ $ten !== '' ? $ten : $id }}">
                            </td>
                            <td class="ncc-c-stt">{{ $stt + $i + 1 }}</td>
                            <td class="ncc-c-code"><span class="ncc-code">{{ ($ncc['code'] ?? '') ?: '—' }}</span></td>
                            <td class="ncc-c-name" data-detail="{{ $id }}" title="Bấm để xem chi tiết">
                                <span class="ncc-name">{{ $ten !== '' ? $ten : '—' }}</span>
                                @if(!empty($ncc['short_name']))
                                    <span class="ncc-sub">{{ $ncc['short_name'] }}</span>
                                @endif
                            </td>
                            <td class="ncc-c-tax">{{ ($ncc['tax_code'] ?? '') ?: '—' }}</td>
                            <td class="ncc-c-phone">
                                @if(!empty($ncc['phone']))
                                    <a class="ncc-phone" href="tel:{{ preg_replace('/\s+/', '', $ncc['phone']) }}">{{ $ncc['phone'] }}</a>
                                @else
                                    <span class="ncc-muted">—</span>
                                @endif
                            </td>
                            <td class="ncc-c-email" title="{{ $ncc['email'] ?? '' }}">{{ ($ncc['email'] ?? '') ?: '—' }}</td>
                            <td class="ncc-c-addr" title="{{ $ncc['address'] ?? '' }}">{{ ($ncc['address'] ?? '') ?: '—' }}</td>
                            <td class="ncc-c-addr2" title="{{ $ncc['address_line2'] ?? '' }}">{{ ($ncc['address_line2'] ?? '') ?: '—' }}</td>
                            <td class="ncc-c-status">
                                <button type="button" class="ncc-switch {{ $bat ? 'on' : '' }}"
                                        data-toggle="{{ $id }}" data-on="{{ $bat ? 1 : 0 }}"
                                        title="{{ $bat ? 'Đang hợp tác — bấm để ngừng' : 'Đã ngừng — bấm để hợp tác lại' }}">
                                    <span class="ncc-switch-knob"></span>
                                </button>
                            </td>
                            <td class="ncc-c-act">
                                <div class="ncc-rowacts">
                                    <button type="button" class="ncc-rowbtn" data-detail="{{ $id }}" title="Xem chi tiết">
                                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                                    </button>
                                    <button type="button" class="ncc-rowbtn" data-edit="{{ $id }}" title="Sửa">
                                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                                    </button>
                                    <button type="button" class="ncc-rowbtn" data-copy="{{ $id }}" title="Nhân bản">
                                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                                    </button>
                                    <button type="button" class="ncc-rowbtn is-danger" data-remove="{{ $id }}" title="Xoá">
                                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/></svg>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="14" class="ncc-empty">
                                @if($hasFilter)
                                    Không tìm thấy nhà cung cấp nào khớp bộ lọc. Thử xoá bớt điều kiện lọc.
                                @else
                                    {{ $EMPTY_TEXT }}
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @include('partials.pagination-v2', [
            'meta' => $meta,
            'noun' => 'nhà cung cấp',
            'perPageName' => 'page_size',
            'perPageOptions' => $C::MUC_SO_DONG,
        ])
    </div>

    <div id="nccBulkMount"></div>

    {{-- Modal Thêm / Sửa --}}
    <div class="ncc-overlay" id="nccFormOverlay" style="display:none;">
        <div class="ncc-dialog">
            <div class="ncc-modal-head">
                <h4 class="ncc-modal-title" id="nccFormTitle">Thêm nhà cung cấp</h4>
                <button type="button" class="ncc-modal-x" data-close>
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </div>

            <form id="nccForm" method="POST" action="{{ route('admin.nha-cung-cap.store') }}">
                @csrf
                <input type="hidden" name="_method" id="nccFormMethod" value="POST">
                <input type="hidden" name="return" value="{{ request()->getRequestUri() }}">
                {{-- Lưu hỏng thì lượt mở lại đọc ô này để biết đang sửa ai. --}}
                <input type="hidden" name="id" id="nccId" value="">

                <div class="ncc-modal-body">
                    <div class="ncc-form-cols">
                        <div class="ncc-col-anh">
                            <label class="ncc-field-label">Hình ảnh</label>
                            <div class="ncc-anh-khung" id="nccAnhKhung">
                                <img id="nccAnhXem" alt="" style="display:none;">
                                <span class="ncc-anh-chu" id="nccAnhChu">Chưa có ảnh</span>
                            </div>
                            <input type="hidden" name="image" id="nccAnh" value="">
                            <label class="ncc-anh-nut">
                                <span id="nccAnhNutChu">Chọn ảnh</span>
                                <input type="file" id="nccAnhFile" accept="image/*" hidden>
                            </label>
                            <button type="button" class="ncc-anh-go" id="nccAnhGo" style="display:none;">Gỡ ảnh</button>

                            <div class="ncc-status-box">
                                <label class="ncc-field-label">Trạng thái</label>
                                <div class="ncc-switch-row">
                                    <button type="button" class="ncc-switch on" id="nccTrangThai"
                                            aria-pressed="true" title="Bấm để đổi trạng thái">
                                        <span class="ncc-switch-knob"></span>
                                    </button>
                                    <span class="ncc-switch-label" id="nccTrangThaiChu">Đang hợp tác</span>
                                </div>
                                <input type="hidden" name="status" id="nccTrangThaiValue" value="1">
                                <p class="ncc-hint">Tắt đi là ngừng hợp tác — bên này không còn hiện trong ô chọn khi lập phiếu.</p>
                            </div>
                        </div>

                        <div class="ncc-col-o">
                            <div class="ncc-form-grid">
                                <div class="ncc-field">
                                    <label class="ncc-field-label" for="nccMa">Mã nhà cung cấp</label>
                                    <input type="text" id="nccMa" name="code" class="ncc-input" maxlength="30"
                                           autocomplete="off" placeholder="Bỏ trống để tự sinh theo quy tắc mã">
                                </div>
                                <div class="ncc-field">
                                    <label class="ncc-field-label" for="nccTen">Tên nhà cung cấp <span class="ncc-req">*</span></label>
                                    <input type="text" id="nccTen" name="name" class="ncc-input" maxlength="150" required
                                           autocomplete="off" placeholder="VD: Công ty TNHH Thực phẩm An Bình">
                                </div>
                                <div class="ncc-field">
                                    <label class="ncc-field-label" for="nccTenTat">Tên viết tắt</label>
                                    <input type="text" id="nccTenTat" name="short_name" class="ncc-input" maxlength="100"
                                           autocomplete="off" placeholder="VD: An Bình">
                                </div>
                                <div class="ncc-field">
                                    <label class="ncc-field-label" for="nccDienThoai">Điện thoại</label>
                                    <input type="text" id="nccDienThoai" name="phone" class="ncc-input" maxlength="20"
                                           autocomplete="off" placeholder="09xxxxxxxx">
                                </div>
                                <div class="ncc-field">
                                    <label class="ncc-field-label" for="nccEmail">Email</label>
                                    <input type="email" id="nccEmail" name="email" class="ncc-input" maxlength="191"
                                           autocomplete="off" placeholder="email@congty.vn">
                                </div>
                                <div class="ncc-field">
                                    <label class="ncc-field-label" for="nccMST">Mã số thuế</label>
                                    <input type="text" id="nccMST" name="tax_code" class="ncc-input" maxlength="30"
                                           autocomplete="off" placeholder="Cần khi lấy hoá đơn VAT">
                                </div>
                                <div class="ncc-field">
                                    <label class="ncc-field-label" for="nccDaiDien">Người đại diện</label>
                                    <input type="text" id="nccDaiDien" name="representative_name" class="ncc-input" maxlength="150"
                                           autocomplete="off" placeholder="VD: Anh Tuấn — phụ trách kinh doanh">
                                </div>
                                <div class="ncc-field">
                                    <label class="ncc-field-label" for="nccDaiDienSDT">SĐT người đại diện</label>
                                    <input type="text" id="nccDaiDienSDT" name="representative_phone" class="ncc-input" maxlength="20"
                                           autocomplete="off" placeholder="09xxxxxxxx">
                                </div>

                                <div class="ncc-field is-full">
                                    <label class="ncc-field-label" for="nccDiaChi">Địa chỉ <span class="ncc-req">*</span></label>
                                    <textarea id="nccDiaChi" name="address" class="ncc-textarea" rows="2" maxlength="255"
                                              placeholder="Số nhà, đường, phường/xã, tỉnh/thành"></textarea>
                                </div>
                                <div class="ncc-field is-full">
                                    <label class="ncc-field-label" for="nccDiaChi2">Địa chỉ 2</label>
                                    <textarea id="nccDiaChi2" name="address_line2" class="ncc-textarea" rows="2" maxlength="200"
                                              placeholder="Kho hàng, chi nhánh giao dịch khác…"></textarea>
                                </div>
                                <div class="ncc-field is-full">
                                    <label class="ncc-field-label" for="nccGhiChu">Ghi chú</label>
                                    <textarea id="nccGhiChu" name="note" class="ncc-textarea" rows="2" maxlength="500"
                                              placeholder="VD: giao hàng 3-5 ngày, chiết khấu 5% từ 50 thùng"></textarea>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="ncc-modal-foot">
                    <button type="button" class="ncc-btn-ghost" data-close>Đóng</button>
                    <button type="submit" class="ncc-btn-primary" id="nccFormSubmit">Lưu</button>
                </div>
            </form>
        </div>
    </div>

    {{-- Modal Chi tiết --}}
    <div class="ncc-overlay" id="nccDetailOverlay" style="display:none;">
        <div class="ncc-dialog ncc-dialog-lg">
            <div class="ncc-modal-head">
                <h4 class="ncc-modal-title" id="nccDetailTitle">Chi tiết nhà cung cấp</h4>
                <button type="button" class="ncc-modal-x" data-close>
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </div>

            <div class="ncc-modal-body">
                <div class="ncc-tab-pane is-active" data-pane="ho-so">
                    <div class="ncc-view-cols">
                        <div class="ncc-view-anh">
                            <div class="ncc-anh-khung is-view">
                                <img id="nccViewAnh" alt="" style="display:none;">
                                <span class="ncc-anh-chu" id="nccViewAnhChu">Chưa có ảnh</span>
                            </div>
                            {{-- Khoá lại: muốn đổi thì bấm Sửa. --}}
                            <div class="ncc-switch-row">
                                <button type="button" class="ncc-switch on" id="nccViewTrangThai" disabled>
                                    <span class="ncc-switch-knob"></span>
                                </button>
                                <span class="ncc-switch-label" id="nccViewTrangThaiChu">Đang hợp tác</span>
                            </div>
                        </div>
                        <div class="ncc-view-grid" id="nccViewGrid"></div>
                    </div>
                </div>

            </div>

            <div class="ncc-modal-foot">
                <button type="button" class="ncc-btn-ghost" data-close>Đóng</button>
                <button type="button" class="ncc-btn-primary" id="nccDetailEdit">Sửa</button>
            </div>
        </div>
    </div>

    {{-- Modal Nhập file --}}
    <div class="ncc-overlay" id="nccImportOverlay" style="display:none;">
        <div class="ncc-dialog ncc-dialog-sm">
            <div class="ncc-modal-head">
                <h4 class="ncc-modal-title">Nhập nhà cung cấp từ file</h4>
                <button type="button" class="ncc-modal-x" data-close>
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </div>

            <form method="POST" action="{{ route('admin.nha-cung-cap.import') }}" enctype="multipart/form-data">
                @csrf
                <input type="hidden" name="return" value="{{ request()->getRequestUri() }}">

                <div class="ncc-modal-body">
                    <div class="ncc-field">
                        <label class="ncc-field-label" for="nccFile">File CSV <span class="ncc-req">*</span></label>
                        <input type="file" id="nccFile" name="file" class="ncc-input ncc-file" accept=".csv,text/csv" required>
                    </div>

                    <p class="ncc-note-box">
                        Đúng 9 cột theo thứ tự: <b>STT · Mã NCC · Tên NCC · MST · Điện thoại · Email · Địa chỉ ·
                        Địa chỉ 2 · Trạng thái</b> (1 là đang hợp tác, 0 là ngừng).
                        Bỏ trống mã thì hệ thống tự sinh. Một dòng sai là dừng cả lượt, chưa ghi dòng nào.
                        <a href="{{ route('admin.nha-cung-cap.mauNhap') }}">Tải file mẫu</a>.
                    </p>
                </div>

                <div class="ncc-modal-foot">
                    <button type="button" class="ncc-btn-ghost" data-close>Đóng</button>
                    <button type="submit" class="ncc-btn-primary">Nhập file</button>
                </div>
            </form>
        </div>
    </div>

    <style id="nccCotCss"></style>

    <style>
        .ncc {
            /* Phá padding p-4 của <main> để tràn viền như các trang danh sách khác */
            margin: -1.5rem;
            min-height: calc(100vh - 56px);
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            color: #262626; background: #fff;
        }

        /* Header */
        .ncc-head { display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; padding: 14px 20px; }
        .ncc-title { margin: 0; font-size: 16px; font-weight: 700; line-height: 34px; }
        .ncc-sum { font-size: 13px; color: #595959; }
        .ncc-sum b { color: #262626; }
        .ncc-callout { margin: 0 20px 12px; padding: 10px 12px; border-radius: 6px; font-size: 13px; }
        .ncc-callout.is-error { background: #fff1f0; color: #cf1322; }

        /* Bộ lọc */
        .ncc-filter { padding: 0 20px 12px; margin-bottom: 12px; border-bottom: 1px solid #eee; }
        .ncc-toolbar { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; }
        .ncc-toolbar-actions { margin-left: auto; display: flex; align-items: center; gap: 8px; }
        .ncc-toolbar-adv { display: none; flex-wrap: wrap; align-items: center; gap: 12px; margin-top: 12px; }
        .ncc-toolbar-adv.is-open { display: flex; }
        .ncc-adv-btn {
            height: 34px; display: inline-flex; align-items: center; gap: 6px; border: 1px solid #d9d9d9;
            border-radius: 4px; background: #fff; padding: 0 12px; font-size: 13px; color: #595959; cursor: pointer;
            transition: border-color .15s, color .15s;
        }
        .ncc-adv-btn:hover, .ncc-adv-btn.is-open { border-color: #1890ff; color: #1890ff; }
        .ncc-adv-caret { transition: transform .2s; }
        .ncc-adv-btn.is-open .ncc-adv-caret { transform: rotate(180deg); }
        .ncc-adv-count {
            min-width: 18px; height: 18px; padding: 0 5px; border-radius: 9999px; background: #1890ff; color: #fff;
            font-size: 11px; font-weight: 700; display: inline-flex; align-items: center; justify-content: center;
        }

        .ncc-searchbox { display: flex; border-radius: 4px; }
        .ncc-searchbox:focus-within { box-shadow: 0 0 0 4px rgba(13, 110, 253, .25); }
        .ncc-search-input {
            height: 34px; width: 300px; border: 1px solid #d9d9d9; border-right: 0; border-radius: 4px 0 0 4px;
            background: #fff; padding: 0 12px; font-size: 13px; outline: none;
        }
        .ncc-search-input::placeholder { color: #bfbfbf; }
        .ncc-searchbox:focus-within .ncc-search-input,
        .ncc-searchbox:focus-within .ncc-search-btn { border-color: #86b7fe; }
        .ncc-search-btn {
            height: 34px; width: 40px; display: inline-flex; align-items: center; justify-content: center;
            border: 1px solid #d9d9d9; border-radius: 0 4px 4px 0; background: #fafafa; color: #595959; cursor: pointer;
        }
        .ncc-search-btn:hover { color: #1890ff; }

        .ncc-select {
            height: 34px; border: 1px solid #d9d9d9; border-radius: 4px; background-color: #fff;
            padding: 0 30px 0 12px; font-size: 13px; color: #262626; cursor: pointer; outline: none;
            max-width: 220px; appearance: none; -webkit-appearance: none;
            background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23595959' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><path d='m6 9 6 6 6-6'/></svg>");
            background-repeat: no-repeat; background-position: right 8px center;
        }
        .ncc-select:focus { border-color: #86b7fe; box-shadow: 0 0 0 .25rem rgba(13, 110, 253, .25); }
        .ncc-clear {
            display: inline-flex; align-items: center; height: 34px; padding: 0 10px; border-radius: 4px;
            font-size: 13px; color: #8c8c8c; text-decoration: none;
        }
        .ncc-clear:hover { background: #f5f5f5; color: #262626; }

        /* Nút chung */
        .ncc-btn-primary, .ncc-btn-ghost, .ncc-btn-danger {
            height: 34px; display: inline-flex; align-items: center; justify-content: center; gap: 6px;
            padding: 0 14px; border-radius: 4px; font-size: 13px; font-weight: 500; cursor: pointer;
            border: 1px solid transparent; text-decoration: none; transition: background .15s, border-color .15s, color .15s;
        }
        .ncc-btn-primary { background: #1890ff; color: #fff; }
        .ncc-btn-primary:hover:not([disabled]) { background: #0f7ae5; }
        .ncc-btn-ghost { background: #fff; border-color: #d9d9d9; color: #262626; }
        .ncc-btn-ghost:hover:not([disabled]) { border-color: #1890ff; color: #1890ff; }
        .ncc-btn-danger { background: #fff; border-color: #ffa39e; color: #ff4d4f; }
        .ncc-btn-danger:hover:not([disabled]) { background: #ff4d4f; border-color: #ff4d4f; color: #fff; }

        /* Dropdown tiện ích */
        .ncc-util { position: relative; }
        .ncc-util-btn {
            height: 34px; display: inline-flex; align-items: center; gap: 6px; border: 1px solid #d9d9d9;
            border-radius: 4px; background: #fff; padding: 0 12px; font-size: 13px; color: #595959; cursor: pointer;
        }
        .ncc-util-btn:hover { border-color: #1890ff; color: #1890ff; }
        .ncc-util-caret { transition: transform .2s; }
        .ncc-util.open .ncc-util-caret { transform: rotate(180deg); }
        .ncc-util-menu {
            display: none; position: absolute; right: 0; top: calc(100% + 4px); z-index: 1050; min-width: 190px;
            background: #fff; border: 1px solid #e6e6e6; border-radius: 6px; box-shadow: 0 6px 20px rgba(0, 0, 0, .12);
            padding: 4px; flex-direction: column;
        }
        .ncc-util.open .ncc-util-menu { display: flex; }
        .ncc-util-item {
            display: flex; align-items: center; gap: 8px; width: 100%; padding: 8px 10px; border: 0; border-radius: 4px;
            background: none; font-size: 13px; color: #262626; text-decoration: none; cursor: pointer;
        }
        .ncc-util-item:hover { background: #f5f5f5; color: #1890ff; }

        /* Bảng — mọi ô canh giữa, bề rộng khai theo % và cộng đúng 100%. */
        .ncc-table-wrap { width: 100%; padding: 0 20px; overflow-x: auto; scrollbar-width: thin; }
        .ncc-table-wrap::-webkit-scrollbar { height: 11px; }
        .ncc-table-wrap::-webkit-scrollbar-thumb { background-color: #dcdcdc; border-radius: 8px; border: 3px solid #fff; }
        .ncc-table { width: 100%; min-width: 1120px; border-collapse: collapse; font-size: 13px; table-layout: fixed; }
        .ncc-table thead tr { background: #f0f0f0; color: #262626; }
        .ncc-table thead th { text-align: center; padding: 14px 10px; font-size: 13px; font-weight: 700; white-space: nowrap; }
        .ncc-table tbody td {
            padding: 14px 10px; border-bottom: 1px solid #f0f0f0; vertical-align: middle;
            text-align: center; white-space: nowrap; line-height: 1.5;
        }
        .ncc-table tbody tr:hover { background: #fafafa; }
        .ncc-table tbody tr.is-selected { background: #e6f7ff; }

        .ncc-table th.ncc-c-check,  .ncc-table td.ncc-c-check  { width: 3%; }
        .ncc-table th.ncc-c-stt,    .ncc-table td.ncc-c-stt    { width: 3%; color: #8c8c8c; }
        .ncc-table th.ncc-c-code,   .ncc-table td.ncc-c-code   { width: 7%; }
        .ncc-table th.ncc-c-name,   .ncc-table td.ncc-c-name   { width: 13%; overflow: hidden; text-overflow: ellipsis; cursor: pointer; }
        .ncc-table th.ncc-c-tax,    .ncc-table td.ncc-c-tax    { width: 7%; }
        .ncc-table th.ncc-c-phone,  .ncc-table td.ncc-c-phone  { width: 7%; }
        .ncc-table th.ncc-c-email,  .ncc-table td.ncc-c-email  { width: 16%; overflow: hidden; text-overflow: ellipsis; }
        .ncc-table th.ncc-c-addr,   .ncc-table td.ncc-c-addr   { width: 16%; overflow: hidden; text-overflow: ellipsis; color: #595959; }
        .ncc-table th.ncc-c-addr2,  .ncc-table td.ncc-c-addr2  { width: 16%; overflow: hidden; text-overflow: ellipsis; color: #595959; }
        .ncc-table th.ncc-c-status, .ncc-table td.ncc-c-status { width: 5%; }
        .ncc-table th.ncc-c-act,    .ncc-table td.ncc-c-act    { width: 7%; }

        .ncc-code { font-weight: 600; color: #1890ff; }
        .ncc-name { display: block; font-weight: 500; overflow: hidden; text-overflow: ellipsis; }
        .ncc-sub { display: block; margin-top: 2px; font-size: 12px; color: #8c8c8c; overflow: hidden; text-overflow: ellipsis; }
        .ncc-muted { color: #bfbfbf; }
        .ncc-phone { color: #262626; text-decoration: none; }
        .ncc-phone:hover { color: #1890ff; }
        .ncc-empty { padding: 40px 12px; text-align: center; color: #8c8c8c; white-space: normal; }
        .ncc-check { width: 15px; height: 15px; cursor: pointer; accent-color: #1890ff; }

        .ncc-rowacts { display: inline-flex; align-items: center; justify-content: center; gap: 6px; }
        .ncc-rowbtn {
            width: 30px; height: 30px; display: inline-flex; align-items: center; justify-content: center;
            border: 1px solid #d9d9d9; border-radius: 4px; background: #fff; color: #595959; cursor: pointer;
            transition: border-color .15s, color .15s;
        }
        .ncc-rowbtn:hover { border-color: #1890ff; color: #1890ff; }
        .ncc-rowbtn.is-danger:hover { border-color: #ff4d4f; color: #ff4d4f; }

        /* Công tắc trạng thái ngoài bảng */
        .ncc-switch {
            position: relative; display: inline-flex; align-items: center; height: 22px; width: 42px; border: 0;
            border-radius: 9999px; background: #ddd; cursor: pointer; padding: 0; transition: background .15s;
        }
        .ncc-switch.on { background: #7083b6; }
        .ncc-switch-knob {
            display: inline-block; height: 16px; width: 16px; border-radius: 50%; background: #fff;
            box-shadow: 0 0 3px rgba(0,0,0,.3); transform: translateX(3px); transition: transform .15s;
        }
        .ncc-switch.on .ncc-switch-knob { transform: translateX(23px); }
        .ncc-switch[disabled] { cursor: default; opacity: .75; }

        /* Thanh thao tác hàng loạt — pill trắng nổi giữa đáy vùng nội dung */
        .ncc-bulk {
            position: fixed; bottom: 24px; left: 230px; right: 0; margin-inline: auto; z-index: 1070;
            width: max-content; max-width: calc(100vw - 260px);
            display: flex; align-items: center; gap: 16px; border: 1px solid #e6e6e6; border-radius: 9999px;
            background: #fff; padding: 12px 20px; box-shadow: 0 6px 24px rgba(0, 0, 0, .15);
        }
        body:has(.jh-sidebar.collapsed) .ncc-bulk { left: 48px; }
        @media (max-width: 820px) { .ncc-bulk { left: 0; } }
        .ncc-bulk-count { font-size: 13px; white-space: nowrap; }
        .ncc-bulk-count b { color: #1890ff; }
        .ncc-bulk-clear { border: 0; background: none; font-size: 13px; color: #8c8c8c; cursor: pointer; padding: 0; }
        .ncc-bulk-clear:hover { color: #262626; }
        .ncc-bulk-actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .ncc-bulk-actions .ncc-btn-ghost, .ncc-bulk-actions .ncc-btn-danger { height: 30px; }

        /* Nút vuông chỉ có icon + menu chọn cột */
        .ncc-btn-sq { width: 34px; padding: 0; justify-content: center; flex-shrink: 0; }
        .ncc-cot-menu { min-width: 210px; max-height: 320px; overflow-y: auto; }
        .ncc-cot-item {
            display: flex; align-items: center; gap: 8px; padding: 6px 10px; border-radius: 4px;
            font-size: 13px; color: #262626; cursor: pointer; margin: 0;
        }
        .ncc-cot-item:hover { background: #f5f5f5; }
        .ncc-cot-item input { width: 14px; height: 14px; accent-color: #1890ff; cursor: pointer; }
        .ncc-cot-item.is-all { font-weight: 600; }
        .ncc-cot-line { height: 1px; margin: 4px 0; background: #f0f0f0; }

        .ncc-file { height: auto; padding: 7px 10px; }
        .ncc-note-box { margin: 0; padding: 10px 12px; border-radius: 6px; background: #f6f8fa; font-size: 12px; color: #595959; }

        /* Modal */
        .ncc-overlay {
            position: fixed; inset: 0; z-index: 1055; background: rgba(0, 0, 0, .45);
            display: flex; align-items: center; justify-content: center; padding: 16px;
        }
        .ncc-dialog {
            width: 100%; max-width: 940px; max-height: 92vh; overflow-y: auto; background: #fff;
            border-radius: 10px; box-shadow: 0 12px 40px rgba(0, 0, 0, .2); scrollbar-width: thin;
        }
        .ncc-dialog-lg { max-width: 1080px; }
        .ncc-dialog-sm { max-width: 520px; }
        .ncc-modal-head {
            position: sticky; top: 0; z-index: 2; background: #fff;
            display: flex; align-items: center; justify-content: space-between; gap: 12px;
            padding: 14px 20px; border-bottom: 1px solid #f0f0f0;
        }
        .ncc-modal-title { margin: 0; font-size: 15px; font-weight: 700; }
        .ncc-modal-x { border: 0; background: none; color: #8c8c8c; cursor: pointer; padding: 4px; border-radius: 4px; }
        .ncc-modal-x:hover { background: #f5f5f5; color: #262626; }
        .ncc-modal-body { padding: 16px 20px; display: flex; flex-direction: column; gap: 16px; }
        /* Hàng nút chân hộp thoại luôn canh giữa */
        .ncc-modal-foot {
            position: sticky; bottom: 0; z-index: 2; display: flex; align-items: center; justify-content: center;
            gap: 12px; flex-wrap: wrap; padding: 12px 20px; border-top: 1px solid #f0f0f0; background: #fafafa;
        }

        /* Form */
        .ncc-form-cols { display: grid; grid-template-columns: 220px 1fr; gap: 20px; }
        @media (max-width: 720px) { .ncc-form-cols { grid-template-columns: 1fr; } }
        .ncc-col-anh { display: flex; flex-direction: column; gap: 8px; }
        .ncc-form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px 16px; }
        @media (max-width: 720px) { .ncc-form-grid { grid-template-columns: 1fr; } }
        .ncc-field { display: flex; flex-direction: column; gap: 4px; min-width: 0; }
        .ncc-field.is-full { grid-column: 1 / -1; }
        .ncc-field-label { font-size: 12px; font-weight: 600; color: #595959; }
        .ncc-input, .ncc-textarea {
            border: 1px solid #d9d9d9; border-radius: 4px; padding: 0 10px; height: 34px;
            font-size: 13px; color: #262626; outline: none; background: #fff; width: 100%;
        }
        .ncc-textarea { height: auto; padding: 8px 10px; resize: vertical; font-family: inherit; }
        .ncc-input:focus, .ncc-textarea:focus { border-color: #86b7fe; box-shadow: 0 0 0 .2rem rgba(13, 110, 253, .2); }
        .ncc-hint { margin: 0; font-size: 12px; color: #8c8c8c; }
        .ncc-req { color: #ff4d4f; }

        .ncc-anh-khung {
            width: 100%; aspect-ratio: 1 / 1; border: 1px dashed #d9d9d9; border-radius: 8px; background: #fafafa;
            display: flex; align-items: center; justify-content: center; overflow: hidden;
        }
        .ncc-anh-khung img { width: 100%; height: 100%; object-fit: cover; }
        .ncc-anh-khung.is-view { max-width: 180px; }
        .ncc-anh-chu { font-size: 12px; color: #bfbfbf; }
        .ncc-anh-nut {
            height: 32px; display: inline-flex; align-items: center; justify-content: center; border: 1px solid #d9d9d9;
            border-radius: 4px; background: #fff; font-size: 13px; color: #262626; cursor: pointer;
        }
        .ncc-anh-nut:hover { border-color: #1890ff; color: #1890ff; }
        .ncc-anh-go { border: 0; background: none; font-size: 12px; color: #ff4d4f; cursor: pointer; padding: 0; }
        .ncc-status-box { margin-top: 4px; display: flex; flex-direction: column; gap: 4px; }
        .ncc-switch-row { display: flex; align-items: center; gap: 8px; margin: 0; }
        .ncc-switch-label { font-size: 13px; }

        /* Modal chi tiết */
        .ncc-tab {
            border: 0; background: none; padding: 8px 14px; font-size: 13px; color: #595959; cursor: pointer;
            border-bottom: 2px solid transparent;
        }
        .ncc-tab:hover { color: #1890ff; }
        .ncc-tab.is-active { color: #1890ff; font-weight: 600; border-bottom-color: #1890ff; }
        .ncc-tab-pane { display: none; }
        .ncc-tab-pane.is-active { display: block; }
        .ncc-view-cols { display: grid; grid-template-columns: 200px 1fr; gap: 20px; }
        @media (max-width: 720px) { .ncc-view-cols { grid-template-columns: 1fr; } }
        .ncc-view-anh { display: flex; flex-direction: column; align-items: center; gap: 8px; }
        .ncc-view-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 12px 16px; }
        @media (max-width: 720px) { .ncc-view-grid { grid-template-columns: 1fr; } }
        .ncc-cell { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
        .ncc-cell.is-full { grid-column: 1 / -1; }
        .ncc-lb { font-size: 12px; color: #8c8c8c; }
        .ncc-vl { font-size: 13px; color: #262626; word-break: break-word; }
    </style>

    <script>
        (function () {
            const CSRF = '{{ csrf_token() }}';
            const RETURN_URL = @json(request()->getRequestUri());
            const URL_BASE = @json(url('/admin/suppliers'));
            const URL_STORE = @json(route('admin.nha-cung-cap.store'));
            const URL_BULK_STATUS = @json(route('admin.nha-cung-cap.bulkStatus'));
            const URL_BULK_DEL = @json(route('admin.nha-cung-cap.bulkDestroy'));
            const URL_ANH = @json(route('admin.nha-cung-cap.anh'));

            const ROWS = @json($list);
            const BY_ID = new Map(ROWS.map((r) => [Number(r.id), r]));

            const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => (
                { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]
            ));
            const money = (v) => {
                const n = Number(v) || 0;
                return n ? n.toLocaleString('vi-VN') + '₫' : '—';
            };
            const ngay = (s) => {
                if (!s) return '—';
                const d = new Date(String(s).replace(' ', 'T'));
                return isNaN(d) ? String(s) : d.toLocaleDateString('vi-VN');
            };

            // ---------- Bộ lọc ----------
            const $filter = document.getElementById('nccFilter');

            (function () {
                const btn = document.getElementById('nccAdvToggle');
                const row = document.getElementById('nccAdvRow');
                const setOpen = (open) => {
                    row.classList.toggle('is-open', open);
                    btn.classList.toggle('is-open', open);
                    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
                };
                if (!row.classList.contains('is-open') && localStorage.getItem('ncc-adv-open') === '1') setOpen(true);
                btn.addEventListener('click', () => {
                    const open = !row.classList.contains('is-open');
                    setOpen(open);
                    localStorage.setItem('ncc-adv-open', open ? '1' : '0');
                });
            })();

            $filter.querySelectorAll('select').forEach((sel) => sel.addEventListener('change', () => $filter.submit()));
            let searchTimer = null;
            $filter.querySelector('input[name="keyword"]').addEventListener('input', () => {
                clearTimeout(searchTimer);
                searchTimer = setTimeout(() => $filter.submit(), 400);
            });

            // ---------- Form POST động cho mọi thao tác ghi ----------
            function postForm(action, method, fields) {
                const f = document.createElement('form');
                f.method = 'POST';
                f.action = action;
                f.style.display = 'none';
                const add = (name, val) => {
                    const i = document.createElement('input');
                    i.type = 'hidden';
                    i.name = name;
                    i.value = val == null ? '' : val;
                    f.appendChild(i);
                };
                add('_token', CSRF);
                if (method && method !== 'POST') add('_method', method);
                add('return', RETURN_URL);
                for (const [k, v] of Object.entries(fields)) add(k, v);
                document.body.appendChild(f);
                f.submit();
            }

            function toastErr(msg) {
                const cont = document.querySelector('.toast-container');
                if (!cont || typeof bootstrap === 'undefined') { alert(msg); return; }
                const el = document.createElement('div');
                el.className = 'toast align-items-center text-bg-danger border-0';
                el.setAttribute('role', 'alert');
                el.innerHTML = '<div class="d-flex"><div class="toast-body">' + esc(msg) + '</div>'
                    + '<button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>';
                cont.appendChild(el);
                const t = new bootstrap.Toast(el, { delay: 6000 });
                el.addEventListener('hidden.bs.toast', () => el.remove());
                t.show();
            }

            // ---------- Modal thêm / sửa ----------
            const $form = document.getElementById('nccForm');
            const $formOverlay = document.getElementById('nccFormOverlay');
            const O = {
                code: document.getElementById('nccMa'),
                name: document.getElementById('nccTen'),
                short_name: document.getElementById('nccTenTat'),
                phone: document.getElementById('nccDienThoai'),
                email: document.getElementById('nccEmail'),
                tax_code: document.getElementById('nccMST'),
                representative_name: document.getElementById('nccDaiDien'),
                representative_phone: document.getElementById('nccDaiDienSDT'),
                address: document.getElementById('nccDiaChi'),
                address_line2: document.getElementById('nccDiaChi2'),
                note: document.getElementById('nccGhiChu'),
            };
            const $anh = document.getElementById('nccAnh');
            const $anhXem = document.getElementById('nccAnhXem');
            const $anhChu = document.getElementById('nccAnhChu');
            const $anhGo = document.getElementById('nccAnhGo');
            const $tt = document.getElementById('nccTrangThai');
            const $ttChu = document.getElementById('nccTrangThaiChu');
            const $ttValue = document.getElementById('nccTrangThaiValue');

            function veAnh(url) {
                $anh.value = url || '';
                if (url) {
                    $anhXem.src = url;
                    $anhXem.style.display = '';
                    $anhChu.style.display = 'none';
                    $anhGo.style.display = '';
                } else {
                    $anhXem.removeAttribute('src');
                    $anhXem.style.display = 'none';
                    $anhChu.style.display = '';
                    $anhGo.style.display = 'none';
                }
            }

            /** Giá trị gửi đi nằm ở ô ẩn, nút chỉ là mặt ngoài. */
            function veTrangThai(bat) {
                if (bat !== undefined) $ttValue.value = bat ? '1' : '0';
                const on = $ttValue.value === '1';
                $tt.classList.toggle('on', on);
                $tt.setAttribute('aria-pressed', on ? 'true' : 'false');
                $ttChu.textContent = on ? 'Đang hợp tác' : 'Ngừng hợp tác';
            }
            $tt.addEventListener('click', () => veTrangThai($ttValue.value !== '1'));

            // Ảnh tải lên ngay lúc chọn, form chỉ mang theo đường dẫn.
            document.getElementById('nccAnhFile').addEventListener('change', async (e) => {
                const file = e.target.files[0];
                if (!file) return;
                const fd = new FormData();
                fd.append('anh', file);
                fd.append('_token', CSRF);
                try {
                    const res = await fetch(URL_ANH, { method: 'POST', body: fd });
                    const data = await res.json();
                    if (!res.ok || !data.url) throw new Error(data.message || 'Tải ảnh không thành công.');
                    veAnh(data.url);
                } catch (err) {
                    toastErr(err.message || 'Tải ảnh không thành công.');
                }
                e.target.value = '';
            });
            $anhGo.addEventListener('click', () => veAnh(''));

            const closeAll = () => {
                $formOverlay.style.display = 'none';
                document.getElementById('nccDetailOverlay').style.display = 'none';
                document.getElementById('nccImportOverlay').style.display = 'none';
            };
            document.querySelectorAll('[data-close]').forEach((b) => b.addEventListener('click', closeAll));
            document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeAll(); });

            /** mode: 'add' | 'edit' | 'copy' — copy chép mọi ô trừ mã. */
            function openForm(mode, s) {
                const sua = mode === 'edit';
                $form.action = sua ? `${URL_BASE}/${s.id}` : URL_STORE;
                document.getElementById('nccFormMethod').value = sua ? 'PUT' : 'POST';
                document.getElementById('nccFormTitle').textContent =
                    sua ? 'Sửa nhà cung cấp' : (mode === 'copy' ? 'Nhân bản nhà cung cấp' : 'Thêm nhà cung cấp');
                document.getElementById('nccFormSubmit').textContent = sua ? 'Cập nhật' : 'Lưu';

                const d = s || {};
                document.getElementById('nccId').value = sua ? (d.id || '') : '';
                for (const [key, el] of Object.entries(O)) el.value = d[key] || '';
                if (mode === 'copy') O.code.value = '';
                veAnh(d.image || '');
                veTrangThai(s ? Number(d.status) === 1 : true);

                $formOverlay.style.display = 'flex';
                setTimeout(() => O.name.focus(), 30);
            }

            document.getElementById('nccAddBtn').addEventListener('click', () => openForm('add', null));

            // ---------- Modal chi tiết ----------
            const $detail = document.getElementById('nccDetailOverlay');
            let dangXem = null;

            function o(nhan, giaTri, full) {
                return `<div class="ncc-cell${full ? ' is-full' : ''}"><span class="ncc-lb">${esc(nhan)}</span>`
                    + `<span class="ncc-vl">${esc(giaTri || '—')}</span></div>`;
            }

            function veChiTiet(s) {
                dangXem = s;
                document.getElementById('nccDetailTitle').textContent = s.name || 'Chi tiết nhà cung cấp';

                const anh = document.getElementById('nccViewAnh');
                const anhChu = document.getElementById('nccViewAnhChu');
                if (s.image) {
                    anh.src = s.image; anh.style.display = ''; anhChu.style.display = 'none';
                } else {
                    anh.removeAttribute('src'); anh.style.display = 'none'; anhChu.style.display = '';
                }

                const bat = Number(s.status) === 1;
                document.getElementById('nccViewTrangThai').classList.toggle('on', bat);
                document.getElementById('nccViewTrangThaiChu').textContent =
                    bat ? 'Đang hợp tác' : 'Ngừng hợp tác';

                document.getElementById('nccViewGrid').innerHTML = [
                    o('Mã nhà cung cấp', s.code),
                    o('Tên nhà cung cấp', s.name),
                    o('Tên viết tắt', s.short_name),
                    o('Mã số thuế', s.tax_code),
                    o('Điện thoại', s.phone),
                    o('Email', s.email),
                    o('Người đại diện', s.representative_name),
                    o('SĐT người đại diện', s.representative_phone),
                    o('Ngày tạo', ngay(s.created_at)),
                    o('Địa chỉ', s.address, true),
                    o('Địa chỉ 2', s.address_line2, true),
                    o('Ghi chú', s.note, true),
                ].join('');

                $detail.style.display = 'flex';
            }

            document.getElementById('nccDetailEdit').addEventListener('click', () => {
                if (!dangXem) return;
                $detail.style.display = 'none';
                openForm('edit', dangXem);
            });

            // ---------- Sự kiện bảng ----------
            const tbody = document.querySelector('.ncc-table tbody');
            tbody.addEventListener('click', (e) => {
                if (e.target.closest('a') || e.target.closest('input')) return;

                const tg = e.target.closest('[data-toggle]');
                if (tg) {
                    postForm(`${URL_BASE}/${tg.dataset.toggle}/status`, 'PUT', { status: tg.dataset.on === '1' ? 0 : 1 });
                    return;
                }
                const rm = e.target.closest('[data-remove]');
                if (rm) { xoa(BY_ID.get(Number(rm.dataset.remove))); return; }
                const cp = e.target.closest('[data-copy]');
                if (cp) { openForm('copy', BY_ID.get(Number(cp.dataset.copy))); return; }
                const ed = e.target.closest('[data-edit]');
                if (ed) { openForm('edit', BY_ID.get(Number(ed.dataset.edit))); return; }
                const dt = e.target.closest('[data-detail]');
                if (dt) { veChiTiet(BY_ID.get(Number(dt.dataset.detail))); }
            });

            function xoa(s) {
                if (!s) return;
                window.sysDelete({
                    title: 'Xác nhận xoá nhà cung cấp',
                    message: `Xoá nhà cung cấp "${s.name}"? Bên nào còn phiếu mua thì hệ thống sẽ từ chối. `
                        + 'Muốn dừng nhập hàng mà vẫn giữ chứng từ cũ thì chuyển sang "Ngừng hợp tác".',
                    highlightText: `${s.code || ''} — ${s.name || ''}`,
                }).then((ok) => { if (ok) postForm(`${URL_BASE}/${s.id}`, 'DELETE', {}); });
            }

            // ---------- Chọn dòng + thanh hàng loạt ----------
            const chon = new Set();
            const $bulk = document.getElementById('nccBulkMount');
            const checkAll = document.getElementById('nccCheckAll');
            const rowChecks = () => Array.from(document.querySelectorAll('.ncc-row-check'));

            function syncRow(cb) {
                const tr = cb.closest('tr');
                if (cb.checked) { chon.add(Number(cb.value)); tr.classList.add('is-selected'); }
                else { chon.delete(Number(cb.value)); tr.classList.remove('is-selected'); }
            }
            function syncHeader() {
                const all = rowChecks();
                const on = all.filter((c) => c.checked).length;
                checkAll.checked = on > 0 && on === all.length;
                checkAll.indeterminate = on > 0 && on < all.length;
            }
            function veBulk() {
                const n = chon.size;
                if (!n) { $bulk.innerHTML = ''; return; }
                const dangBat = [...chon].filter((id) => Number(BY_ID.get(id)?.status) === 1).length;

                $bulk.innerHTML = `
                    <div class="ncc-bulk">
                        <span class="ncc-bulk-count">Đã chọn <b>${n}</b> nhà cung cấp</span>
                        <button type="button" class="ncc-bulk-clear" id="nccBulkClear">Bỏ chọn</button>
                        <div class="ncc-bulk-actions">
                            ${dangBat > 0
                                ? `<button type="button" class="ncc-btn-ghost" data-bulk="off">Ngừng hợp tác (${dangBat})</button>`
                                : `<button type="button" class="ncc-btn-ghost" data-bulk="on">Bật lại hợp tác (${n})</button>`}
                            <button type="button" class="ncc-btn-danger" data-bulk="del">Xoá (${n})</button>
                        </div>
                    </div>`;
            }

            rowChecks().forEach((cb) => cb.addEventListener('change', () => { syncRow(cb); syncHeader(); veBulk(); }));
            if (checkAll) {
                checkAll.addEventListener('change', () => {
                    rowChecks().forEach((cb) => { cb.checked = checkAll.checked; syncRow(cb); });
                    syncHeader(); veBulk();
                });
            }

            function idFields(ids) {
                const out = {};
                ids.forEach((id, i) => { out[`ids[${i}]`] = id; });
                return out;
            }

            $bulk.addEventListener('click', (e) => {
                if (e.target.closest('#nccBulkClear')) {
                    chon.clear();
                    rowChecks().forEach((cb) => { cb.checked = false; cb.closest('tr').classList.remove('is-selected'); });
                    syncHeader(); veBulk();
                    return;
                }

                const nut = e.target.closest('[data-bulk]');
                if (!nut) return;
                const ids = [...chon];

                if (nut.dataset.bulk === 'del') {
                    window.sysDelete({
                        title: 'Xác nhận xoá hàng loạt',
                        message: `Xoá ${ids.length} nhà cung cấp đã chọn? Bên nào còn phiếu mua sẽ được giữ lại.`,
                        highlightText: `Số lượng: ${ids.length} nhà cung cấp`,
                    }).then((ok) => { if (ok) postForm(URL_BULK_DEL, 'POST', idFields(ids)); });
                    return;
                }

                const bat = nut.dataset.bulk === 'on';
                window.sysConfirm({
                    title: bat ? 'Bật lại hợp tác' : 'Ngừng hợp tác',
                    message: bat
                        ? `Bật lại hợp tác với ${ids.length} nhà cung cấp đã chọn?`
                        : `Chuyển ${ids.length} nhà cung cấp đã chọn sang "Ngừng hợp tác"? Chứng từ cũ giữ nguyên.`,
                    confirmText: bat ? 'Bật lại' : 'Ngừng hợp tác',
                }).then((ok) => {
                    if (ok) postForm(URL_BULK_STATUS, 'POST', { status: bat ? 1 : 0, ...idFields(ids) });
                });
            });

            // ---------- Hai dropdown: Tiện ích và Xem cột ----------
            const dropdowns = [
                { box: document.getElementById('nccUtil'), btn: document.getElementById('nccUtilBtn') },
                { box: document.getElementById('nccCotBox'), btn: document.getElementById('nccCotBtn') },
            ];
            const dongDropdown = () => dropdowns.forEach((d) => {
                d.box.classList.remove('open');
                d.btn.setAttribute('aria-expanded', 'false');
            });
            dropdowns.forEach((d) => {
                d.btn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    const open = !d.box.classList.contains('open');
                    dongDropdown();
                    d.box.classList.toggle('open', open);
                    d.btn.setAttribute('aria-expanded', open ? 'true' : 'false');
                });
                // Bấm ô tick trong menu thì đừng đóng menu.
                d.box.querySelector('.ncc-util-menu').addEventListener('click', (e) => e.stopPropagation());
            });
            document.addEventListener('click', dongDropdown);

            // ---------- Xem cột ----------
            const COT_KEY = 'ncc-cot-an';
            const $cotCss = document.getElementById('nccCotCss');
            const $cotCbs = Array.from(document.querySelectorAll('.ncc-cot-cb'));
            const $cotAll = document.getElementById('nccCotAll');

            function veCot() {
                const an = $cotCbs.filter((cb) => !cb.checked).map((cb) => cb.dataset.cot);
                $cotCss.textContent = an.length
                    ? an.map((c) => `.ncc-table .ncc-c-${c}`).join(',') + '{display:none}'
                    : '';
                $cotAll.checked = an.length === 0;
                $cotAll.indeterminate = an.length > 0 && an.length < $cotCbs.length;
                localStorage.setItem(COT_KEY, JSON.stringify(an));
            }

            try {
                const an = JSON.parse(localStorage.getItem(COT_KEY) || '[]');
                $cotCbs.forEach((cb) => { cb.checked = !an.includes(cb.dataset.cot); });
            } catch (e) { /* dữ liệu cũ hỏng thì cứ hiện đủ cột */ }
            veCot();

            $cotCbs.forEach((cb) => cb.addEventListener('change', () => {
                // Giữ lại ít nhất một cột.
                if ($cotCbs.every((c) => !c.checked)) { cb.checked = true; return; }
                veCot();
            }));
            $cotAll.addEventListener('change', () => {
                $cotCbs.forEach((cb) => { cb.checked = $cotAll.checked; });
                if (!$cotAll.checked) $cotCbs[0].checked = true;
                veCot();
            });

            // ---------- Nhập file ----------
            const $import = document.getElementById('nccImportOverlay');
            document.getElementById('nccImportBtn').addEventListener('click', () => {
                dongDropdown();
                $import.style.display = 'flex';
            });

            // Lưu hỏng thì mở lại hộp thoại kèm dữ liệu vừa gõ.
            @if(old('name'))
                (function () {
                    const cu = @json(old());
                    openForm(cu.id ? 'edit' : 'add', cu.id ? cu : { ...cu, id: null });
                })();
            @endif
        })();
    </script>
@endsection
