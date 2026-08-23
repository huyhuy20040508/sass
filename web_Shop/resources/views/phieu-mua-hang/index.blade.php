@extends('layouts.app')

@section('title', \App\Http\Controllers\PhieuMuaHangController::TITLE_PAGE)

@section('content')
    {{-- Tên ô của form = tên trường bên API nên controller gửi thẳng payload đi. --}}
    @php
        $C = \App\Http\Controllers\PhieuMuaHangController::class;
        $TITLE = $C::TITLE_PAGE;
        $EMPTY_TEXT = $C::EMPTY_TEXT;

        $so = fn ($n) => number_format((float) $n, 0, ',', '.');
        $tien = fn ($n) => ((float) $n) != 0.0 ? number_format((float) $n, 0, ',', '.').'₫' : '—';
        $ngay = function ($v) {
            $t = $v ? strtotime($v) : false;
            return $t ? date('d/m/Y', $t) : '—';
        };

        $trangThaiChon = array_filter(explode(',', $filters['status']));
        $traChon = array_filter(explode(',', $filters['payment_status']));

        // Lọc phụ nằm trong "Nâng cao"; đang bật thì hàng đó tự mở kèm con số.
        $advCount = count($trangThaiChon) + count($traChon)
            + ($filters['from_date'] !== '' || $filters['to_date'] !== '' ? 1 : 0)
            + ($filters['sort'] !== 'newest' ? 1 : 0);
        $advOpen = $advCount > 0;
        $hasFilter = $advCount > 0 || $filters['keyword'] !== '' || $filters['supplier_id'] > 0;

        $stt = ($meta['page'] - 1) * $meta['page_size'];
    @endphp

    <div class="pmh">
        <div class="pmh-head">
            <h1 class="pmh-title">{{ $TITLE }}</h1>
            <span class="pmh-sum">
                Lưu tạm: <b>{{ $so($thongKe['draft']) }}</b> ·
                Đã duyệt: <b>{{ $so($thongKe['approved']) }}</b> ·
                Đã mua: <b>{{ $tien($thongKe['purchased_amount']) }}</b> ·
                Còn nợ NCC: <b class="pmh-no">{{ $tien($thongKe['debt_amount']) }}</b>
                <em>(chưa tính phiếu lưu tạm và phiếu đã huỷ)</em>
            </span>
        </div>

        @if(!empty($error))
            <p class="pmh-callout is-error">{{ $error }}</p>
        @endif

        {{-- Lọc realtime: đổi ô tick hay select chạy ngay, gõ thì chờ 400ms. --}}
        <form method="GET" action="{{ route('admin.phieu-mua-hang.index') }}" id="pmhFilter" class="pmh-filter">
            <div class="pmh-toolbar">
                <div class="pmh-searchbox">
                    <input type="text" name="keyword" value="{{ $filters['keyword'] }}" class="pmh-search-input"
                           placeholder="Tìm theo mã phiếu, nhà cung cấp hoặc ghi chú" autocomplete="off">
                    <button type="submit" class="pmh-search-btn" title="Tìm kiếm">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                    </button>
                </div>

                <select name="supplier_id" class="pmh-select" title="Lọc theo nhà cung cấp">
                    <option value="">Mọi nhà cung cấp</option>
                    @foreach($nhaCungCap as $ncc)
                        <option value="{{ $ncc['id'] }}" @selected($filters['supplier_id'] === (int) $ncc['id'])>
                            {{ $ncc['name'] ?? '' }}
                        </option>
                    @endforeach
                </select>

                <button type="button" id="pmhAdvToggle" class="pmh-adv-btn {{ $advOpen ? 'is-open' : '' }}"
                        aria-expanded="{{ $advOpen ? 'true' : 'false' }}">
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M7 12h10M11 18h2"/></svg>
                    Nâng cao
                    <svg class="pmh-adv-caret" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                    @if($advCount > 0)<span class="pmh-adv-count">{{ $advCount }}</span>@endif
                </button>

                @if($hasFilter)
                    <a href="{{ route('admin.phieu-mua-hang.index') }}" class="pmh-clear">Xoá lọc</a>
                @endif

                <div class="pmh-toolbar-actions">
                    <button type="button" class="pmh-btn-primary" id="pmhAddBtn">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M12 5v14"/></svg>
                        Lập phiếu mua
                    </button>

                    <div class="pmh-util" id="pmhUtil">
                        <button type="button" class="pmh-util-btn" id="pmhUtilBtn" aria-haspopup="true" aria-expanded="false">
                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="1.6"/><circle cx="19" cy="12" r="1.6"/><circle cx="5" cy="12" r="1.6"/></svg>
                            Tiện ích
                            <svg class="pmh-util-caret" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                        </button>
                        <div class="pmh-util-menu">
                            {{-- Xuất mang theo đúng bộ lọc đang xem. --}}
                            <a href="{{ route('admin.phieu-mua-hang.export', request()->query()) }}" class="pmh-util-item">
                                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5"/><path d="M12 15V3"/></svg>
                                Xuất file (CSV)
                            </a>
                            <button type="button" class="pmh-util-item" id="pmhPrintBtn">
                                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><path d="M6 14h12v8H6z"/></svg>
                                In danh sách
                            </button>
                        </div>
                    </div>

                    {{-- Xem cột — lựa chọn lưu ở localStorage. --}}
                    <div class="pmh-util" id="pmhCotBox">
                        <button type="button" class="pmh-util-btn pmh-btn-sq" id="pmhCotBtn" title="Xem cột" aria-expanded="false">
                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 21v-7M4 10V3M12 21v-9M12 8V3M20 21v-5M20 12V3"/><path d="M1 14h6M9 8h6M17 16h6"/></svg>
                        </button>
                        <div class="pmh-util-menu pmh-cot-menu">
                            <label class="pmh-cot-item is-all">
                                <input type="checkbox" id="pmhCotAll" checked>
                                <span>Tất cả</span>
                            </label>
                            <div class="pmh-cot-line"></div>
                            @foreach($C::COT_BANG as $ma => $ten)
                                <label class="pmh-cot-item">
                                    <input type="checkbox" class="pmh-cot-cb" data-cot="{{ $ma }}" checked>
                                    <span>{{ $ten }}</span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>

            <div class="pmh-toolbar-adv {{ $advOpen ? 'is-open' : '' }}" id="pmhAdvRow">
                <div class="pmh-tickbox">
                    <span class="pmh-tick-label">Trạng thái</span>
                    @foreach($C::TRANG_THAI as $ma => $ten)
                        <label class="pmh-tick">
                            <input type="checkbox" name="status[]" value="{{ $ma }}"
                                   @checked(in_array($ma, $trangThaiChon, true))>
                            <span class="pmh-chip is-{{ $C::MAU_TRANG_THAI[$ma] }}">{{ $ten }}</span>
                        </label>
                    @endforeach
                </div>

                <div class="pmh-tickbox">
                    <span class="pmh-tick-label">Thanh toán</span>
                    @foreach($C::TRANG_THAI_TRA as $ma => $ten)
                        <label class="pmh-tick">
                            <input type="checkbox" name="payment_status[]" value="{{ $ma }}"
                                   @checked(in_array($ma, $traChon, true))>
                            <span class="pmh-chip is-{{ $C::MAU_TRANG_THAI_TRA[$ma] }}">{{ $ten }}</span>
                        </label>
                    @endforeach
                </div>

                <div class="pmh-tickbox">
                    <span class="pmh-tick-label">Ngày lập</span>
                    <input type="date" name="from_date" value="{{ $filters['from_date'] }}" class="pmh-select" title="Từ ngày">
                    <span class="pmh-dash">→</span>
                    <input type="date" name="to_date" value="{{ $filters['to_date'] }}" class="pmh-select" title="Đến ngày">
                </div>

                <select name="sort" class="pmh-select" title="Sắp xếp">
                    @foreach($C::SAP_XEP as $ma => $ten)
                        <option value="{{ $ma }}" @selected($filters['sort'] === $ma)>{{ $ten }}</option>
                    @endforeach
                </select>
            </div>

            <input type="hidden" name="page_size" value="{{ $meta['page_size'] }}">
        </form>

        {{-- Bề rộng cột khai theo %, cộng đúng 100%. --}}
        <div class="pmh-table-wrap">
            <table class="pmh-table">
                <thead>
                    <tr>
                        <th class="pmh-c-check"><input type="checkbox" id="pmhCheckAll" class="pmh-check" title="Chọn hết dòng đang hiện"></th>
                        <th class="pmh-c-stt">STT</th>
                        <th class="pmh-c-code">Mã phiếu</th>
                        <th class="pmh-c-supplier">Nhà cung cấp</th>
                        <th class="pmh-c-docdate">Ngày chứng từ</th>
                        <th class="pmh-c-items">Tiền hàng</th>
                        <th class="pmh-c-total">Tổng tiền</th>
                        <th class="pmh-c-debt">Còn nợ</th>
                        <th class="pmh-c-status">Trạng thái</th>
                        <th class="pmh-c-pay">Thanh toán</th>
                        <th class="pmh-c-note">Ghi chú</th>
                        <th class="pmh-c-act">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($list as $i => $p)
                        @php
                            $id = (int) ($p['id'] ?? 0);
                            $tt = $p['status'] ?? 'draft';
                            $ttTra = $p['payment_status'] ?? 'unpaid';
                            $tong = (float) ($p['total_amount'] ?? 0);
                            $daTra = (float) ($p['paid_amount'] ?? 0);
                            $conNo = max(0, $tong - $daTra);
                            $nhap = $tt === 'draft';
                        @endphp
                        <tr data-id="{{ $id }}" data-status="{{ $tt }}">
                            <td class="pmh-c-check">
                                <input type="checkbox" class="pmh-check pmh-row-check" value="{{ $id }}"
                                       aria-label="Chọn phiếu {{ $p['po_code'] ?? $id }}">
                            </td>
                            <td class="pmh-c-stt">{{ $stt + $i + 1 }}</td>
                            <td class="pmh-c-code" data-detail="{{ $id }}" title="Bấm để xem chi tiết">
                                <span class="pmh-code">{{ ($p['po_code'] ?? '') ?: '—' }}</span>
                            </td>
                            <td class="pmh-c-supplier" data-detail="{{ $id }}" title="{{ $p['supplier_name'] ?? '' }}">
                                <span class="pmh-name">{{ ($p['supplier_name'] ?? '') ?: 'Bên bán vãng lai' }}</span>
                            </td>
                            <td class="pmh-c-docdate">{{ $ngay($p['document_date'] ?? null) }}</td>
                            <td class="pmh-c-items">{{ $tien($p['items_amount'] ?? 0) }}</td>
                            <td class="pmh-c-total"><b>{{ $tien($tong) }}</b></td>
                            <td class="pmh-c-debt">
                                @if($conNo > 0 && $tt !== 'cancelled')
                                    <span class="pmh-no">{{ $tien($conNo) }}</span>
                                @else
                                    <span class="pmh-muted">—</span>
                                @endif
                            </td>
                            <td class="pmh-c-status">
                                <span class="pmh-chip is-{{ $C::MAU_TRANG_THAI[$tt] ?? 'off' }}">
                                    {{ $C::TRANG_THAI[$tt] ?? $tt }}
                                </span>
                            </td>
                            <td class="pmh-c-pay">
                                @if($tt === 'cancelled')
                                    <span class="pmh-muted">—</span>
                                @else
                                    <span class="pmh-chip is-{{ $C::MAU_TRANG_THAI_TRA[$ttTra] ?? 'off' }}">
                                        {{ $C::TRANG_THAI_TRA[$ttTra] ?? $ttTra }}
                                    </span>
                                @endif
                            </td>
                            <td class="pmh-c-note" title="{{ $p['note'] ?? '' }}">{{ ($p['note'] ?? '') ?: '—' }}</td>
                            <td class="pmh-c-act">
                                <div class="pmh-rowacts">
                                    <button type="button" class="pmh-rowbtn" data-detail="{{ $id }}" title="Xem chi tiết">
                                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                                    </button>
                                    @if($nhap)
                                        <button type="button" class="pmh-rowbtn" data-edit="{{ $id }}" title="Sửa phiếu">
                                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                                        </button>
                                        <button type="button" class="pmh-rowbtn is-ok" data-approve="{{ $id }}" title="Duyệt và nhập kho">
                                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                                        </button>
                                        <button type="button" class="pmh-rowbtn is-danger" data-remove="{{ $id }}" title="Xoá phiếu">
                                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/></svg>
                                        </button>
                                    @elseif($tt === 'approved' && $conNo > 0)
                                        <button type="button" class="pmh-rowbtn" data-pay="{{ $id }}" title="Ghi nhận thanh toán">
                                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></svg>
                                        </button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="12" class="pmh-empty">
                                @if($hasFilter)
                                    Không tìm thấy phiếu nào khớp bộ lọc. Thử xoá bớt điều kiện lọc.
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
            'noun' => 'phiếu',
            'perPageName' => 'page_size',
            'perPageOptions' => $C::MUC_SO_DONG,
        ])
    </div>

    <div id="pmhBulkMount"></div>

    {{-- ------------------------------------------------------------------
         Hộp thoại Lập / Sửa phiếu — dựng theo đúng màn của bản order v2.

         Ba khối xếp dọc, đúng thứ tự bên đó:

           1. Hàng nút Lưu tạm / Duyệt nằm TRÊN CÙNG, canh phải.
           2. Thông tin phiếu: 4 cột × 4 ô. Cột 1 và 2 gần như là hồ sơ bên
              bán, tự điền theo nhà cung cấp đã chọn nên khoá lại; cột 4 là
              thông tin phiếu do hệ thống đặt, cũng khoá.
           3. Thông tin hàng hoá: lọc nhóm hàng + ô tìm hàng + dropdown Nâng
              cao (xuất / tải mẫu / nhập file), rồi tới lưới hàng.
           4. Khối tiền canh phải: trước thuế · tiền thuế · tổng tiền.

         Chiết khấu và số tiền đã trả KHÔNG nằm ở đây — bên v2 hai ô ấy cũng
         bị gỡ khỏi màn lập phiếu, tiền trả đi qua hộp "Ghi nhận thanh toán"
         riêng.
    ------------------------------------------------------------------- --}}
    <div class="pmh-overlay" id="pmhFormOverlay" style="display:none;">
        <div class="pmh-dialog pmh-dialog-xl">
            <div class="pmh-modal-head">
                <h4 class="pmh-modal-title" id="pmhFormTitle">Lập phiếu mua hàng</h4>
                <button type="button" class="pmh-modal-x" data-close>
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </div>

            <form id="pmhForm" method="POST" action="{{ route('admin.phieu-mua-hang.store') }}">
                @csrf
                <input type="hidden" name="_method" id="pmhFormMethod" value="POST">
                <input type="hidden" name="return" value="{{ request()->getRequestUri() }}">
                {{-- Nút nào bấm thì ô này nói ra: 1 = duyệt luôn sau khi lưu. --}}
                <input type="hidden" name="duyet" id="pmhDuyet" value="0">
                {{-- Lưu hỏng thì lượt mở lại đọc ô này để biết đang sửa phiếu nào. --}}
                <input type="hidden" name="id" id="pmhId" value="">
                <input type="hidden" name="items" id="pmhItems" value="">
                {{-- Bản đầy đủ của lưới hàng (kèm tên, đơn vị) chỉ để dựng lại
                     hộp thoại khi lưu hỏng. API không đọc ô này. --}}
                <input type="hidden" name="items_meta" id="pmhItemsMeta" value="">
                <input type="hidden" name="attachment" id="pmhAttachment" value="">
                {{-- Chiết khấu không có ô trên màn (giống v2), nhưng API vẫn
                     nhận — giữ ô ẩn để payload không đổi hình dạng. --}}
                <input type="hidden" name="discount_amount" value="0">
                <input type="hidden" name="paid_amount" value="0">

                {{-- 1. Hàng nút, trên cùng và canh phải --}}
                <div class="pmh-form-bar">
                    <button type="submit" class="pmh-btn-ghost" id="pmhSaveDraft">Lưu tạm</button>
                    <button type="submit" class="pmh-btn-primary" id="pmhSaveApprove">Duyệt</button>
                </div>

                <div class="pmh-modal-body">
                    {{-- 2. Thông tin phiếu: 4 cột × 4 ô --}}
                    <div class="pmh-info">
                        <div class="pmh-info-col">
                            <div class="pmh-field">
                                <label class="pmh-field-label" for="pmhNCC">Nhà cung cấp <span class="pmh-req">*</span></label>
                                <div class="pmh-ncc-row">
                                    <select id="pmhNCC" name="supplier_id" class="pmh-input">
                                        <option value="0">Chọn nhà cung cấp</option>
                                        @foreach($nhaCungCap as $ncc)
                                            <option value="{{ $ncc['id'] }}"
                                                    data-name="{{ $ncc['name'] ?? '' }}"
                                                    data-phone="{{ $ncc['phone'] ?? '' }}"
                                                    data-address="{{ $ncc['address'] ?? '' }}"
                                                    data-address2="{{ $ncc['address_line2'] ?? '' }}"
                                                    data-rep-phone="{{ $ncc['representative_phone'] ?? '' }}">
                                                {{ ($ncc['code'] ?? '') ? $ncc['code'].' - ' : '' }}{{ $ncc['name'] ?? '' }}
                                            </option>
                                        @endforeach
                                    </select>
                                    <button type="button" class="pmh-ncc-add" id="pmhNCCAdd" title="Thêm nhà cung cấp mới">
                                        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M12 5v14"/></svg>
                                    </button>
                                </div>
                                <input type="hidden" name="supplier_name" id="pmhTenNCC" value="">
                            </div>
                            <div class="pmh-field">
                                <label class="pmh-field-label" for="pmhDiaChi">Địa chỉ</label>
                                <input type="text" id="pmhDiaChi" class="pmh-input" disabled>
                            </div>
                            <div class="pmh-field">
                                <label class="pmh-field-label" for="pmhDiaChi2">Địa chỉ 2</label>
                                <input type="text" id="pmhDiaChi2" class="pmh-input" disabled>
                            </div>
                            <div class="pmh-field">
                                <label class="pmh-field-label" for="pmhDienThoai">Điện thoại</label>
                                <input type="text" id="pmhDienThoai" class="pmh-input" disabled>
                            </div>
                        </div>

                        <div class="pmh-info-col">
                            <div class="pmh-field">
                                <label class="pmh-field-label" for="pmhSDTLienHe">SĐT người liên hệ</label>
                                <input type="text" id="pmhSDTLienHe" class="pmh-input" disabled>
                            </div>
                            <div class="pmh-field">
                                <label class="pmh-field-label" for="pmhNgayCT">Ngày chứng từ</label>
                                <input type="date" id="pmhNgayCT" name="document_date" class="pmh-input">
                            </div>
                            <div class="pmh-field">
                                <label class="pmh-field-label" for="pmhNgayHetHan">Ngày hết hạn</label>
                                <input type="date" id="pmhNgayHetHan" name="expected_date" class="pmh-input">
                            </div>
                            <div class="pmh-field">
                                <label class="pmh-field-label" for="pmhGhiChu">Ghi chú</label>
                                <input type="text" id="pmhGhiChu" name="note" class="pmh-input" maxlength="500"
                                       autocomplete="off">
                            </div>
                        </div>

                        <div class="pmh-info-col">
                            <div class="pmh-field">
                                <label class="pmh-field-label" for="pmhNguoiMua">Nhân viên mua hàng</label>
                                <select id="pmhNguoiMua" name="purchaser_id" class="pmh-input">
                                    <option value="0">— Chưa phân công —</option>
                                    @foreach($nhanVien as $nv)
                                        <option value="{{ $nv['id'] }}">
                                            {{ ($nv['code'] ?? '') ? $nv['code'].' - ' : '' }}{{ $nv['full_name'] ?? ($nv['name'] ?? '') }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="pmh-field">
                                <label class="pmh-field-label" for="pmhSoGiao">Số phiếu giao của NCC</label>
                                <input type="text" id="pmhSoGiao" name="supplier_delivery_code" class="pmh-input"
                                       maxlength="50" autocomplete="off">
                            </div>
                            <div class="pmh-field" id="pmhVatPhieuO">
                                <label class="pmh-field-label" for="pmhVatPercent">Thuế GTGT</label>
                                {{-- Đơn vị nằm TRONG khung ô, không đứng rời
                                     bên ngoài. Nhãn đổi theo số: -1 và -2 là MÃ
                                     thuế chứ không phải phần trăm. --}}
                                <div class="pmh-donvi">
                                    <input type="number" id="pmhVatPercent" name="vat_percent" class="pmh-input"
                                           min="-2" max="100" step="1" value="0"
                                           title="Thuế suất áp cho cả phiếu. -1 = KCT, -2 = KKKNT">
                                    <span class="pmh-donvi-chu" id="pmhVatSuffix">%</span>
                                </div>
                            </div>
                            <div class="pmh-field">
                                <label class="pmh-field-label" for="pmhVatMode">Cách khai thuế</label>
                                <select id="pmhVatMode" name="vat_mode" class="pmh-input">
                                    @foreach($C::KIEU_VAT as $ma => $ten)
                                        <option value="{{ $ma }}">{{ $ten }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <div class="pmh-info-col">
                            <div class="pmh-field">
                                <label class="pmh-field-label" for="pmhNguoiLap">Người tạo phiếu</label>
                                <input type="text" id="pmhNguoiLap" class="pmh-input" disabled
                                       value="{{ session('api.user.full_name') ?? '' }}">
                            </div>
                            <div class="pmh-field">
                                <label class="pmh-field-label" for="pmhNgayLap">Ngày tạo</label>
                                <input type="text" id="pmhNgayLap" class="pmh-input" disabled>
                            </div>
                            <div class="pmh-field">
                                <label class="pmh-field-label" for="pmhLoaiCT">Loại chứng từ</label>
                                <input type="text" id="pmhLoaiCT" class="pmh-input" disabled value="Phiếu mua hàng">
                            </div>
                            <div class="pmh-field">
                                <label class="pmh-field-label" for="pmhTrangThai">Trạng thái</label>
                                <input type="text" id="pmhTrangThai" class="pmh-input" disabled value="Tạo mới">
                            </div>
                        </div>
                    </div>

                    {{-- 3. Thông tin hàng hoá --}}
                    <div class="pmh-goods">
                        <div class="pmh-goods-head">
                            <h4 class="pmh-goods-title">Thông tin hàng hóa</h4>

                            <div class="pmh-goods-tools">
                                {{-- Chỉ những nhóm ĐANG CÓ hàng mua được; nhóm rỗng
                                     không bày ra vì chọn vào chỉ ra bảng trắng.

                                     Ba trạng thái, ba câu chữ: hỏi được và có
                                     nhóm · hỏi được mà chưa nhóm nào có hàng ·
                                     không hỏi được. Gộp hai cái sau làm một là
                                     người dùng đi tìm lỗi ở nhầm chỗ. --}}
                                @php
                                    $nhomHong = $nhomHang === null;
                                    $nhomRong = is_array($nhomHang) && $nhomHang === [];
                                @endphp
                                <select id="pmhNhomHang" class="pmh-input pmh-input-sm"
                                        title="Lọc theo nhóm hàng" @disabled($nhomHong || $nhomRong)>
                                    <option value="0">
                                        @if($nhomHong)
                                            Không đọc được nhóm hàng
                                        @elseif($nhomRong)
                                            Chưa nhóm nào có hàng
                                        @else
                                            Chọn nhóm hàng
                                        @endif
                                    </option>
                                    @foreach($nhomHang ?? [] as $nh)
                                        <option value="{{ $nh['id'] }}">
                                            {{ $nh['name'] ?? '' }} ({{ $nh['so_mat_hang'] ?? 0 }})
                                        </option>
                                    @endforeach
                                </select>

                                <div class="pmh-pick" id="pmhPick">
                                    <svg class="pmh-pick-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                                    <input type="text" id="pmhPickInput" class="pmh-pick-input" autocomplete="off"
                                           placeholder="Tìm hàng hóa">
                                    <div class="pmh-pick-menu" id="pmhPickMenu" hidden></div>
                                </div>

                                <div class="pmh-util" id="pmhNangCao">
                                    <button type="button" class="pmh-util-btn" id="pmhNangCaoBtn" aria-haspopup="true" aria-expanded="false">
                                        Nâng cao
                                        <svg class="pmh-util-caret" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                                    </button>
                                    <div class="pmh-util-menu">
                                        <button type="button" class="pmh-util-item" id="pmhLineExport">Xuất file</button>
                                        <button type="button" class="pmh-util-item" id="pmhLineSample">Tải file mẫu</button>
                                        <button type="button" class="pmh-util-item" id="pmhLineImport">Nhập file</button>
                                        <input type="file" id="pmhLineFile" accept=".csv,text/csv" hidden>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <p class="pmh-import-error" id="pmhImportError" style="display:none;"></p>

                        <div class="pmh-lines-wrap">
                            <table class="pmh-lines-table" id="pmhLinesTable">
                                <thead>
                                    <tr>
                                        <th class="pmh-l-idx">STT</th>
                                        <th class="pmh-l-code">Mã hàng hóa</th>
                                        <th class="pmh-l-name">Tên hàng hóa</th>
                                        <th class="pmh-l-unit">Đơn vị tính</th>
                                        <th class="pmh-l-qty">Số lượng</th>
                                        <th class="pmh-l-cost">Giá nhập</th>
                                        <th class="pmh-l-sub">Thành tiền trước thuế</th>
                                        <th class="pmh-l-vat">% VAT</th>
                                        <th class="pmh-l-vatmoney">Tiền VAT</th>
                                        <th class="pmh-l-amount">Tổng tiền sau VAT</th>
                                        <th class="pmh-l-lot">Số lô</th>
                                        <th class="pmh-l-exp">Hạn dùng</th>
                                        <th class="pmh-l-x"></th>
                                    </tr>
                                </thead>
                                <tbody id="pmhLines"></tbody>
                            </table>
                            <p class="pmh-lines-empty" id="pmhLinesEmpty">
                                Chưa có dòng hàng nào. Gõ vào ô tìm hàng hóa ở trên để thêm.
                            </p>
                        </div>
                    </div>

                    {{-- 4. Khối tiền, canh phải --}}
                    <div class="pmh-money">
                        <div class="pmh-money-row">
                            <span class="pmh-money-lb">Tổng tiền trước thuế</span>
                            <span class="pmh-money-vl" id="pmhTienHang">0₫</span>
                        </div>
                        <div class="pmh-money-row">
                            <span class="pmh-money-lb">Tổng tiền thuế</span>
                            <span class="pmh-money-vl" id="pmhThue">0₫</span>
                        </div>
                        <div class="pmh-money-row is-total">
                            <span class="pmh-money-lb">Tổng tiền</span>
                            <span class="pmh-money-vl" id="pmhTongCong">0₫</span>
                        </div>
                    </div>

                    {{-- Ảnh chứng từ: v2 để ô đính kèm ở cụm dưới cùng --}}
                    <div class="pmh-attach">
                        <span class="pmh-field-label">Ảnh chứng từ bên bán</span>
                        <label class="pmh-anh-nut">
                            <span id="pmhAnhNutChu">Chọn ảnh</span>
                            <input type="file" id="pmhAnhFile" accept="image/*" hidden>
                        </label>
                        <a id="pmhAnhXem" class="pmh-anh-link" href="#" target="_blank" rel="noopener" style="display:none;">Xem ảnh đã tải</a>
                        <button type="button" class="pmh-anh-go" id="pmhAnhGo" style="display:none;">Gỡ ảnh</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    {{-- Hộp thêm nhà cung cấp — ĐÚNG hộp của trang Nhà cung cấp, không phải
         bản chép lại. Khác đúng một chỗ: ở đây form không quay về danh sách mà
         gọi API rồi nhét bên vừa khai thẳng vào ô chọn của phiếu đang gõ dở. --}}
    @include('partials.modal-nha-cung-cap')

    {{-- Hộp thoại Chi tiết --}}
    <div class="pmh-overlay" id="pmhDetailOverlay" style="display:none;">
        <div class="pmh-dialog pmh-dialog-lg">
            <div class="pmh-modal-head">
                <h4 class="pmh-modal-title" id="pmhDetailTitle">Chi tiết phiếu mua hàng</h4>
                <button type="button" class="pmh-modal-x" data-close>
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </div>

            <div class="pmh-modal-body" id="pmhDetailBody">
                <p class="pmh-lines-empty">Đang đọc phiếu…</p>
            </div>

            <div class="pmh-modal-foot" id="pmhDetailFoot">
                <button type="button" class="pmh-btn-ghost" data-close>Đóng</button>
            </div>
        </div>
    </div>

    {{-- Hộp thoại Ghi nhận thanh toán --}}
    <div class="pmh-overlay" id="pmhPayOverlay" style="display:none;">
        <div class="pmh-dialog pmh-dialog-sm">
            <div class="pmh-modal-head">
                <h4 class="pmh-modal-title">Ghi nhận thanh toán</h4>
                <button type="button" class="pmh-modal-x" data-close>
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </div>

            <form id="pmhPayForm" method="POST" action="">
                @csrf
                <input type="hidden" name="return" value="{{ request()->getRequestUri() }}">

                <div class="pmh-modal-body">
                    <p class="pmh-note-box" id="pmhPayInfo"></p>

                    <div class="pmh-field">
                        <label class="pmh-field-label" for="pmhPayAmount">Tổng đã trả cho phiếu <span class="pmh-req">*</span></label>
                        <div class="pmh-donvi">
                            <input type="text" id="pmhPayAmount" name="paid_amount" class="pmh-input"
                                   inputmode="numeric" required>
                            <span class="pmh-donvi-chu">₫</span>
                        </div>
                        <p class="pmh-hint">Đây là số LUỸ KẾ đã trả, không phải số vừa trả thêm.</p>
                    </div>
                    <div class="pmh-field">
                        <label class="pmh-field-label" for="pmhPayNote">Ghi chú</label>
                        <input type="text" id="pmhPayNote" name="note" class="pmh-input" maxlength="500"
                               autocomplete="off" placeholder="VD: chuyển khoản Vietcombank ngày 22/08">
                    </div>
                </div>

                <div class="pmh-modal-foot">
                    <button type="button" class="pmh-btn-ghost" data-close>Đóng</button>
                    <button type="submit" class="pmh-btn-primary">Lưu</button>
                </div>
            </form>
        </div>
    </div>

    {{-- Hộp thoại Huỷ phiếu --}}
    <div class="pmh-overlay" id="pmhCancelOverlay" style="display:none;">
        <div class="pmh-dialog pmh-dialog-sm">
            <div class="pmh-modal-head">
                <h4 class="pmh-modal-title">Huỷ phiếu mua hàng</h4>
                <button type="button" class="pmh-modal-x" data-close>
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </div>

            <form id="pmhCancelForm" method="POST" action="">
                @csrf
                <input type="hidden" name="return" value="{{ request()->getRequestUri() }}">

                <div class="pmh-modal-body">
                    <p class="pmh-note-box" id="pmhCancelInfo"></p>
                    <div class="pmh-field">
                        <label class="pmh-field-label" for="pmhCancelNote">Lý do huỷ <span class="pmh-req">*</span></label>
                        <input type="text" id="pmhCancelNote" name="note" class="pmh-input" maxlength="500" required
                               autocomplete="off" placeholder="VD: nhà cung cấp báo hết hàng">
                        <p class="pmh-hint">Vài tuần sau không ai nhớ vì sao phiếu chết — lý do nằm lại trong lịch sử phiếu.</p>
                    </div>
                </div>

                <div class="pmh-modal-foot">
                    <button type="button" class="pmh-btn-ghost" data-close>Đóng</button>
                    <button type="submit" class="pmh-btn-danger">Huỷ phiếu</button>
                </div>
            </form>
        </div>
    </div>

    <style id="pmhCotCss"></style>

    <style>
        .pmh {
            /* Phá padding p-4 của <main> để tràn viền như các trang danh sách khác */
            margin: -1.5rem;
            min-height: calc(100vh - 56px);
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            color: #262626; background: #fff;
        }

        /* Header */
        .pmh-head { display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; padding: 14px 20px; }
        .pmh-title { margin: 0; font-size: 16px; font-weight: 700; line-height: 34px; }
        .pmh-sum { font-size: 13px; color: #595959; }
        .pmh-sum b { color: #262626; }
        .pmh-sum em { font-style: normal; color: #bfbfbf; font-size: 12px; }
        .pmh-no { color: #d4380d; }
        .pmh-callout { margin: 0 20px 12px; padding: 10px 12px; border-radius: 6px; font-size: 13px; }
        .pmh-callout.is-error { background: #fff1f0; color: #cf1322; }

        /* Bộ lọc */
        .pmh-filter { padding: 0 20px 12px; margin-bottom: 12px; border-bottom: 1px solid #eee; }
        .pmh-toolbar { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; }
        .pmh-toolbar-actions { margin-left: auto; display: flex; align-items: center; gap: 8px; }
        .pmh-toolbar-adv { display: none; flex-wrap: wrap; align-items: center; gap: 20px; margin-top: 12px; }
        .pmh-toolbar-adv.is-open { display: flex; }
        .pmh-adv-btn {
            height: 34px; display: inline-flex; align-items: center; gap: 6px; border: 1px solid #d9d9d9;
            border-radius: 4px; background: #fff; padding: 0 12px; font-size: 13px; color: #595959; cursor: pointer;
            transition: border-color .15s, color .15s;
        }
        .pmh-adv-btn:hover, .pmh-adv-btn.is-open { border-color: #1890ff; color: #1890ff; }
        .pmh-adv-caret { transition: transform .2s; }
        .pmh-adv-btn.is-open .pmh-adv-caret { transform: rotate(180deg); }
        .pmh-adv-count {
            min-width: 18px; height: 18px; padding: 0 5px; border-radius: 9999px; background: #1890ff; color: #fff;
            font-size: 11px; font-weight: 700; display: inline-flex; align-items: center; justify-content: center;
        }

        .pmh-searchbox { display: flex; border-radius: 4px; }
        .pmh-searchbox:focus-within { box-shadow: 0 0 0 4px rgba(13, 110, 253, .25); }
        .pmh-search-input {
            height: 34px; width: 320px; border: 1px solid #d9d9d9; border-right: 0; border-radius: 4px 0 0 4px;
            background: #fff; padding: 0 12px; font-size: 13px; outline: none;
        }
        .pmh-search-input::placeholder { color: #bfbfbf; }
        .pmh-searchbox:focus-within .pmh-search-input,
        .pmh-searchbox:focus-within .pmh-search-btn { border-color: #86b7fe; }
        .pmh-search-btn {
            height: 34px; width: 40px; display: inline-flex; align-items: center; justify-content: center;
            border: 1px solid #d9d9d9; border-radius: 0 4px 4px 0; background: #fafafa; color: #595959; cursor: pointer;
        }
        .pmh-search-btn:hover { color: #1890ff; }

        /*
           MỌI ô chọn của trang đi qua đúng khuôn dưới đây: bỏ mũi tên mặc định
           của hệ điều hành, vẽ lại bằng cùng một hình chevron với các nút khác.
           Không làm vậy thì ô chọn trên Windows, macOS và Linux ra ba hình mũi
           tên khác nhau, nằm cạnh những nút đã vẽ tay thì lệch hẳn ra.
        */
        select.pmh-select, select.pmh-input, select.pmh-l-input {
            appearance: none; -webkit-appearance: none; cursor: pointer;
            background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23595959' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><path d='m6 9 6 6 6-6'/></svg>");
            background-repeat: no-repeat;
            /* Chừa chỗ cho chevron, không thì tên dài trườn lên trên nó. */
            text-overflow: ellipsis;
        }
        select.pmh-select, select.pmh-input {
            padding-right: 30px; background-position: right 9px center;
        }
        select.pmh-l-input {
            padding-right: 22px; background-position: right 5px center; background-size: 13px 13px;
        }
        select.pmh-select:hover:not(:focus),
        select.pmh-input:hover:not(:focus),
        select.pmh-l-input:hover:not(:focus) { border-color: #b8b8b8; }
        .pmh-select {
            height: 34px; border: 1px solid #d9d9d9; border-radius: 4px; background-color: #fff;
            padding: 0 12px; font-size: 13px; color: #262626; cursor: pointer; outline: none; max-width: 220px;
        }
        .pmh-select:focus { border-color: #86b7fe; box-shadow: 0 0 0 .25rem rgba(13, 110, 253, .25); }
        .pmh-clear {
            display: inline-flex; align-items: center; height: 34px; padding: 0 10px; border-radius: 4px;
            font-size: 13px; color: #8c8c8c; text-decoration: none;
        }
        .pmh-clear:hover { background: #f5f5f5; color: #262626; }

        /* Hàng ô tick trong Nâng cao */
        .pmh-tickbox { display: inline-flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .pmh-tick-label { font-size: 12px; font-weight: 600; color: #8c8c8c; }
        .pmh-tick { display: inline-flex; align-items: center; gap: 5px; margin: 0; cursor: pointer; }
        .pmh-tick input { width: 14px; height: 14px; accent-color: #1890ff; cursor: pointer; }
        .pmh-dash { color: #bfbfbf; font-size: 12px; }

        /* Chip trạng thái — dùng chung cho bộ lọc, bảng và hộp chi tiết */
        .pmh-chip {
            display: inline-flex; align-items: center; height: 22px; padding: 0 10px; border-radius: 9999px;
            font-size: 12px; font-weight: 600; white-space: nowrap;
        }
        .pmh-chip.is-ok { background: #e6f7ff; color: #096dd9; }
        .pmh-chip.is-warn { background: #fff7e6; color: #d46b08; }
        .pmh-chip.is-off { background: #f5f5f5; color: #8c8c8c; }

        /* Nút chung */
        .pmh-btn-primary, .pmh-btn-ghost, .pmh-btn-danger {
            height: 34px; display: inline-flex; align-items: center; justify-content: center; gap: 6px;
            padding: 0 14px; border-radius: 4px; font-size: 13px; font-weight: 500; cursor: pointer;
            border: 1px solid transparent; text-decoration: none; transition: background .15s, border-color .15s, color .15s;
        }
        .pmh-btn-primary { background: #1890ff; color: #fff; }
        .pmh-btn-primary:hover:not([disabled]) { background: #0f7ae5; }
        .pmh-btn-primary[disabled] { opacity: .5; cursor: not-allowed; }
        .pmh-btn-ghost { background: #fff; border-color: #d9d9d9; color: #262626; }
        .pmh-btn-ghost:hover:not([disabled]) { border-color: #1890ff; color: #1890ff; }
        .pmh-btn-danger { background: #fff; border-color: #ffa39e; color: #ff4d4f; }
        .pmh-btn-danger:hover:not([disabled]) { background: #ff4d4f; border-color: #ff4d4f; color: #fff; }

        /* Dropdown tiện ích */
        .pmh-util { position: relative; }
        .pmh-util-btn {
            height: 34px; display: inline-flex; align-items: center; gap: 6px; border: 1px solid #d9d9d9;
            border-radius: 4px; background: #fff; padding: 0 12px; font-size: 13px; color: #595959; cursor: pointer;
        }
        .pmh-util-btn:hover { border-color: #1890ff; color: #1890ff; }
        .pmh-util-caret { transition: transform .2s; }
        .pmh-util.open .pmh-util-caret { transform: rotate(180deg); }
        .pmh-util-menu {
            display: none; position: absolute; right: 0; top: calc(100% + 4px); z-index: 1050; min-width: 190px;
            background: #fff; border: 1px solid #e6e6e6; border-radius: 6px; box-shadow: 0 6px 20px rgba(0, 0, 0, .12);
            padding: 4px; flex-direction: column;
        }
        .pmh-util.open .pmh-util-menu { display: flex; }
        .pmh-util-item {
            display: flex; align-items: center; gap: 8px; width: 100%; padding: 8px 10px; border: 0; border-radius: 4px;
            background: none; font-size: 13px; color: #262626; text-decoration: none; cursor: pointer;
        }
        .pmh-util-item:hover { background: #f5f5f5; color: #1890ff; }
        .pmh-btn-sq { width: 34px; padding: 0; justify-content: center; flex-shrink: 0; }
        .pmh-cot-menu { min-width: 210px; max-height: 320px; overflow-y: auto; }
        .pmh-cot-item {
            display: flex; align-items: center; gap: 8px; padding: 6px 10px; border-radius: 4px;
            font-size: 13px; color: #262626; cursor: pointer; margin: 0;
        }
        .pmh-cot-item:hover { background: #f5f5f5; }
        .pmh-cot-item input { width: 14px; height: 14px; accent-color: #1890ff; cursor: pointer; }
        .pmh-cot-item.is-all { font-weight: 600; }
        .pmh-cot-line { height: 1px; margin: 4px 0; background: #f0f0f0; }

        /* Bảng — mọi ô canh giữa, bề rộng khai theo % và cộng đúng 100%. */
        .pmh-table-wrap { width: 100%; padding: 0 20px; overflow-x: auto; scrollbar-width: thin; }
        .pmh-table-wrap::-webkit-scrollbar { height: 11px; }
        .pmh-table-wrap::-webkit-scrollbar-thumb { background-color: #dcdcdc; border-radius: 8px; border: 3px solid #fff; }
        .pmh-table { width: 100%; min-width: 1400px; border-collapse: collapse; font-size: 13px; table-layout: fixed; }
        .pmh-table thead tr { background: #f0f0f0; color: #262626; }
        .pmh-table thead th { text-align: center; padding: 14px 10px; font-size: 13px; font-weight: 700; white-space: nowrap; }
        .pmh-table tbody td {
            padding: 14px 10px; border-bottom: 1px solid #f0f0f0; vertical-align: middle;
            text-align: center; white-space: nowrap; line-height: 1.5;
        }
        .pmh-table tbody tr:hover { background: #fafafa; }
        .pmh-table tbody tr.is-selected { background: #e6f7ff; }

        .pmh-table th.pmh-c-check,    .pmh-table td.pmh-c-check    { width: 3%; }
        .pmh-table th.pmh-c-stt,      .pmh-table td.pmh-c-stt      { width: 3%; color: #8c8c8c; }
        .pmh-table th.pmh-c-code,     .pmh-table td.pmh-c-code     { width: 8%; cursor: pointer; }
        .pmh-table th.pmh-c-supplier, .pmh-table td.pmh-c-supplier { width: 13%; overflow: hidden; text-overflow: ellipsis; cursor: pointer; }
        .pmh-table th.pmh-c-docdate,  .pmh-table td.pmh-c-docdate  { width: 8%; color: #595959; }
        .pmh-table th.pmh-c-items,    .pmh-table td.pmh-c-items    { width: 9%; color: #595959; }
        .pmh-table th.pmh-c-total,    .pmh-table td.pmh-c-total    { width: 9%; }
        .pmh-table th.pmh-c-debt,     .pmh-table td.pmh-c-debt     { width: 9%; }
        .pmh-table th.pmh-c-status,   .pmh-table td.pmh-c-status   { width: 8%; }
        .pmh-table th.pmh-c-pay,      .pmh-table td.pmh-c-pay      { width: 9%; }
        .pmh-table th.pmh-c-note,     .pmh-table td.pmh-c-note     { width: 9%; overflow: hidden; text-overflow: ellipsis; color: #595959; }
        .pmh-table th.pmh-c-act,      .pmh-table td.pmh-c-act      { width: 12%; }

        .pmh-code { font-weight: 600; color: #1890ff; }
        .pmh-name { display: block; font-weight: 500; overflow: hidden; text-overflow: ellipsis; }
        .pmh-muted { color: #bfbfbf; }
        .pmh-empty { padding: 40px 12px; text-align: center; color: #8c8c8c; white-space: normal; }
        .pmh-check { width: 15px; height: 15px; cursor: pointer; accent-color: #1890ff; }

        .pmh-rowacts { display: inline-flex; align-items: center; justify-content: center; gap: 6px; }
        .pmh-rowbtn {
            width: 30px; height: 30px; display: inline-flex; align-items: center; justify-content: center;
            border: 1px solid #d9d9d9; border-radius: 4px; background: #fff; color: #595959; cursor: pointer;
            transition: border-color .15s, color .15s;
        }
        .pmh-rowbtn:hover { border-color: #1890ff; color: #1890ff; }
        .pmh-rowbtn.is-ok:hover { border-color: #52c41a; color: #52c41a; }
        .pmh-rowbtn.is-danger:hover { border-color: #ff4d4f; color: #ff4d4f; }

        /* Thanh thao tác hàng loạt — pill trắng nổi giữa đáy vùng nội dung */
        .pmh-bulk {
            position: fixed; bottom: 24px; left: 230px; right: 0; margin-inline: auto; z-index: 1070;
            width: max-content; max-width: calc(100vw - 260px);
            display: flex; align-items: center; gap: 16px; border: 1px solid #e6e6e6; border-radius: 9999px;
            background: #fff; padding: 12px 20px; box-shadow: 0 6px 24px rgba(0, 0, 0, .15);
        }
        body:has(.jh-sidebar.collapsed) .pmh-bulk { left: 48px; }
        @media (max-width: 820px) { .pmh-bulk { left: 0; } }
        .pmh-bulk-count { font-size: 13px; white-space: nowrap; }
        .pmh-bulk-count b { color: #1890ff; }
        .pmh-bulk-clear { border: 0; background: none; font-size: 13px; color: #8c8c8c; cursor: pointer; padding: 0; }
        .pmh-bulk-clear:hover { color: #262626; }
        .pmh-bulk-actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .pmh-bulk-actions .pmh-btn-ghost, .pmh-bulk-actions .pmh-btn-danger { height: 30px; }

        /* Modal */
        .pmh-overlay {
            position: fixed; inset: 0; z-index: 1055; background: rgba(0, 0, 0, .45);
            display: flex; align-items: center; justify-content: center; padding: 16px;
        }
        .pmh-dialog {
            width: 100%; max-width: 940px; max-height: 92vh; overflow-y: auto; background: #fff;
            border-radius: 10px; box-shadow: 0 12px 40px rgba(0, 0, 0, .2); scrollbar-width: thin;
        }
        /* Hộp lập phiếu rộng hơn hẳn: lưới hàng có mười ba cột, bó vào 1180
           là màn rộng vẫn phải cuộn ngang một cách vô cớ. */
        .pmh-dialog-xl { max-width: 1480px; }
        .pmh-dialog-lg { max-width: 1000px; }
        .pmh-dialog-sm { max-width: 520px; }
        .pmh-modal-head {
            position: sticky; top: 0; z-index: 3; background: #fff;
            display: flex; align-items: center; justify-content: space-between; gap: 12px;
            padding: 14px 20px; border-bottom: 1px solid #f0f0f0;
        }
        .pmh-modal-title { margin: 0; font-size: 15px; font-weight: 700; }
        .pmh-modal-x { border: 0; background: none; color: #8c8c8c; cursor: pointer; padding: 4px; border-radius: 4px; }
        .pmh-modal-x:hover { background: #f5f5f5; color: #262626; }
        .pmh-modal-body { padding: 16px 20px; display: flex; flex-direction: column; gap: 16px; }
        /* Hàng nút chân hộp thoại luôn canh giữa */
        .pmh-modal-foot {
            position: sticky; bottom: 0; z-index: 3; display: flex; align-items: center; justify-content: center;
            gap: 12px; flex-wrap: wrap; padding: 12px 20px; border-top: 1px solid #f0f0f0; background: #fafafa;
        }

        /* Hàng nút của hộp lập phiếu — nằm TRÊN thân, canh phải như bản v2 */
        .pmh-form-bar {
            position: sticky; top: 49px; z-index: 2; display: flex; justify-content: flex-end; gap: 8px;
            padding: 10px 20px; background: #fff; border-bottom: 1px solid #f0f0f0;
        }

        /* Thông tin phiếu: 4 cột × 4 ô, đúng lưới của v2 */
        .pmh-info { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px 16px; }
        @media (max-width: 1000px) { .pmh-info { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 560px) { .pmh-info { grid-template-columns: 1fr; } }
        .pmh-info-col { display: flex; flex-direction: column; gap: 10px; min-width: 0; }
        .pmh-input[disabled] { background: #f5f5f5; color: #8c8c8c; cursor: default; }

        .pmh-ncc-row { display: flex; gap: 6px; }
        .pmh-ncc-row .pmh-input { min-width: 0; }
        .pmh-ncc-add {
            width: 34px; flex-shrink: 0; display: inline-flex; align-items: center; justify-content: center;
            border: 1px solid #1890ff; border-radius: 4px; background: #fff; color: #1890ff; cursor: pointer;
        }
        .pmh-ncc-add:hover { background: #1890ff; color: #fff; }
        /*
           Ô nhập có đơn vị đi kèm: "%" nằm TRONG khung, sát mép phải, xám hơn
           con số. Để nó ngoài khung thì hàng ô cao thấp so le và cái "%" trông
           như một chữ rơi vãi cạnh ô nhập.

           pointer-events:none để bấm trúng chữ "%" vẫn là bấm vào ô nhập.
        */
        .pmh-donvi { position: relative; }
        .pmh-donvi .pmh-input { padding-right: 34px; text-align: right; }
        .pmh-donvi.is-rong .pmh-input { padding-right: 60px; }
        .pmh-donvi-chu {
            position: absolute; right: 10px; top: 0; height: 34px;
            display: flex; align-items: center;
            font-size: 13px; color: #8c8c8c; pointer-events: none; user-select: none;
        }

        /*
           Nút tăng/giảm của ô số: bỏ hẳn. Chúng đè lên chữ đơn vị, và ở một ô
           thuế suất thì bấm mũi tên từng nấc một là cách chậm nhất để đi từ 0
           tới 10.
        */
        .pmh-input[type="number"], .pmh-l-input[type="number"] { -moz-appearance: textfield; appearance: textfield; }
        .pmh-input[type="number"]::-webkit-outer-spin-button,
        .pmh-input[type="number"]::-webkit-inner-spin-button,
        .pmh-l-input[type="number"]::-webkit-outer-spin-button,
        .pmh-l-input[type="number"]::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }

        /* Form */
        .pmh-form-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px 16px; }
        @media (max-width: 900px) { .pmh-form-grid { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 560px) { .pmh-form-grid { grid-template-columns: 1fr; } }
        .pmh-field { display: flex; flex-direction: column; gap: 4px; min-width: 0; }
        .pmh-field.is-2 { grid-column: span 2; }
        @media (max-width: 560px) { .pmh-field.is-2 { grid-column: span 1; } }
        .pmh-field-label { font-size: 12px; font-weight: 600; color: #595959; }
        .pmh-input {
            border: 1px solid #d9d9d9; border-radius: 4px; padding: 0 10px; height: 34px;
            font-size: 13px; color: #262626; outline: none; background: #fff; width: 100%;
        }
        .pmh-input:focus { border-color: #86b7fe; box-shadow: 0 0 0 .2rem rgba(13, 110, 253, .2); }
        .pmh-input-sm { width: auto; min-width: 180px; }
        .pmh-hint { margin: 0; font-size: 12px; color: #8c8c8c; }
        .pmh-req { color: #ff4d4f; }
        .pmh-note-box { margin: 0; padding: 10px 12px; border-radius: 6px; background: #f6f8fa; font-size: 12px; color: #595959; }

        .pmh-anh-nut {
            height: 32px; display: inline-flex; align-items: center; justify-content: center; padding: 0 12px;
            border: 1px solid #d9d9d9; border-radius: 4px; background: #fff; font-size: 13px; color: #262626;
            cursor: pointer; margin: 0;
        }
        .pmh-anh-nut:hover { border-color: #1890ff; color: #1890ff; }
        .pmh-anh-link { font-size: 12px; color: #1890ff; }
        .pmh-anh-go { border: 0; background: none; font-size: 12px; color: #ff4d4f; cursor: pointer; padding: 0; }

        /* Khối "Thông tin hàng hóa" */
        .pmh-goods { border-top: 1px solid #f0f0f0; padding-top: 12px; }
        .pmh-goods-head {
            display: flex; align-items: center; justify-content: space-between; gap: 16px;
            flex-wrap: wrap; margin-bottom: 10px;
        }
        .pmh-goods-title { margin: 0; font-size: 14px; font-weight: 700; }
        .pmh-goods-tools { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-left: auto; }
        .pmh-import-error {
            margin: 0 0 10px; padding: 8px 12px; border-radius: 6px;
            background: #fff1f0; color: #cf1322; font-size: 12px;
        }

        .pmh-pick { position: relative; width: 300px; min-width: 220px; }
        .pmh-pick-icon { position: absolute; left: 10px; top: 9px; color: #8c8c8c; pointer-events: none; }
        .pmh-pick-input {
            width: 100%; height: 34px; border: 1px solid #d9d9d9; border-radius: 4px; background: #fff;
            padding: 0 12px 0 32px; font-size: 13px; outline: none;
        }
        .pmh-pick-input:focus { border-color: #86b7fe; box-shadow: 0 0 0 .2rem rgba(13, 110, 253, .2); }
        .pmh-pick-menu {
            position: absolute; left: 0; right: 0; top: calc(100% + 4px); z-index: 1060; max-height: 300px;
            overflow-y: auto; background: #fff; border: 1px solid #e6e6e6; border-radius: 6px;
            box-shadow: 0 6px 20px rgba(0, 0, 0, .12); padding: 4px;
        }
        .pmh-pick-row {
            display: flex; align-items: center; gap: 10px; width: 100%; padding: 8px 10px; border: 0;
            border-radius: 4px; background: none; text-align: left; cursor: pointer;
        }
        .pmh-pick-row:hover, .pmh-pick-row.is-on { background: #e6f7ff; }
        .pmh-pick-row[disabled] { cursor: default; color: #8c8c8c; }
        .pmh-pick-main { flex: 1; min-width: 0; }
        .pmh-pick-name { display: block; font-size: 13px; color: #262626; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .pmh-pick-sub { display: block; font-size: 12px; color: #8c8c8c; }
        .pmh-pick-right { text-align: right; font-size: 12px; color: #595959; white-space: nowrap; }


        .pmh-lines-wrap { overflow-x: auto; scrollbar-width: thin; }
        .pmh-lines-wrap::-webkit-scrollbar { height: 11px; }
        .pmh-lines-wrap::-webkit-scrollbar-thumb { background-color: #dcdcdc; border-radius: 8px; border: 3px solid #fff; }
        /*
           Bề rộng tối thiểu rộng hơn hẳn khung hộp thoại, và lưới cuộn ngang.
           Ép mười ba cột vào đúng bề ngang màn hình là mọi ô đều hẹp tới mức
           cụt chữ: ô ngày còn "dd/mm/", ô đơn vị còn "Đơn vị…".
        */
        .pmh-lines-table { width: 100%; min-width: 1400px; border-collapse: collapse; font-size: 13px; table-layout: fixed; }
        .pmh-lines-table thead th {
            text-align: center; padding: 10px 8px; font-size: 12px; font-weight: 700; color: #262626;
            background: #f0f0f0; white-space: normal; line-height: 1.3;
        }
        .pmh-lines-table tbody td {
            padding: 7px 8px; border-bottom: 1px solid #f5f5f5; vertical-align: middle; text-align: center;
            /* Một dòng, không xuống hàng. Mã hàng dài mà cho xuống hàng thì một
               dòng phiếu cao bằng bảy dòng, và cả lưới đọc không ra hàng nào. */
            white-space: nowrap;
        }
        .pmh-lines-table tbody tr:hover { background: #fafafa; }

        /* Mười ba cột, cộng đúng 100%. Ẩn cột % VAT thì 5% đó dồn cho Tên hàng. */
        .pmh-lines-table th.pmh-l-idx,      .pmh-lines-table td.pmh-l-idx      { width: 3%; color: #8c8c8c; }
        .pmh-lines-table th.pmh-l-code,     .pmh-lines-table td.pmh-l-code     { width: 10%; }
        .pmh-lines-table th.pmh-l-name,     .pmh-lines-table td.pmh-l-name     { width: 14%; text-align: left; }
        .pmh-lines-table th.pmh-l-unit,     .pmh-lines-table td.pmh-l-unit     { width: 8%; }
        .pmh-lines-table th.pmh-l-qty,      .pmh-lines-table td.pmh-l-qty      { width: 8%; }
        .pmh-lines-table th.pmh-l-cost,     .pmh-lines-table td.pmh-l-cost     { width: 9%; }
        .pmh-lines-table th.pmh-l-sub,      .pmh-lines-table td.pmh-l-sub      { width: 9%; }
        .pmh-lines-table th.pmh-l-vat,      .pmh-lines-table td.pmh-l-vat      { width: 5%; }
        .pmh-lines-table th.pmh-l-vatmoney, .pmh-lines-table td.pmh-l-vatmoney { width: 7%; }
        .pmh-lines-table th.pmh-l-amount,   .pmh-lines-table td.pmh-l-amount   { width: 9%; }
        .pmh-lines-table th.pmh-l-lot,      .pmh-lines-table td.pmh-l-lot      { width: 7%; }
        .pmh-lines-table th.pmh-l-exp,      .pmh-lines-table td.pmh-l-exp      { width: 9%; }
        .pmh-lines-table th.pmh-l-x,        .pmh-lines-table td.pmh-l-x        { width: 2%; }

        /* Khai thuế theo phiếu: cột % VAT biến mất, đúng như v2 */
        .pmh-lines-table:not(.is-vat-goods) th.pmh-l-vat,
        .pmh-lines-table:not(.is-vat-goods) td.pmh-l-vat { display: none; }
        .pmh-lines-table:not(.is-vat-goods) th.pmh-l-name,
        .pmh-lines-table:not(.is-vat-goods) td.pmh-l-name { width: 19%; }

        /* Mã và tên: cắt bằng dấu ba chấm, chữ đủ nằm trong thẻ title. */
        .pmh-lines-table td.pmh-l-code, .pmh-lines-table td.pmh-l-name { overflow: hidden; text-overflow: ellipsis; }
        .pmh-l-ma { font-size: 12px; color: #595959; }

        /*
           Ba cột tiền canh PHẢI và dùng chữ số đều bề ngang: xếp thành một cột
           số thẳng hàng thì liếc một cái là so được, còn canh giữa thì mỗi dòng
           lệch một kiểu.
        */
        .pmh-lines-table th.pmh-l-sub, .pmh-lines-table td.pmh-l-sub,
        .pmh-lines-table th.pmh-l-vatmoney, .pmh-lines-table td.pmh-l-vatmoney,
        .pmh-lines-table th.pmh-l-amount, .pmh-lines-table td.pmh-l-amount { text-align: right; }
        .pmh-lines-table td.pmh-l-sub,
        .pmh-lines-table td.pmh-l-vatmoney,
        .pmh-lines-table td.pmh-l-amount { font-variant-numeric: tabular-nums; }
        .pmh-lines-table td.pmh-l-vatmoney { color: #595959; }
        .pmh-lines-table td.pmh-l-sub { color: #595959; }

        .pmh-l-input {
            width: 100%; height: 32px; border: 1px solid #d9d9d9; border-radius: 4px; background: #fff;
            padding: 0 8px; font-size: 13px; text-align: right; outline: none; color: #262626;
            font-variant-numeric: tabular-nums;
        }
        .pmh-l-input:focus { border-color: #86b7fe; box-shadow: 0 0 0 .15rem rgba(13, 110, 253, .2); }
        select.pmh-l-input { text-align: left; }
        .pmh-l-input.is-text { text-align: left; }
        .pmh-l-input[type="date"] { text-align: left; padding-right: 4px; }
        .pmh-l-name-main { display: block; font-weight: 500; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .pmh-l-name-sub { display: block; font-size: 12px; color: #8c8c8c; }

        /*
           Dòng quy đổi — thứ duy nhất trên màn này đáng được nhấn.
           Mua theo thùng mà kho đếm theo cái là chỗ sai nhiều nhất của phiếu
           mua, nên số THẬT SỰ vào kho phải nằm ngay dưới ô số lượng.

           Viết tắt "= 48 Hộp" cho vừa bề ngang ô; câu đầy đủ nằm trong title.
        */
        .pmh-l-conv {
            display: block; margin-top: 2px; font-size: 11px; color: #096dd9; white-space: nowrap;
            overflow: hidden; text-overflow: ellipsis;
        }
        .pmh-l-amount b { font-weight: 600; }
        .pmh-l-x-btn {
            width: 26px; height: 26px; display: inline-flex; align-items: center; justify-content: center;
            border: 1px solid transparent; border-radius: 4px; background: none; color: #bfbfbf; cursor: pointer;
        }
        .pmh-l-x-btn:hover { border-color: #ffa39e; color: #ff4d4f; }
        .pmh-lines-empty { margin: 0; padding: 28px 12px; text-align: center; font-size: 13px; color: #8c8c8c; }

        /* Khối tiền — canh phải, ba dòng như v2 */
        .pmh-money {
            margin-left: auto; width: 100%; max-width: 420px; display: flex; flex-direction: column; gap: 8px;
        }
        .pmh-money-row { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
        .pmh-money-lb { font-size: 13px; color: #595959; }
        .pmh-money-vl { font-size: 13px; color: #262626; font-variant-numeric: tabular-nums; }
        .pmh-money-row.is-total {
            margin-top: 4px; padding-top: 8px; border-top: 1px solid #f0f0f0;
        }
        .pmh-money-row.is-total .pmh-money-lb { font-weight: 700; color: #262626; }
        .pmh-money-row.is-total .pmh-money-vl { font-size: 16px; font-weight: 700; color: #1890ff; }

        /*
           Bản in: nút "In danh sách" phải ra tờ giấy có bảng, không phải ảnh
           chụp cả trang quản trị. Giấu khung điều hướng, bộ lọc và cột thao tác.
        */
        @media print {
            .jh-sidebar, .jh-topbar, .toast-container,
            .pmh-filter, .pmh-bulk, .pgv2, .pmh-c-check, .pmh-c-act { display: none !important; }
            .pmh { margin: 0; min-height: 0; }
            .pmh-table-wrap { padding: 0; overflow: visible; }
            .pmh-table { min-width: 0; font-size: 11px; }
            .pmh-table tbody tr { page-break-inside: avoid; }
            .pmh-chip { background: none !important; color: #000 !important; padding: 0; font-weight: 400; }
        }

        .pmh-attach { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }

        /* Hộp chi tiết */
        .pmh-view-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px 16px; }
        @media (max-width: 900px) { .pmh-view-grid { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 560px) { .pmh-view-grid { grid-template-columns: 1fr; } }
        .pmh-cell { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
        .pmh-cell.is-full { grid-column: 1 / -1; }
        .pmh-lb { font-size: 12px; color: #8c8c8c; }
        .pmh-vl { font-size: 13px; color: #262626; word-break: break-word; }
        .pmh-view-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .pmh-view-table thead th {
            text-align: center; padding: 10px 8px; font-size: 12px; font-weight: 700; color: #595959;
            background: #fafafa; white-space: nowrap;
        }
        .pmh-view-table tbody td { padding: 10px 8px; border-bottom: 1px solid #f5f5f5; text-align: center; }
        .pmh-view-table tbody td.is-left { text-align: left; }
        .pmh-his { display: flex; flex-direction: column; gap: 8px; }
        .pmh-his-row { display: flex; gap: 10px; font-size: 12px; color: #595959; }
        .pmh-his-time { color: #8c8c8c; white-space: nowrap; }
        .pmh-sec-title { margin: 0; font-size: 13px; font-weight: 700; color: #262626; }
    </style>

    <script>
        (function () {
            const CSRF = '{{ csrf_token() }}';
            const RETURN_URL = @json(request()->getRequestUri());
            const URL_BASE = @json(url('/admin/purchase-orders'));
            const URL_STORE = @json(route('admin.phieu-mua-hang.store'));
            const URL_MAT_HANG = @json(route('admin.phieu-mua-hang.matHang'));
            const URL_ANH = @json(route('admin.phieu-mua-hang.anh'));
            const URL_NCC_NHANH = @json(route('admin.phieu-mua-hang.themNhanhNCC'));
            const URL_NCC_ANH = @json(route('admin.nha-cung-cap.anh'));
            const URL_BULK_APPROVE = @json(route('admin.phieu-mua-hang.bulkApprove'));
            const URL_BULK_DEL = @json(route('admin.phieu-mua-hang.bulkDestroy'));

            const ROWS = @json($list);
            const BY_ID = new Map(ROWS.map((r) => [Number(r.id), r]));
            const NHAN_TRANG_THAI = @json(\App\Http\Controllers\PhieuMuaHangController::TRANG_THAI);
            const MAU_TRANG_THAI = @json(\App\Http\Controllers\PhieuMuaHangController::MAU_TRANG_THAI);
            const NHAN_TRA = @json(\App\Http\Controllers\PhieuMuaHangController::TRANG_THAI_TRA);
            const MAU_TRA = @json(\App\Http\Controllers\PhieuMuaHangController::MAU_TRANG_THAI_TRA);

            const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => (
                { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]
            ));
            const money = (v) => (Number(v) || 0).toLocaleString('vi-VN') + '₫';
            const soN = (v) => {
                const n = Number(String(v ?? '').replace(/[^\d-]/g, ''));
                return Number.isFinite(n) ? n : 0;
            };
            const nhomSo = (v) => (Number(v) || 0).toLocaleString('vi-VN');
            const ngay = (s) => {
                if (!s) return '—';
                const d = new Date(String(s).replace(' ', 'T'));
                return isNaN(d) ? String(s) : d.toLocaleDateString('vi-VN');
            };
            const gioNgay = (s) => {
                if (!s) return '';
                const d = new Date(String(s).replace(' ', 'T'));
                return isNaN(d) ? String(s) : d.toLocaleString('vi-VN', {
                    day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit',
                });
            };
            // VAT âm là mã thuế đặc biệt của mặt hàng, không phải phần trăm.
            const nhanVat = (v) => (v === -1 ? 'KCT' : v === -2 ? 'KKKNT' : (Number(v) || 0) + '%');

            /**
             * Con trỏ trong ô tiền đếm TỪ PHẢI SANG.
             *
             * Ô tiền được chấm lại dấu nghìn sau mỗi phím, nên vị trí tính từ
             * trái nhảy một bước mỗi lần chuỗi dài thêm một dấu chấm — gõ số
             * hàng triệu là con trỏ tự bò về đầu.
             *
             * Ô số (input[type=number]) không có vùng chọn: đọc selectionStart
             * trên nó ném lỗi, nên bọc lại và trả về null nghĩa là "để yên".
             */
            function viTriTuPhai(o) {
                try {
                    return o.selectionStart == null ? null : o.value.length - o.selectionStart;
                } catch (e) {
                    return null;
                }
            }

            function datConTro(o, tuPhai) {
                if (tuPhai == null) return;
                const i = Math.max(0, o.value.length - tuPhai);
                try { o.setSelectionRange(i, i); } catch (e) { /* ô số */ }
            }

            // ---------- Bộ lọc ----------
            const $filter = document.getElementById('pmhFilter');

            (function () {
                const btn = document.getElementById('pmhAdvToggle');
                const row = document.getElementById('pmhAdvRow');
                const setOpen = (open) => {
                    row.classList.toggle('is-open', open);
                    btn.classList.toggle('is-open', open);
                    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
                };
                if (!row.classList.contains('is-open') && localStorage.getItem('pmh-adv-open') === '1') setOpen(true);
                btn.addEventListener('click', () => {
                    const open = !row.classList.contains('is-open');
                    setOpen(open);
                    localStorage.setItem('pmh-adv-open', open ? '1' : '0');
                });
            })();

            $filter.querySelectorAll('select, input[type="checkbox"], input[type="date"]')
                .forEach((el) => el.addEventListener('change', () => $filter.submit()));
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

            /**
             * Đóng hộp thoại — hộp NẰM TRÊN đóng trước.
             *
             * Hộp thêm nhà cung cấp mở đè lên hộp lập phiếu. Đóng cả hai một
             * lượt là người dùng vừa khai xong bên bán thì mất luôn cái phiếu
             * đang gõ dở phía dưới.
             */
            const closeAll = () => {
                const ncc = document.getElementById('nccFormOverlay');
                if (ncc.style.display !== 'none') { ncc.style.display = 'none'; return; }

                ['pmhFormOverlay', 'pmhDetailOverlay', 'pmhPayOverlay', 'pmhCancelOverlay']
                    .forEach((id) => { document.getElementById(id).style.display = 'none'; });
            };
            document.querySelectorAll('[data-close]').forEach((b) => b.addEventListener('click', closeAll));
            document.addEventListener('keydown', (e) => {
                if (e.key !== 'Escape') return;
                // Ô tìm hàng đang mở thì Esc đóng nó trước, chưa đóng cả hộp thoại.
                if (!pickMenu.hidden) { dongPick(); return; }
                closeAll();
            });

            // =================================================================
            //  Hộp thoại Lập / Sửa phiếu
            // =================================================================
            const $form = document.getElementById('pmhForm');
            const $formOverlay = document.getElementById('pmhFormOverlay');
            const $lines = document.getElementById('pmhLines');
            const $linesTable = document.getElementById('pmhLinesTable');
            const $linesEmpty = document.getElementById('pmhLinesEmpty');
            const $vatMode = document.getElementById('pmhVatMode');
            const $vatPercent = document.getElementById('pmhVatPercent');
            const $attachment = document.getElementById('pmhAttachment');
            const $importError = document.getElementById('pmhImportError');

            // Ô ghi được, tên khoá = tên trường API.
            const O = {
                supplier_id: document.getElementById('pmhNCC'),
                document_date: document.getElementById('pmhNgayCT'),
                expected_date: document.getElementById('pmhNgayHetHan'),
                purchaser_id: document.getElementById('pmhNguoiMua'),
                supplier_delivery_code: document.getElementById('pmhSoGiao'),
                note: document.getElementById('pmhGhiChu'),
            };
            // Ô chỉ để đọc: hồ sơ bên bán và thông tin phiếu do hệ thống đặt.
            const X = {
                tenNCC: document.getElementById('pmhTenNCC'),
                diaChi: document.getElementById('pmhDiaChi'),
                diaChi2: document.getElementById('pmhDiaChi2'),
                dienThoai: document.getElementById('pmhDienThoai'),
                sdtLienHe: document.getElementById('pmhSDTLienHe'),
                ngayLap: document.getElementById('pmhNgayLap'),
                trangThai: document.getElementById('pmhTrangThai'),
            };

            /** Mỗi phần tử là một dòng hàng đang dựng trong hộp thoại. */
            let DONG = [];
            let dongSeq = 0;

            /**
             * Chọn nhà cung cấp thì bốn ô hồ sơ bên dưới tự điền.
             *
             * Tên bên bán đi vào ô ẩn chứ không phải ô gõ được: đó là bản CHỤP
             * ghi xuống chứng từ, và để người ta sửa tay thì phiếu in ra một
             * đằng, danh mục một nẻo.
             */
            function veHoSoNCC() {
                const opt = O.supplier_id.selectedOptions[0];
                const d = opt ? opt.dataset : {};
                X.tenNCC.value = d.name || '';
                X.diaChi.value = d.address || '';
                X.diaChi2.value = d.address2 || '';
                X.dienThoai.value = d.phone || '';
                X.sdtLienHe.value = d.repPhone || '';
            }
            O.supplier_id.addEventListener('change', veHoSoNCC);

            // ---------- Ô tìm hàng ----------
            const pickInput = document.getElementById('pmhPickInput');
            const pickMenu = document.getElementById('pmhPickMenu');
            const $nhomHang = document.getElementById('pmhNhomHang');
            let pickTimer = null;
            let pickRows = [];
            let pickIdx = -1;

            const dongPick = () => { pickMenu.hidden = true; pickIdx = -1; };

            function vePick(rows, loi) {
                pickRows = rows;
                pickIdx = -1;
                if (loi) {
                    pickMenu.innerHTML = `<button type="button" class="pmh-pick-row" disabled>${esc(loi)}</button>`;
                    pickMenu.hidden = false;
                    return;
                }
                if (!rows.length) {
                    pickMenu.innerHTML = '<button type="button" class="pmh-pick-row" disabled>'
                        + 'Không tìm thấy hàng hóa nào đang bán khớp từ khóa.</button>';
                    pickMenu.hidden = false;
                    return;
                }
                pickMenu.innerHTML = rows.map((r, i) => {
                    const ten = [r.product_name, r.variant_name].filter(Boolean).join(' · ');
                    const gia = r.cost_price == null ? 'chưa khai giá vốn' : money(r.cost_price);
                    return `<button type="button" class="pmh-pick-row" data-i="${i}">
                        <span class="pmh-pick-main">
                            <span class="pmh-pick-name">${esc(ten)}</span>
                            <span class="pmh-pick-sub">${esc(r.sku || '')} · kho này còn ${nhomSo(r.stock)} ${esc(r.base_unit_name || '')}</span>
                        </span>
                        <span class="pmh-pick-right">${esc(gia)}</span>
                    </button>`;
                }).join('');
                pickMenu.hidden = false;
            }

            async function timHang(kw) {
                const nhom = Number($nhomHang.value) || 0;
                try {
                    const res = await fetch(
                        `${URL_MAT_HANG}?keyword=${encodeURIComponent(kw)}&category_id=${nhom}`,
                        { headers: { Accept: 'application/json' } },
                    );
                    const data = await res.json();
                    vePick(data.data || [], res.ok ? '' : (data.message || 'Không tìm được hàng hóa.'));
                } catch (e) {
                    vePick([], 'Không gọi được máy chủ để tìm hàng.');
                }
            }

            pickInput.addEventListener('input', () => {
                clearTimeout(pickTimer);
                pickTimer = setTimeout(() => timHang(pickInput.value.trim()), 300);
            });
            pickInput.addEventListener('focus', () => {
                if (pickRows.length) { pickMenu.hidden = false; } else { timHang(pickInput.value.trim()); }
            });
            // Đổi nhóm hàng là lọc lại ngay, không đợi gõ thêm chữ nào.
            $nhomHang.addEventListener('change', () => timHang(pickInput.value.trim()));
            pickInput.addEventListener('keydown', (e) => {
                if (pickMenu.hidden || !pickRows.length) return;
                if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                    e.preventDefault();
                    pickIdx = (pickIdx + (e.key === 'ArrowDown' ? 1 : -1) + pickRows.length) % pickRows.length;
                    pickMenu.querySelectorAll('.pmh-pick-row').forEach((el, i) => el.classList.toggle('is-on', i === pickIdx));
                    pickMenu.querySelectorAll('.pmh-pick-row')[pickIdx]?.scrollIntoView({ block: 'nearest' });
                } else if (e.key === 'Enter') {
                    e.preventDefault();
                    themDong(pickRows[pickIdx >= 0 ? pickIdx : 0]);
                }
            });
            pickMenu.addEventListener('click', (e) => {
                const nut = e.target.closest('.pmh-pick-row[data-i]');
                if (nut) themDong(pickRows[Number(nut.dataset.i)]);
            });
            document.addEventListener('click', (e) => {
                if (!e.target.closest('#pmhPick')) dongPick();
            });

            /**
             * Thêm một dòng hàng.
             *
             * Chọn lại đúng món ĐANG CÓ trong phiếu với cùng đơn vị và cùng số lô
             * thì cộng số lượng vào dòng cũ. Khác đơn vị hoặc khác lô thì là dòng
             * riêng: mua 1 thùng và 5 cái, hay hai lô khác nhau, đều là những
             * dòng có thật trên hóa đơn bên bán.
             */
            function themDong(mh, ghiDe) {
                if (!mh) return;
                dongPick();
                pickInput.value = '';
                pickRows = [];

                const donVi = (mh.units && mh.units.length) ? mh.units : [{
                    unit_id: mh.base_unit_id || 0, name: mh.base_unit_name || '', ratio: 1,
                }];
                const unitID = ghiDe ? Number(ghiDe.unit_id || 0) : Number(donVi[0].unit_id || 0);
                const lot = ghiDe ? (ghiDe.lot_number || '') : '';

                const daCo = DONG.find((d) => d.variant_id === mh.variant_id && d.unit_id === unitID && d.lot_number === lot);
                if (daCo && !ghiDe) {
                    daCo.quantity += 1;
                    veDong();
                    return;
                }

                const dv = donVi.find((u) => Number(u.unit_id || 0) === unitID) || donVi[0];
                DONG.push({
                    key: ++dongSeq,
                    variant_id: mh.variant_id,
                    product_name: mh.product_name || '',
                    variant_name: mh.variant_name || '',
                    sku: mh.sku || '',
                    base_unit_name: mh.base_unit_name || '',
                    units: donVi,
                    unit_id: unitID,
                    ratio: Number(dv.ratio || 1),
                    quantity: ghiDe ? Number(ghiDe.quantity || 1) : 1,
                    unit_cost: ghiDe ? Number(ghiDe.unit_cost || 0) : Number(mh.cost_price || 0),
                    vat_hang: Number(mh.vat_percent || 0),
                    vat_percent: ghiDe ? Number(ghiDe.vat_percent || 0) : Number(mh.vat_percent || 0),
                    lot_number: lot,
                    expire_date: ghiDe ? (ghiDe.expire_date || '') : '',
                });
                veDong();
            }

            /** Thuế của một dòng: khai theo phiếu thì mọi dòng chung một mức. */
            function vatCuaDong(d) {
                return $vatMode.value === 'goods' ? d.vat_percent : soN($vatPercent.value);
            }

            function veDong() {
                $linesEmpty.style.display = DONG.length ? 'none' : '';
                const theoDong = $vatMode.value === 'goods';
                $linesTable.classList.toggle('is-vat-goods', theoDong);

                $lines.innerHTML = DONG.map((d, i) => {
                    const vat = vatCuaDong(d);
                    const tienHang = Math.round(d.unit_cost * d.quantity);
                    const tienThue = Math.round(tienHang * Math.max(0, vat) / 100);
                    const base = d.quantity * d.ratio;
                    const nguyen = Math.abs(base - Math.round(base)) < 0.0001;

                    const ten = [d.product_name, d.variant_name].filter(Boolean).join(' · ');
                    const donVi = d.units.map((u) => {
                        const id = Number(u.unit_id || 0);
                        const nhan = u.name || 'Đơn vị chính';
                        const hs = Number(u.ratio || 1) === 1 ? '' : ` (×${nhomSo(u.ratio)})`;
                        return `<option value="${id}" ${id === d.unit_id ? 'selected' : ''}>${esc(nhan)}${hs}</option>`;
                    }).join('');

                    // Dòng quy đổi chỉ nói khi có gì để nói: đơn vị mua = đơn vị
                    // kho thì câu "= 3 Cái vào kho" chỉ là tiếng ồn.
                    //
                    // Viết tắt cho vừa ô, câu đầy đủ để trong title — ô số lượng
                    // rộng chừng trăm hai, nhét cả câu vào là tràn sang cột bên.
                    let quyDoi = '';
                    if (d.ratio !== 1) {
                        const dv = esc(d.base_unit_name || 'đơn vị');
                        quyDoi = nguyen
                            ? `<span class="pmh-l-conv" title="${nhomSo(Math.round(base))} ${dv} sẽ vào kho">= ${nhomSo(Math.round(base))} ${dv}</span>`
                            : `<span class="pmh-l-conv" style="color:#ff4d4f" title="Quy đổi ra ${nhomSo(base)} ${dv} — sổ kho chỉ nhận số nguyên">Quy đổi lẻ</span>`;
                    }

                    return `<tr data-key="${d.key}">
                        <td class="pmh-l-idx">${i + 1}</td>
                        <td class="pmh-l-code" title="${esc(d.sku)}">
                            <span class="pmh-l-ma">${esc(d.sku || '—')}</span>
                        </td>
                        <td class="pmh-l-name" title="${esc(ten)}">
                            <span class="pmh-l-name-main">${esc(ten)}</span>
                        </td>
                        <td class="pmh-l-unit">
                            <select class="pmh-l-input" data-f="unit_id" aria-label="Đơn vị tính">${donVi}</select>
                        </td>
                        <td class="pmh-l-qty">
                            <input type="text" class="pmh-l-input" data-f="quantity" inputmode="numeric"
                                   value="${nhomSo(d.quantity)}" aria-label="Số lượng">
                            ${quyDoi}
                        </td>
                        <td class="pmh-l-cost">
                            <input type="text" class="pmh-l-input" data-f="unit_cost" inputmode="numeric"
                                   value="${nhomSo(d.unit_cost)}" aria-label="Giá nhập">
                        </td>
                        <td class="pmh-l-sub">${money(tienHang)}</td>
                        <td class="pmh-l-vat">
                            <input type="number" class="pmh-l-input" data-f="vat_percent" min="-2" max="100" step="1"
                                   value="${vat}" aria-label="Thuế suất">
                        </td>
                        <td class="pmh-l-vatmoney">${money(tienThue)}</td>
                        <td class="pmh-l-amount"><b>${money(tienHang + tienThue)}</b></td>
                        <td class="pmh-l-lot">
                            <input type="text" class="pmh-l-input is-text" data-f="lot_number" maxlength="50"
                                   value="${esc(d.lot_number)}" placeholder="Không lô" aria-label="Số lô">
                        </td>
                        <td class="pmh-l-exp">
                            <input type="date" class="pmh-l-input" data-f="expire_date"
                                   value="${esc(d.expire_date)}" aria-label="Hạn dùng">
                        </td>
                        <td class="pmh-l-x">
                            <button type="button" class="pmh-l-x-btn" data-xoa="${d.key}" title="Bỏ dòng này">
                                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                            </button>
                        </td>
                    </tr>`;
                }).join('');

                veTien();
            }

            function veTien() {
                let tienHang = 0;
                let thue = 0;
                DONG.forEach((d) => {
                    const line = Math.round(d.unit_cost * d.quantity);
                    tienHang += line;
                    thue += Math.round(line * Math.max(0, vatCuaDong(d)) / 100);
                });

                document.getElementById('pmhTienHang').textContent = money(tienHang);
                document.getElementById('pmhThue').textContent = money(thue);
                document.getElementById('pmhTongCong').textContent = money(tienHang + thue);

                document.getElementById('pmhSaveApprove').disabled = DONG.length === 0;
            }

            $lines.addEventListener('input', (e) => {
                const o = e.target.closest('[data-f]');
                if (!o) return;
                const key = Number(o.closest('tr').dataset.key);
                const d = DONG.find((x) => x.key === key);
                if (!d) return;

                const f = o.dataset.f;
                if (f === 'quantity') {
                    d.quantity = Math.max(0, soN(o.value));
                } else if (f === 'unit_cost') {
                    d.unit_cost = Math.max(0, soN(o.value));
                } else if (f === 'vat_percent') {
                    d.vat_percent = Math.max(-2, Math.min(100, Number(o.value) || 0));
                } else if (f === 'lot_number') {
                    // Số lô và hạn dùng không đổi con số nào khác trên lưới: ghi
                    // thẳng vào dữ liệu, khỏi vẽ lại cả bảng dưới tay người gõ.
                    d.lot_number = o.value;
                    return;
                } else if (f === 'expire_date') {
                    d.expire_date = o.value;
                    return;
                }

                // Vẽ lại cả lưới để dòng quy đổi và tiền của dòng đó cùng đổi
                // theo — nhưng giữ con trỏ ở đúng ô người dùng đang gõ.
                const phai = viTriTuPhai(o);
                veDong();
                const lai = $lines.querySelector(`tr[data-key="${key}"] [data-f="${f}"]`);
                if (lai) { lai.focus(); datConTro(lai, phai); }
            });

            $lines.addEventListener('change', (e) => {
                const o = e.target.closest('[data-f="unit_id"]');
                if (!o) return;
                const key = Number(o.closest('tr').dataset.key);
                const d = DONG.find((x) => x.key === key);
                if (!d) return;
                d.unit_id = Number(o.value);
                const dv = d.units.find((u) => Number(u.unit_id || 0) === d.unit_id);
                d.ratio = Number(dv?.ratio || 1);
                veDong();
            });

            $lines.addEventListener('click', (e) => {
                const nut = e.target.closest('[data-xoa]');
                if (!nut) return;
                DONG = DONG.filter((d) => d.key !== Number(nut.dataset.xoa));
                veDong();
            });

            /**
             * Nhãn trong khung ô thuế: "%" với số dương, "KCT"/"KKKNT" với hai
             * mã âm. Nhãn dài thì nới chỗ ra để con số không chui xuống dưới nó.
             */
            function veNhanVat() {
                const v = Number($vatPercent.value) || 0;
                const chu = v < 0 ? nhanVat(v) : '%';
                document.getElementById('pmhVatSuffix').textContent = chu;
                $vatPercent.closest('.pmh-donvi').classList.toggle('is-rong', chu.length > 1);
            }

            $vatPercent.addEventListener('input', () => { veNhanVat(); veDong(); });
            $vatMode.addEventListener('change', veKieuVat);

            /** Khai theo dòng thì ô thuế của cả phiếu biến mất, đúng như v2. */
            function veKieuVat() {
                document.getElementById('pmhVatPhieuO').style.display =
                    $vatMode.value === 'goods' ? 'none' : '';
                veNhanVat();
                veDong();
            }

            // ---------- Ảnh chứng từ ----------
            // Tải lên ngay lúc chọn, form chỉ mang theo đường dẫn.
            document.getElementById('pmhAnhFile').addEventListener('change', async (e) => {
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
            document.getElementById('pmhAnhGo').addEventListener('click', () => veAnh(''));

            function veAnh(url) {
                $attachment.value = url || '';
                const xem = document.getElementById('pmhAnhXem');
                const go = document.getElementById('pmhAnhGo');
                document.getElementById('pmhAnhNutChu').textContent = url ? 'Đổi ảnh' : 'Chọn ảnh';
                xem.style.display = url ? '' : 'none';
                go.style.display = url ? '' : 'none';
                if (url) xem.href = url;
            }

            // ---------- Nâng cao: xuất / tải mẫu / nhập lưới hàng ----------
            const COT_DONG = ['Mã hàng hóa', 'Tên hàng hóa', 'Số lượng', 'Giá nhập', '% VAT', 'Số lô', 'Hạn dùng'];

            /** Tải một chuỗi xuống máy dưới dạng CSV có BOM để Excel đọc đúng dấu. */
            function taiCSV(ten, dong) {
                const noi = '﻿' + dong.map((h) => h.map((o) => {
                    const v = String(o ?? '');
                    return /[",\n]/.test(v) ? '"' + v.replace(/"/g, '""') + '"' : v;
                }).join(',')).join('\r\n');
                const a = document.createElement('a');
                a.href = URL.createObjectURL(new Blob([noi], { type: 'text/csv;charset=utf-8' }));
                a.download = ten;
                a.click();
                setTimeout(() => URL.revokeObjectURL(a.href), 1000);
            }

            document.getElementById('pmhLineExport').addEventListener('click', () => {
                dongDropdown();
                if (!DONG.length) { toastErr('Phiếu chưa có dòng hàng nào để xuất.'); return; }
                taiCSV('dong-hang-phieu-mua.csv', [COT_DONG].concat(DONG.map((d) => [
                    d.sku, [d.product_name, d.variant_name].filter(Boolean).join(' · '),
                    d.quantity, d.unit_cost, vatCuaDong(d), d.lot_number, d.expire_date,
                ])));
            });

            document.getElementById('pmhLineSample').addEventListener('click', () => {
                dongDropdown();
                taiCSV('mau-dong-hang-phieu-mua.csv', [
                    COT_DONG,
                    ['SP001', 'Ghi tên cho dễ đọc, hệ thống tra theo mã', 2, 240000, 8, 'L2026-08', '2027-08-22'],
                    ['SP002', '', 10, 15000, 0, '', ''],
                ]);
            });

            document.getElementById('pmhLineImport').addEventListener('click', () => {
                dongDropdown();
                document.getElementById('pmhLineFile').click();
            });

            /**
             * Nhập lưới hàng từ CSV.
             *
             * Tra hàng theo MÃ chứ không theo tên: tên gõ trong file là để người
             * đọc, còn thứ đi vào phiếu phải là món có thật trong danh mục. Mã
             * không tra ra thì báo đúng dòng đó, không im lặng bỏ qua.
             */
            document.getElementById('pmhLineFile').addEventListener('change', async (e) => {
                const file = e.target.files[0];
                e.target.value = '';
                if (!file) return;

                $importError.style.display = 'none';
                const van = await file.text();
                const dong = van.replace(/^﻿/, '').split(/\r?\n/).filter((d) => d.trim() !== '');
                if (dong.length < 2) { baoLoiNhap('File không có dòng dữ liệu nào.'); return; }

                const loi = [];
                const them = [];
                for (let i = 1; i < dong.length; i++) {
                    const o = tachCSV(dong[i]);
                    const ma = (o[0] || '').trim();
                    const sl = soN(o[2]);
                    if (ma === '') { loi.push(`Dòng ${i + 1}: chưa có mã hàng hóa.`); continue; }
                    if (sl <= 0) { loi.push(`Dòng ${i + 1}: số lượng phải lớn hơn 0.`); continue; }
                    them.push({ ma, sl, gia: soN(o[3]), vat: Number(o[4]) || 0, lo: (o[5] || '').trim(), han: (o[6] || '').trim() });
                }
                if (loi.length) { baoLoiNhap(loi.slice(0, 8).join(' ')); return; }

                const timThay = await Promise.all(them.map((t) =>
                    fetch(`${URL_MAT_HANG}?keyword=${encodeURIComponent(t.ma)}`, { headers: { Accept: 'application/json' } })
                        .then((r) => r.json())
                        .then((j) => (j.data || []).find((m) => String(m.sku).toLowerCase() === t.ma.toLowerCase()))
                        .catch(() => null)
                ));

                const khong = [];
                them.forEach((t, i) => {
                    const mh = timThay[i];
                    if (!mh) { khong.push(t.ma); return; }
                    themDong(mh, {
                        unit_id: mh.base_unit_id || 0, quantity: t.sl, unit_cost: t.gia,
                        vat_percent: t.vat, lot_number: t.lo, expire_date: t.han,
                    });
                });

                if (khong.length) {
                    baoLoiNhap('Không tìm thấy mã hàng: ' + khong.join(', ') + '. Các dòng còn lại đã thêm vào phiếu.');
                }
            });

            function baoLoiNhap(cau) {
                $importError.textContent = cau;
                $importError.style.display = '';
            }

            /** Tách một dòng CSV, hiểu cả ô bọc trong dấu nháy kép. */
            function tachCSV(dong) {
                const o = [];
                let cur = '';
                let trongNhay = false;
                for (let i = 0; i < dong.length; i++) {
                    const c = dong[i];
                    if (trongNhay) {
                        if (c === '"' && dong[i + 1] === '"') { cur += '"'; i++; }
                        else if (c === '"') trongNhay = false;
                        else cur += c;
                    } else if (c === '"') trongNhay = true;
                    else if (c === ',') { o.push(cur); cur = ''; }
                    else cur += c;
                }
                o.push(cur);
                return o;
            }

            // ---------- Thêm nhà cung cấp ngay trong hộp lập phiếu ----------
            //
            // Hộp thoại là partials/modal-nha-cung-cap — cùng một tệp với trang
            // Nhà cung cấp. Ở đây chỉ chặn lượt gửi đi và đổi đích: gọi API rồi
            // nhét bên vừa khai vào ô chọn, thay vì quay về danh sách.
            const $nccForm = document.getElementById('nccForm');
            const $nccOverlay = document.getElementById('nccFormOverlay');
            const $nccAnh = document.getElementById('nccAnh');
            const $nccTT = document.getElementById('nccTrangThaiValue');

            /** Vẽ ảnh đã tải lên vào khung xem trước. */
            function veAnhNCC(url) {
                $nccAnh.value = url || '';
                const xem = document.getElementById('nccAnhXem');
                const chu = document.getElementById('nccAnhChu');
                const go = document.getElementById('nccAnhGo');
                if (url) {
                    xem.src = url; xem.style.display = ''; chu.style.display = 'none'; go.style.display = '';
                } else {
                    xem.removeAttribute('src'); xem.style.display = 'none'; chu.style.display = ''; go.style.display = 'none';
                }
            }

            /** Công tắc trạng thái: giá trị gửi đi nằm ở ô ẩn, nút chỉ là mặt ngoài. */
            function veTrangThaiNCC(bat) {
                if (bat !== undefined) $nccTT.value = bat ? '1' : '0';
                const on = $nccTT.value === '1';
                const nut = document.getElementById('nccTrangThai');
                nut.classList.toggle('on', on);
                nut.setAttribute('aria-pressed', on ? 'true' : 'false');
                document.getElementById('nccTrangThaiChu').textContent = on ? 'Đang hợp tác' : 'Ngừng hợp tác';
            }

            document.getElementById('nccTrangThai').addEventListener('click', () => veTrangThaiNCC($nccTT.value !== '1'));
            document.getElementById('nccAnhGo').addEventListener('click', () => veAnhNCC(''));
            document.getElementById('nccAnhFile').addEventListener('change', async (e) => {
                const file = e.target.files[0];
                if (!file) return;
                const fd = new FormData();
                fd.append('anh', file);
                fd.append('_token', CSRF);
                try {
                    const res = await fetch(URL_NCC_ANH, { method: 'POST', body: fd });
                    const data = await res.json();
                    if (!res.ok || !data.url) throw new Error(data.message || 'Tải ảnh không thành công.');
                    veAnhNCC(data.url);
                } catch (err) {
                    toastErr(err.message || 'Tải ảnh không thành công.');
                }
                e.target.value = '';
            });

            document.getElementById('pmhNCCAdd').addEventListener('click', () => {
                $nccForm.reset();
                document.getElementById('nccId').value = '';
                veAnhNCC('');
                veTrangThaiNCC(true);
                $nccOverlay.style.display = 'flex';
                setTimeout(() => document.getElementById('nccTen').focus(), 30);
            });

            $nccForm.addEventListener('submit', async (e) => {
                e.preventDefault();

                const than = {};
                new FormData($nccForm).forEach((v, k) => {
                    if (k !== '_token' && k !== '_method' && k !== 'return' && k !== 'id') than[k] = v;
                });

                const nut = document.getElementById('nccFormSubmit');
                nut.disabled = true;
                try {
                    const res = await fetch(URL_NCC_NHANH, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': CSRF },
                        body: JSON.stringify(than),
                    });
                    const data = await res.json();
                    if (!res.ok) throw new Error(loiDauTien(data) || 'Thêm nhà cung cấp không thành công.');

                    // Nhét thẳng vào ô chọn và chọn luôn: người dùng vừa khai bên
                    // bán này là để dùng cho đúng phiếu đang gõ dở.
                    const ncc = data.data || {};
                    const opt = document.createElement('option');
                    opt.value = ncc.id;
                    opt.textContent = (ncc.code ? ncc.code + ' - ' : '') + (ncc.name || '');
                    opt.dataset.name = ncc.name || '';
                    opt.dataset.phone = ncc.phone || '';
                    opt.dataset.address = ncc.address || '';
                    opt.dataset.address2 = ncc.address_line2 || '';
                    opt.dataset.repPhone = ncc.representative_phone || '';
                    O.supplier_id.appendChild(opt);
                    O.supplier_id.value = String(ncc.id);
                    veHoSoNCC();
                    closeAll();
                } catch (err) {
                    toastErr(err.message || 'Thêm nhà cung cấp không thành công.');
                } finally {
                    nut.disabled = false;
                }
            });

            /** Lỗi theo từng ô của Laravel: lấy câu đầu tiên cho dễ đọc. */
            function loiDauTien(data) {
                if (data.message) return data.message;
                const o = data.errors;
                if (o && typeof o === 'object') {
                    const dau = Object.values(o)[0];
                    return Array.isArray(dau) ? dau[0] : dau;
                }

                return '';
            }

            // ---------- Mở hộp thoại ----------

            /** mode: 'add' | 'edit'. `p` là phiếu API trả về khi sửa. */
            function moForm(mode, p) {
                const sua = mode === 'edit';
                $form.action = sua ? `${URL_BASE}/${p.id}` : URL_STORE;
                document.getElementById('pmhFormMethod').value = sua ? 'PUT' : 'POST';
                document.getElementById('pmhId').value = sua ? (p.id || '') : '';
                document.getElementById('pmhFormTitle').textContent = sua
                    ? `Sửa phiếu ${p.po_code || ''}`.trim()
                    : 'Lập phiếu mua hàng';

                const d = p || {};
                O.supplier_id.value = String(d.supplier_id || 0);
                O.document_date.value = (d.document_date || '').slice(0, 10);
                O.expected_date.value = (d.expected_date || '').slice(0, 10);
                O.purchaser_id.value = String(d.purchaser_id || 0);
                O.supplier_delivery_code.value = d.supplier_delivery_code || '';
                O.note.value = d.note || '';
                veHoSoNCC();
                // Phiếu cũ giữ NGUYÊN tên bên bán đã chụp, kể cả khi danh mục đã đổi tên.
                if (sua) X.tenNCC.value = d.supplier_name || X.tenNCC.value;

                X.ngayLap.value = sua ? ngay(d.created_at) : new Date().toLocaleDateString('vi-VN');
                X.trangThai.value = sua ? (NHAN_TRANG_THAI[d.status] || 'Lưu tạm') : 'Tạo mới';

                $vatMode.value = d.vat_mode || 'order';
                $vatPercent.value = String(d.vat_percent ?? 0);
                veKieuVat();
                veAnh(d.attachment || '');
                $importError.style.display = 'none';
                $nhomHang.value = '0';

                DONG = [];
                dongSeq = 0;
                pickRows = [];
                pickInput.value = '';

                if (sua && Array.isArray(d.items)) {
                    // Dòng cũ dựng lại từ chính phiếu: hộp thoại phải mở ra đúng
                    // như phiếu đang lưu, kể cả khi hàng hóa đã đổi tên sau đó.
                    d.items.forEach((it) => {
                        const ratio = Number(it.unit_ratio || 1);
                        DONG.push({
                            key: ++dongSeq,
                            variant_id: Number(it.product_variant_id || 0),
                            product_name: it.product_name || '',
                            variant_name: it.variant_name || '',
                            sku: it.variant_sku || '',
                            base_unit_name: '',
                            units: [{ unit_id: Number(it.unit_id || 0), name: it.unit_name || 'Đơn vị chính', ratio }],
                            unit_id: Number(it.unit_id || 0),
                            ratio,
                            quantity: Number(it.quantity || 0),
                            unit_cost: Number(it.unit_cost || 0),
                            vat_hang: Number(it.vat_percent || 0),
                            vat_percent: Number(it.vat_percent || 0),
                            lot_number: it.lot_number || '',
                            expire_date: (it.expire_date || '').slice(0, 10),
                        });
                    });
                }

                veDong();
                $formOverlay.style.display = 'flex';
                setTimeout(() => (DONG.length ? pickInput : O.supplier_id).focus(), 30);

                if (sua && DONG.length) napDonViChoDongCu();
            }

            /**
             * Dòng dựng lại từ phiếu cũ chỉ mang ĐÚNG đơn vị đã lưu, nên ô chọn
             * đơn vị chỉ có một lựa chọn — sửa phiếu mà không đổi được từ Thùng
             * sang Cái thì coi như không sửa được.
             *
             * Hỏi lại đúng đường ô tìm hàng vẫn dùng, theo mã hàng, rồi ghép danh
             * sách đơn vị vào. Hỏng thì im lặng bỏ qua: phiếu vẫn sửa được mọi
             * thứ khác, chỉ là ô đơn vị đứng yên.
             */
            async function napDonViChoDongCu() {
                const sku = [...new Set(DONG.map((d) => d.sku).filter(Boolean))];
                if (!sku.length) return;

                try {
                    const ds = await Promise.all(sku.map((s) =>
                        fetch(`${URL_MAT_HANG}?keyword=${encodeURIComponent(s)}`, { headers: { Accept: 'application/json' } })
                            .then((r) => r.json())
                            .then((j) => j.data || [])
                            .catch(() => [])
                    ));

                    const theoBienThe = new Map();
                    ds.flat().forEach((mh) => theoBienThe.set(Number(mh.variant_id), mh));

                    let doi = false;
                    DONG.forEach((d) => {
                        const mh = theoBienThe.get(Number(d.variant_id));
                        if (!mh || !Array.isArray(mh.units) || !mh.units.length) return;
                        d.units = mh.units;
                        d.base_unit_name = mh.base_unit_name || '';
                        d.vat_hang = Number(mh.vat_percent || 0);
                        doi = true;
                    });
                    if (doi) veDong();
                } catch (e) { /* giữ nguyên ô đơn vị một lựa chọn */ }
            }

            document.getElementById('pmhAddBtn').addEventListener('click', () => moForm('add', null));

            // Nút nào bấm thì phiếu đi đường đó: Lưu tạm chỉ ghi, Duyệt thì ghi
            // xong gọi tiếp đường duyệt (xem PhieuMuaHangController::store).
            document.getElementById('pmhSaveDraft').addEventListener('click', () => {
                document.getElementById('pmhDuyet').value = '0';
            });
            document.getElementById('pmhSaveApprove').addEventListener('click', () => {
                document.getElementById('pmhDuyet').value = '1';
            });

            $form.addEventListener('submit', (e) => {
                const hopLe = DONG.filter((d) => d.quantity > 0);
                if (!hopLe.length) {
                    e.preventDefault();
                    toastErr('Phiếu chưa có dòng hàng nào có số lượng.');
                    return;
                }
                const le = hopLe.find((d) => Math.abs(d.quantity * d.ratio - Math.round(d.quantity * d.ratio)) > 0.0001);
                if (le) {
                    e.preventDefault();
                    toastErr(`"${le.product_name}" quy đổi ra số lẻ — kho chỉ nhận số nguyên. Đổi số lượng hoặc chọn đơn vị khác.`);
                    return;
                }

                document.getElementById('pmhItems').value = JSON.stringify(hopLe.map((d) => ({
                    variant_id: d.variant_id,
                    unit_id: d.unit_id,
                    quantity: d.quantity,
                    unit_cost: d.unit_cost,
                    vat_percent: $vatMode.value === 'goods' ? d.vat_percent : 0,
                    lot_number: d.lot_number,
                    expire_date: d.expire_date,
                })));
                document.getElementById('pmhItemsMeta').value = JSON.stringify(hopLe);
            });

            // =================================================================
            //  Hộp thoại Chi tiết
            // =================================================================
            const $detail = document.getElementById('pmhDetailOverlay');
            const $detailBody = document.getElementById('pmhDetailBody');
            const $detailFoot = document.getElementById('pmhDetailFoot');

            const o = (nhan, giaTri, full) =>
                `<div class="pmh-cell${full ? ' is-full' : ''}"><span class="pmh-lb">${esc(nhan)}</span>`
                + `<span class="pmh-vl">${esc(giaTri || '—')}</span></div>`;

            async function xemChiTiet(id) {
                $detailBody.innerHTML = '<p class="pmh-lines-empty">Đang đọc phiếu…</p>';
                $detailFoot.innerHTML = '<button type="button" class="pmh-btn-ghost" data-close>Đóng</button>';
                $detailFoot.querySelector('[data-close]').addEventListener('click', closeAll);
                $detail.style.display = 'flex';

                let p = null;
                try {
                    const res = await fetch(`${URL_BASE}/${id}`, { headers: { Accept: 'application/json' } });
                    const data = await res.json();
                    if (!res.ok) throw new Error(data.message || 'Không đọc được phiếu.');
                    p = data.data;
                } catch (err) {
                    $detailBody.innerHTML = `<p class="pmh-lines-empty">${esc(err.message || 'Không đọc được phiếu.')}</p>`;
                    return;
                }

                veChiTiet(p);
            }

            function veChiTiet(p) {
                const tong = Number(p.total_amount || 0);
                const daTra = Number(p.paid_amount || 0);
                const conNo = Math.max(0, tong - daTra);
                const tt = p.status || 'draft';
                const ttTra = p.payment_status || 'unpaid';

                document.getElementById('pmhDetailTitle').textContent = 'Phiếu ' + (p.po_code || '');

                const dong = (p.items || []).map((it, i) => {
                    const ten = [it.product_name, it.variant_name].filter(Boolean).join(' · ');
                    const quyDoi = Number(it.unit_ratio || 1) !== 1
                        ? `<span class="pmh-l-conv">= ${nhomSo(it.base_quantity)} vào kho</span>` : '';
                    return `<tr>
                        <td>${i + 1}</td>
                        <td class="is-left">${esc(ten)}<span class="pmh-l-name-sub">${esc(it.variant_sku || '')}</span></td>
                        <td>${esc(it.unit_name || '—')}</td>
                        <td>${nhomSo(it.quantity)}${quyDoi}</td>
                        <td>${money(it.unit_cost)}</td>
                        <td>${esc(nhanVat(Number(it.vat_percent || 0)))}</td>
                        <td><b>${money(it.total_cost)}</b></td>
                        <td>${esc(it.lot_number || '—')}</td>
                        <td>${ngay(it.expire_date)}</td>
                    </tr>`;
                }).join('');

                const lichSu = (p.histories || []).map((h) => `<div class="pmh-his-row">
                    <span class="pmh-his-time">${esc(gioNgay(h.created_at))}</span>
                    <span>${esc(h.note || (NHAN_TRANG_THAI[h.to_status] || h.to_status))}</span>
                </div>`).join('');

                $detailBody.innerHTML = `
                    <div class="pmh-view-grid">
                        ${o('Mã phiếu', p.po_code)}
                        ${o('Nhà cung cấp', p.supplier_name || 'Bên bán vãng lai')}
                        ${o('Ngày chứng từ', ngay(p.document_date))}
                        ${o('Hẹn giao', ngay(p.expected_date))}
                        <div class="pmh-cell"><span class="pmh-lb">Trạng thái</span>
                            <span class="pmh-vl"><span class="pmh-chip is-${MAU_TRANG_THAI[tt] || 'off'}">${esc(NHAN_TRANG_THAI[tt] || tt)}</span></span></div>
                        <div class="pmh-cell"><span class="pmh-lb">Thanh toán</span>
                            <span class="pmh-vl"><span class="pmh-chip is-${MAU_TRA[ttTra] || 'off'}">${esc(NHAN_TRA[ttTra] || ttTra)}</span></span></div>
                        ${o('Số phiếu giao của NCC', p.supplier_delivery_code)}
                        ${o('Ngày lập', gioNgay(p.created_at))}
                        ${p.cancel_reason ? o('Lý do huỷ', p.cancel_reason, true) : ''}
                        ${p.note ? o('Ghi chú', p.note, true) : ''}
                    </div>

                    <table class="pmh-view-table">
                        <thead><tr>
                            <th>#</th><th>Hàng hóa</th><th>Đơn vị tính</th><th>Số lượng</th>
                            <th>Giá nhập</th><th>% VAT</th><th>Tổng tiền sau VAT</th>
                            <th>Số lô</th><th>Hạn dùng</th>
                        </tr></thead>
                        <tbody>${dong || '<tr><td colspan="9" class="pmh-lines-empty">Phiếu không có dòng hàng nào.</td></tr>'}</tbody>
                    </table>

                    <div class="pmh-money">
                        <div class="pmh-money-row"><span class="pmh-money-lb">Tiền hàng</span><span class="pmh-money-vl">${money(p.items_amount)}</span></div>
                        <div class="pmh-money-row"><span class="pmh-money-lb">Chiết khấu</span><span class="pmh-money-vl">${money(p.discount_amount)}</span></div>
                        <div class="pmh-money-row"><span class="pmh-money-lb">Thuế GTGT</span><span class="pmh-money-vl">${money(p.vat_amount)}</span></div>
                        <div class="pmh-money-row is-total"><span class="pmh-money-lb">Tổng cộng</span><span class="pmh-money-vl">${money(tong)}</span></div>
                        <div class="pmh-money-row"><span class="pmh-money-lb">Đã trả</span><span class="pmh-money-vl">${money(daTra)}</span></div>
                        <div class="pmh-money-row"><span class="pmh-money-lb">Còn nợ</span><span class="pmh-money-vl pmh-no">${money(conNo)}</span></div>
                    </div>

                    ${lichSu ? `<div><p class="pmh-sec-title">Lịch sử phiếu</p><div class="pmh-his">${lichSu}</div></div>` : ''}
                    ${p.attachment ? `<p class="pmh-note-box">Ảnh chứng từ bên bán:
                        <a href="${esc(p.attachment)}" target="_blank" rel="noopener">mở ảnh</a></p>` : ''}
                `;

                // Nút chân hộp dựng theo cờ API trả về, không tự đoán từ trạng thái.
                const nut = ['<button type="button" class="pmh-btn-ghost" data-close>Đóng</button>'];
                if (p.can_edit) {
                    nut.push(`<button type="button" class="pmh-btn-danger" data-huy="${p.id}">Huỷ phiếu</button>`);
                    nut.push(`<button type="button" class="pmh-btn-ghost" data-sua="${p.id}">Sửa</button>`);
                }
                if (p.can_approve) {
                    nut.push(`<button type="button" class="pmh-btn-primary" data-duyet="${p.id}">Duyệt &amp; nhập kho</button>`);
                }
                if (p.can_pay) {
                    nut.push(`<button type="button" class="pmh-btn-primary" data-tra="${p.id}">Ghi nhận thanh toán</button>`);
                }
                $detailFoot.innerHTML = nut.join('');
                $detailFoot.querySelectorAll('[data-close]').forEach((b) => b.addEventListener('click', closeAll));
                $detailFoot.querySelector('[data-sua]')?.addEventListener('click', () => {
                    closeAll();
                    moForm('edit', p);
                });
                $detailFoot.querySelector('[data-duyet]')?.addEventListener('click', () => duyet(p));
                $detailFoot.querySelector('[data-huy]')?.addEventListener('click', () => moHuy(p));
                $detailFoot.querySelector('[data-tra]')?.addEventListener('click', () => moTra(p));
            }

            // ---------- Duyệt / huỷ / trả tiền ----------
            function duyet(p) {
                const ten = p.po_code || ('#' + p.id);
                window.sysConfirm({
                    title: 'Duyệt phiếu và nhập kho',
                    message: `Duyệt phiếu ${ten}? Hàng vào kho ngay lúc này và phiếu khoá lại — `
                        + 'sau đó muốn chữa số đã vào kho thì phải cân đối ở màn Tồn kho.',
                    confirmText: 'Duyệt & nhập kho',
                }).then((ok) => { if (ok) postForm(`${URL_BASE}/${p.id}/approve`, 'POST', {}); });
            }

            function moTra(p) {
                const tong = Number(p.total_amount || 0);
                const daTra = Number(p.paid_amount || 0);
                document.getElementById('pmhPayForm').action = `${URL_BASE}/${p.id}/payment`;
                document.getElementById('pmhPayInfo').textContent =
                    `Phiếu ${p.po_code || ''} · tổng ${money(tong)} · đã trả ${money(daTra)} · còn nợ ${money(Math.max(0, tong - daTra))}.`;
                document.getElementById('pmhPayAmount').value = nhomSo(tong);
                document.getElementById('pmhPayNote').value = '';
                closeAll();
                document.getElementById('pmhPayOverlay').style.display = 'flex';
            }

            function moHuy(p) {
                document.getElementById('pmhCancelForm').action = `${URL_BASE}/${p.id}/cancel`;
                document.getElementById('pmhCancelInfo').textContent =
                    `Phiếu ${p.po_code || ''} đang ở trạng thái lưu tạm nên chưa đụng tới kho. Huỷ xong phiếu vẫn nằm lại trong sổ để tra cứu.`;
                document.getElementById('pmhCancelNote').value = '';
                closeAll();
                document.getElementById('pmhCancelOverlay').style.display = 'flex';
            }

            const $payAmount = document.getElementById('pmhPayAmount');
            $payAmount.addEventListener('input', () => {
                const phai = viTriTuPhai($payAmount);
                $payAmount.value = nhomSo(soN($payAmount.value));
                datConTro($payAmount, phai);
            });
            document.getElementById('pmhPayForm').addEventListener('submit', () => {
                $payAmount.value = String(soN($payAmount.value));
            });

            // =================================================================
            //  Sự kiện bảng
            // =================================================================
            const tbody = document.querySelector('.pmh-table tbody');
            tbody.addEventListener('click', (e) => {
                if (e.target.closest('a') || e.target.closest('input')) return;

                const rm = e.target.closest('[data-remove]');
                if (rm) { xoa(BY_ID.get(Number(rm.dataset.remove))); return; }
                const ap = e.target.closest('[data-approve]');
                if (ap) { duyet(BY_ID.get(Number(ap.dataset.approve))); return; }
                const pay = e.target.closest('[data-pay]');
                if (pay) { moTra(BY_ID.get(Number(pay.dataset.pay))); return; }
                const ed = e.target.closest('[data-edit]');
                if (ed) { moSua(Number(ed.dataset.edit)); return; }
                const dt = e.target.closest('[data-detail]');
                if (dt) { xemChiTiet(Number(dt.dataset.detail)); }
            });

            /** Sửa phải đọc lại phiếu: dòng hàng không nằm trong bảng danh sách. */
            async function moSua(id) {
                try {
                    const res = await fetch(`${URL_BASE}/${id}`, { headers: { Accept: 'application/json' } });
                    const data = await res.json();
                    if (!res.ok) throw new Error(data.message || 'Không đọc được phiếu.');
                    moForm('edit', data.data);
                } catch (err) {
                    toastErr(err.message || 'Không đọc được phiếu.');
                }
            }

            function xoa(p) {
                if (!p) return;
                window.sysDelete({
                    title: 'Xác nhận xoá phiếu mua hàng',
                    message: `Xoá phiếu ${p.po_code || ''}? Chỉ phiếu lưu tạm mới xoá được — `
                        + 'phiếu đã duyệt nằm lại trong sổ vì kho đã đổi theo nó.',
                    highlightText: `${p.po_code || ''} — ${p.supplier_name || ''}`,
                }).then((ok) => { if (ok) postForm(`${URL_BASE}/${p.id}`, 'DELETE', {}); });
            }

            // ---------- Chọn dòng + thanh hàng loạt ----------
            const chon = new Set();
            const $bulk = document.getElementById('pmhBulkMount');
            const checkAll = document.getElementById('pmhCheckAll');
            const rowChecks = () => Array.from(document.querySelectorAll('.pmh-row-check'));

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
                const nhap = [...chon].filter((id) => (BY_ID.get(id)?.status) === 'draft').length;

                $bulk.innerHTML = `
                    <div class="pmh-bulk">
                        <span class="pmh-bulk-count">Đã chọn <b>${n}</b> phiếu</span>
                        <button type="button" class="pmh-bulk-clear" id="pmhBulkClear">Bỏ chọn</button>
                        <div class="pmh-bulk-actions">
                            ${nhap > 0 ? `<button type="button" class="pmh-btn-ghost" data-bulk="approve">Duyệt &amp; nhập kho (${nhap})</button>` : ''}
                            ${nhap > 0 ? `<button type="button" class="pmh-btn-danger" data-bulk="del">Xoá (${nhap})</button>` : ''}
                            ${nhap === 0 ? '<span class="pmh-bulk-count pmh-muted">Phiếu đã duyệt hoặc đã huỷ không sửa được nữa</span>' : ''}
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
                if (e.target.closest('#pmhBulkClear')) {
                    chon.clear();
                    rowChecks().forEach((cb) => { cb.checked = false; cb.closest('tr').classList.remove('is-selected'); });
                    syncHeader(); veBulk();
                    return;
                }

                const nut = e.target.closest('[data-bulk]');
                if (!nut) return;
                // Chỉ gửi phiếu lưu tạm: phiếu khác API từ chối, gửi lên chỉ để
                // nhận về một câu "3 phiếu không duyệt được" vô nghĩa.
                const ids = [...chon].filter((id) => (BY_ID.get(id)?.status) === 'draft');
                if (!ids.length) return;

                if (nut.dataset.bulk === 'del') {
                    window.sysDelete({
                        title: 'Xác nhận xoá hàng loạt',
                        message: `Xoá ${ids.length} phiếu lưu tạm đã chọn?`,
                        highlightText: `Số lượng: ${ids.length} phiếu`,
                    }).then((ok) => { if (ok) postForm(URL_BULK_DEL, 'POST', idFields(ids)); });
                    return;
                }

                window.sysConfirm({
                    title: 'Duyệt hàng loạt',
                    message: `Duyệt ${ids.length} phiếu đã chọn? Hàng của cả ${ids.length} phiếu vào kho ngay lúc này `
                        + 'và các phiếu đó khoá lại.',
                    confirmText: 'Duyệt & nhập kho',
                }).then((ok) => { if (ok) postForm(URL_BULK_APPROVE, 'POST', idFields(ids)); });
            });

            // ---------- Hai dropdown: Tiện ích và Xem cột ----------
            const dropdowns = [
                { box: document.getElementById('pmhUtil'), btn: document.getElementById('pmhUtilBtn') },
                { box: document.getElementById('pmhCotBox'), btn: document.getElementById('pmhCotBtn') },
                { box: document.getElementById('pmhNangCao'), btn: document.getElementById('pmhNangCaoBtn') },
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
                d.box.querySelector('.pmh-util-menu').addEventListener('click', (e) => e.stopPropagation());
            });
            document.addEventListener('click', dongDropdown);
            document.getElementById('pmhPrintBtn').addEventListener('click', () => { dongDropdown(); window.print(); });

            // ---------- Xem cột ----------
            const COT_KEY = 'pmh-cot-an';
            const $cotCss = document.getElementById('pmhCotCss');
            const $cotCbs = Array.from(document.querySelectorAll('.pmh-cot-cb'));
            const $cotAll = document.getElementById('pmhCotAll');

            function veCot() {
                const an = $cotCbs.filter((cb) => !cb.checked).map((cb) => cb.dataset.cot);
                $cotCss.textContent = an.length
                    ? an.map((c) => `.pmh-table .pmh-c-${c}`).join(',') + '{display:none}'
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

            // Lưu hỏng thì mở lại hộp thoại kèm ĐÚNG những gì vừa gõ, cả lưới
            // hàng. Bắt người ta chọn lại từng dòng chỉ vì server từ chối một ô
            // là cách nhanh nhất để họ bỏ luôn phiếu đang lập.
            @if(old('items'))
                (function () {
                    const cu = @json(old());
                    toastErr(@json(session('error') ?: 'Phiếu chưa lưu được — dữ liệu vừa nhập vẫn còn trong hộp thoại.'));

                    // Đang SỬA thì mở lại đúng ở chế độ sửa. Mở ở chế độ thêm là
                    // lượt gửi sau đẻ ra phiếu nháp thứ hai, còn phiếu gốc thì
                    // vẫn nguyên như cũ.
                    const id = Number(cu.id) || 0;
                    moForm(id ? 'edit' : 'add', id ? { id } : null);

                    O.supplier_id.value = String(cu.supplier_id || 0);
                    O.document_date.value = cu.document_date || '';
                    O.expected_date.value = cu.expected_date || '';
                    O.purchaser_id.value = String(cu.purchaser_id || 0);
                    O.supplier_delivery_code.value = cu.supplier_delivery_code || '';
                    O.note.value = cu.note || '';
                    veHoSoNCC();
                    $vatMode.value = cu.vat_mode || 'order';
                    $vatPercent.value = String(cu.vat_percent ?? 0);
                    veKieuVat();
                    veAnh(cu.attachment || '');

                    try {
                        const dong = JSON.parse(cu.items_meta || '[]');
                        if (Array.isArray(dong) && dong.length) {
                            DONG = dong.map((d) => ({ ...d, key: ++dongSeq }));
                        }
                    } catch (e) { /* mất lưới hàng còn hơn mất cả trang */ }
                    veDong();
                })();
            @endif
        })();
    </script>
@endsection
