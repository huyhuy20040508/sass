@extends('layouts.app')

@section('title', \App\Http\Controllers\TraHangNhaCungCapController::TITLE_PAGE)

@section('content')
    {{-- Tên ô của form = tên trường bên API nên controller gửi thẳng payload đi. --}}
    @php
        $C = \App\Http\Controllers\TraHangNhaCungCapController::class;
        $TITLE = $C::TITLE_PAGE;
        $EMPTY_TEXT = $C::EMPTY_TEXT;

        $so = fn ($n) => number_format((float) $n, 0, ',', '.');
        $tien = fn ($n) => ((float) $n) != 0.0 ? number_format((float) $n, 0, ',', '.').'₫' : '—';
        $ngay = function ($v) {
            $t = $v ? strtotime($v) : false;
            return $t ? date('d/m/Y', $t) : '—';
        };

        $trangThaiChon = array_filter(explode(',', $filters['status']));

        // Lọc phụ nằm trong "Nâng cao"; đang bật thì hàng đó tự mở kèm con số.
        $advCount = count($trangThaiChon)
            + ($filters['from_date'] !== '' || $filters['to_date'] !== '' ? 1 : 0)
            + ($filters['sort'] !== 'newest' ? 1 : 0);
        $advOpen = $advCount > 0;
        $hasFilter = $advCount > 0 || $filters['keyword'] !== '' || $filters['supplier_id'] > 0;

        $stt = ($meta['page'] - 1) * $meta['page_size'];
    @endphp

    <div class="thn">
        <div class="thn-head">
            <h1 class="thn-title">{{ $TITLE }}</h1>
            <span class="thn-sum">
                Lưu tạm: <b>{{ $so($thongKe['draft']) }}</b> ·
                Đã duyệt: <b>{{ $so($thongKe['approved']) }}</b> ·
                Đã trả lại: <b class="thn-tra">{{ $tien($thongKe['returned_amount']) }}</b>
                <em>(chưa tính phiếu lưu tạm)</em>
            </span>
        </div>

        @if(!empty($error))
            <p class="thn-callout is-error">{{ $error }}</p>
        @endif

        {{-- Lọc realtime: đổi ô tick hay select chạy ngay, gõ thì chờ 400ms. --}}
        <form method="GET" action="{{ route('admin.tra-hang-nha-cung-cap.index') }}" id="thnFilter" class="thn-filter">
            <div class="thn-toolbar">
                <div class="thn-searchbox">
                    <input type="text" name="keyword" value="{{ $filters['keyword'] }}" class="thn-search-input"
                           placeholder="Tìm theo mã phiếu, mã hoặc tên nhà cung cấp" autocomplete="off">
                    <button type="submit" class="thn-search-btn" title="Tìm kiếm">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                    </button>
                </div>

                <select name="supplier_id" class="thn-select" title="Lọc theo nhà cung cấp">
                    <option value="">Mọi nhà cung cấp</option>
                    @foreach($nhaCungCap as $ncc)
                        <option value="{{ $ncc['id'] }}" @selected($filters['supplier_id'] === (int) $ncc['id'])>
                            {{ $ncc['name'] ?? '' }}
                        </option>
                    @endforeach
                </select>

                <button type="button" id="thnAdvToggle" class="thn-adv-btn {{ $advOpen ? 'is-open' : '' }}"
                        aria-expanded="{{ $advOpen ? 'true' : 'false' }}">
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M7 12h10M11 18h2"/></svg>
                    Nâng cao
                    <svg class="thn-adv-caret" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                    @if($advCount > 0)<span class="thn-adv-count">{{ $advCount }}</span>@endif
                </button>

                @if($hasFilter)
                    <a href="{{ route('admin.tra-hang-nha-cung-cap.index') }}" class="thn-clear">Xoá lọc</a>
                @endif

                <div class="thn-toolbar-actions">
                    <button type="button" class="thn-btn-primary" id="thnAddBtn">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M12 5v14"/></svg>
                        Lập phiếu trả
                    </button>

                    <div class="thn-util" id="thnUtil">
                        <button type="button" class="thn-util-btn" id="thnUtilBtn" aria-haspopup="true" aria-expanded="false">
                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="1.6"/><circle cx="19" cy="12" r="1.6"/><circle cx="5" cy="12" r="1.6"/></svg>
                            Tiện ích
                            <svg class="thn-util-caret" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                        </button>
                        <div class="thn-util-menu">
                            {{-- Xuất mang theo đúng bộ lọc đang xem. --}}
                            <a href="{{ route('admin.tra-hang-nha-cung-cap.export', request()->query()) }}" class="thn-util-item">
                                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5"/><path d="M12 15V3"/></svg>
                                Xuất file (CSV)
                            </a>
                            <button type="button" class="thn-util-item" id="thnPrintBtn">
                                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><path d="M6 14h12v8H6z"/></svg>
                                In danh sách
                            </button>
                        </div>
                    </div>

                    {{-- Xem cột — lựa chọn lưu ở localStorage. --}}
                    <div class="thn-util" id="thnCotBox">
                        <button type="button" class="thn-util-btn thn-btn-sq" id="thnCotBtn" title="Xem cột" aria-expanded="false">
                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 21v-7M4 10V3M12 21v-9M12 8V3M20 21v-5M20 12V3"/><path d="M1 14h6M9 8h6M17 16h6"/></svg>
                        </button>
                        <div class="thn-util-menu thn-cot-menu">
                            <label class="thn-cot-item is-all">
                                <input type="checkbox" id="thnCotAll" checked>
                                <span>Tất cả</span>
                            </label>
                            <div class="thn-cot-line"></div>
                            @foreach($C::COT_BANG as $ma => $ten)
                                <label class="thn-cot-item">
                                    <input type="checkbox" class="thn-cot-cb" data-cot="{{ $ma }}" checked>
                                    <span>{{ $ten }}</span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>

            <div class="thn-toolbar-adv {{ $advOpen ? 'is-open' : '' }}" id="thnAdvRow">
                <div class="thn-tickbox">
                    <span class="thn-tick-label">Trạng thái</span>
                    @foreach($C::TRANG_THAI as $ma => $ten)
                        <label class="thn-tick">
                            <input type="checkbox" name="status[]" value="{{ $ma }}"
                                   @checked(in_array($ma, $trangThaiChon, true))>
                            <span class="thn-chip is-{{ $C::MAU_TRANG_THAI[$ma] }}">{{ $ten }}</span>
                        </label>
                    @endforeach
                </div>

                <div class="thn-tickbox">
                    <span class="thn-tick-label">Ngày lập</span>
                    <input type="date" name="from_date" value="{{ $filters['from_date'] }}" class="thn-select" title="Từ ngày">
                    <span class="thn-dash">→</span>
                    <input type="date" name="to_date" value="{{ $filters['to_date'] }}" class="thn-select" title="Đến ngày">
                </div>

                <select name="sort" class="thn-select" title="Sắp xếp">
                    @foreach($C::SAP_XEP as $ma => $ten)
                        <option value="{{ $ma }}" @selected($filters['sort'] === $ma)>{{ $ten }}</option>
                    @endforeach
                </select>
            </div>

            <input type="hidden" name="page_size" value="{{ $meta['page_size'] }}">
        </form>

        {{-- Bảng chép đúng cột của v2; bề rộng khai theo %, cộng đúng 100%. --}}
        <div class="thn-table-wrap">
            <table class="thn-table">
                <thead>
                    <tr>
                        <th class="thn-c-check"><input type="checkbox" id="thnCheckAll" class="thn-check" title="Chọn hết dòng đang hiện"></th>
                        <th class="thn-c-stt">STT</th>
                        <th class="thn-c-code">Mã phiếu</th>
                        <th class="thn-c-suppliercode">Mã nhà cung cấp</th>
                        <th class="thn-c-supplier">Nhà cung cấp</th>
                        <th class="thn-c-docdate">Ngày chứng từ</th>
                        <th class="thn-c-branch">Chi nhánh</th>
                        <th class="thn-c-items">Tổng tiền hàng</th>
                        <th class="thn-c-total">Tổng tiền (VAT)</th>
                        <th class="thn-c-status">Trạng thái phiếu</th>
                        <th class="thn-c-stock">Trạng thái kho</th>
                        <th class="thn-c-creator">Người lập</th>
                        <th class="thn-c-note">Ghi chú</th>
                        <th class="thn-c-act">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($list as $i => $p)
                        @php
                            $id = (int) ($p['id'] ?? 0);
                            $tt = $p['status'] ?? 'draft';
                            $nhap = $tt === 'draft';
                        @endphp
                        <tr data-id="{{ $id }}" data-status="{{ $tt }}">
                            <td class="thn-c-check">
                                <input type="checkbox" class="thn-check thn-row-check" value="{{ $id }}"
                                       aria-label="Chọn phiếu {{ $p['return_code'] ?? $id }}">
                            </td>
                            <td class="thn-c-stt">{{ $stt + $i + 1 }}</td>
                            <td class="thn-c-code" data-detail="{{ $id }}" title="Bấm để xem chi tiết">
                                <span class="thn-code">{{ ($p['return_code'] ?? '') ?: '—' }}</span>
                            </td>
                            <td class="thn-c-suppliercode">{{ ($p['supplier_code'] ?? '') ?: '—' }}</td>
                            <td class="thn-c-supplier" data-detail="{{ $id }}" title="{{ $p['supplier_name'] ?? '' }}">
                                <span class="thn-name">{{ ($p['supplier_name'] ?? '') ?: '—' }}</span>
                            </td>
                            <td class="thn-c-docdate">{{ $ngay($p['document_date'] ?? null) }}</td>
                            <td class="thn-c-branch">{{ ($p['branch_name'] ?? '') ?: '—' }}</td>
                            <td class="thn-c-items">{{ $tien($p['items_amount'] ?? 0) }}</td>
                            <td class="thn-c-total"><b>{{ $tien($p['total_amount'] ?? 0) }}</b></td>
                            <td class="thn-c-status">
                                <span class="thn-chip is-{{ $C::MAU_TRANG_THAI[$tt] ?? 'off' }}">
                                    {{ $C::TRANG_THAI[$tt] ?? $tt }}
                                </span>
                            </td>
                            <td class="thn-c-stock">
                                <span class="thn-chip is-{{ $C::MAU_TRANG_THAI_KHO[$tt] ?? 'off' }}">
                                    {{ $C::TRANG_THAI_KHO[$tt] ?? '—' }}
                                </span>
                            </td>
                            <td class="thn-c-creator" title="{{ $p['creator_name'] ?? '' }}">{{ ($p['creator_name'] ?? '') ?: '—' }}</td>
                            <td class="thn-c-note" title="{{ $p['note'] ?? '' }}">{{ ($p['note'] ?? '') ?: '—' }}</td>
                            <td class="thn-c-act">
                                <div class="thn-rowacts">
                                    <button type="button" class="thn-rowbtn" data-detail="{{ $id }}" title="Xem chi tiết">
                                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                                    </button>
                                    <button type="button" class="thn-rowbtn" data-print="{{ $id }}" title="In phiếu">
                                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><path d="M6 14h12v8H6z"/></svg>
                                    </button>
                                    {{-- Phiếu đã duyệt khoá lại: kho đã trừ theo nó, sửa hay xoá là sổ kho lệch. --}}
                                    @if($nhap)
                                        <button type="button" class="thn-rowbtn" data-edit="{{ $id }}" title="Sửa phiếu">
                                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                                        </button>
                                        <button type="button" class="thn-rowbtn is-danger" data-remove="{{ $id }}" title="Xoá phiếu">
                                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/></svg>
                                        </button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="14" class="thn-empty">
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

    <div id="thnBulkMount"></div>

    {{-- ------------------------------------------------------------------
         Hộp thoại Lập / Sửa phiếu trả — dựng theo đúng màn của order v2.

           1. Hàng nút Lưu tạm / Duyệt (và In khi sửa) nằm TRÊN CÙNG, canh phải.
           2. Thông tin phiếu — đủ ô của v2, giữ nguyên ô nào khoá ô nào gõ được.
           3. Thông tin hàng hoá: chọn phiếu mua rồi lưới hàng tự đổ ra.
           4. Khối tiền canh phải: trước thuế · tiền thuế · tổng tiền.

         Hàng KHÔNG chọn lẻ được: v2 chỉ cho trả những dòng đã có trên phiếu
         mua, vì số lượng trả phải kẹp theo số đã mua của đúng dòng đó.
    ------------------------------------------------------------------- --}}
    <div class="thn-overlay" id="thnFormOverlay" style="display:none;">
        <div class="thn-dialog thn-dialog-xl">
            <div class="thn-modal-head">
                <h4 class="thn-modal-title" id="thnFormTitle">Lập phiếu trả hàng nhà cung cấp</h4>
                <button type="button" class="thn-modal-x" data-close>
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </div>

            <form id="thnForm" method="POST" action="{{ route('admin.tra-hang-nha-cung-cap.store') }}">
                @csrf
                <input type="hidden" name="_method" id="thnFormMethod" value="POST">
                <input type="hidden" name="return" value="{{ request()->getRequestUri() }}">
                {{-- Nút nào bấm thì ô này nói ra: 1 = duyệt luôn sau khi lưu. --}}
                <input type="hidden" name="duyet" id="thnDuyet" value="0">
                {{-- Lưu hỏng thì lượt mở lại đọc ô này để biết đang sửa phiếu nào. --}}
                <input type="hidden" name="id" id="thnId" value="">
                <input type="hidden" name="items" id="thnItems" value="">
                {{-- Bản đầy đủ của lưới hàng chỉ để dựng lại hộp thoại khi lưu hỏng. --}}
                <input type="hidden" name="items_meta" id="thnItemsMeta" value="">
                {{-- Ô khoá trên màn vẫn phải đi theo phiếu, nên gửi bằng ô ẩn. --}}
                <input type="hidden" name="supplier_name" id="thnTenNCC" value="">
                <input type="hidden" name="branch_id" value="{{ $chiNhanh['id'] }}">
                <input type="hidden" name="document_date" id="thnNgayCTGui" value="">
                <input type="hidden" name="vat_percent" id="thnVatGui" value="0">

                {{-- 1. Hàng nút, trên cùng và canh phải --}}
                <div class="thn-form-bar">
                    <button type="button" class="thn-btn-ghost" id="thnFormPrint" style="display:none;">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><path d="M6 14h12v8H6z"/></svg>
                        In
                    </button>
                    <button type="submit" class="thn-btn-ghost" id="thnSaveDraft">Lưu tạm</button>
                    <button type="submit" class="thn-btn-primary" id="thnSaveApprove">Duyệt</button>
                </div>

                <div class="thn-modal-body">
                    {{-- 2. Thông tin phiếu --}}
                    <div class="thn-info">
                        <div class="thn-info-col">
                            <div class="thn-field">
                                <label class="thn-field-label" for="thnNCC">Nhà cung cấp <span class="thn-req">*</span></label>
                                <select id="thnNCC" name="supplier_id" class="thn-input">
                                    <option value="0">Chọn nhà cung cấp</option>
                                    @foreach($nhaCungCap as $ncc)
                                        <option value="{{ $ncc['id'] }}"
                                                data-code="{{ $ncc['code'] ?? '' }}"
                                                data-name="{{ $ncc['name'] ?? '' }}"
                                                data-phone="{{ $ncc['phone'] ?? '' }}"
                                                data-address="{{ $ncc['address'] ?? '' }}"
                                                data-address2="{{ $ncc['address_line2'] ?? '' }}"
                                                data-rep-phone="{{ $ncc['representative_phone'] ?? '' }}">
                                            {{ ($ncc['code'] ?? '') ? $ncc['code'].' - ' : '' }}{{ $ncc['name'] ?? '' }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="thn-field">
                                <label class="thn-field-label" for="thnDiaChi">Địa chỉ</label>
                                <input type="text" id="thnDiaChi" class="thn-input" disabled>
                            </div>
                            <div class="thn-field">
                                <label class="thn-field-label" for="thnDiaChi2">Địa chỉ 2</label>
                                <input type="text" id="thnDiaChi2" class="thn-input" disabled>
                            </div>
                            <div class="thn-field">
                                <label class="thn-field-label" for="thnSDTLienHe">SĐT người liên hệ</label>
                                <input type="text" id="thnSDTLienHe" class="thn-input" disabled>
                            </div>
                        </div>

                        <div class="thn-info-col">
                            <div class="thn-field">
                                <label class="thn-field-label" for="thnDienThoai">Điện thoại</label>
                                <input type="text" id="thnDienThoai" class="thn-input" disabled>
                            </div>
                            <div class="thn-field">
                                <label class="thn-field-label" for="thnChiNhanh">Chi nhánh</label>
                                <input type="text" id="thnChiNhanh" class="thn-input" disabled
                                       value="{{ $chiNhanh['name'] }}">
                            </div>
                            {{-- Ngày chứng từ do hệ thống đặt, y hệt v2 — khoá lại và gửi bằng ô ẩn. --}}
                            <div class="thn-field">
                                <label class="thn-field-label" for="thnNgayCT">Ngày chứng từ</label>
                                <input type="date" id="thnNgayCT" class="thn-input" disabled>
                            </div>
                            <div class="thn-field">
                                <label class="thn-field-label" for="thnNgayHetHan">Ngày hết hạn</label>
                                <input type="date" id="thnNgayHetHan" name="expired_date" class="thn-input">
                            </div>
                        </div>

                        <div class="thn-info-col">
                            <div class="thn-field">
                                <label class="thn-field-label" for="thnNguoiMua">Nhân viên mua hàng</label>
                                <select id="thnNguoiMua" name="purchaser_id" class="thn-input">
                                    <option value="0">— Chưa phân công —</option>
                                    @foreach($nhanVien as $nv)
                                        <option value="{{ $nv['id'] }}">
                                            {{ ($nv['code'] ?? '') ? $nv['code'].' - ' : '' }}{{ $nv['full_name'] ?? ($nv['name'] ?? '') }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="thn-field">
                                <label class="thn-field-label" for="thnSoGiao">Số phiếu giao bên nhận</label>
                                <input type="text" id="thnSoGiao" name="receiver_delivery_note" class="thn-input"
                                       maxlength="50" autocomplete="off">
                            </div>
                            {{-- VAT lấy theo phiếu mua đang chọn, không gõ tay — như v2. --}}
                            <div class="thn-field">
                                <label class="thn-field-label" for="thnVat">VAT (giá trị phiếu)</label>
                                <div class="thn-donvi">
                                    <input type="text" id="thnVat" class="thn-input" disabled value="0">
                                    <span class="thn-donvi-chu">%</span>
                                </div>
                            </div>
                            <div class="thn-field">
                                <label class="thn-field-label" for="thnGhiChu">Ghi chú</label>
                                <input type="text" id="thnGhiChu" name="note" class="thn-input" maxlength="200"
                                       autocomplete="off">
                            </div>
                        </div>

                        <div class="thn-info-col">
                            <div class="thn-field">
                                <label class="thn-field-label" for="thnNguoiLap">Người lập phiếu</label>
                                <input type="text" id="thnNguoiLap" class="thn-input" disabled
                                       value="{{ session('api.user.full_name') ?? '' }}">
                            </div>
                            <div class="thn-field">
                                <label class="thn-field-label" for="thnNgayLap">Ngày lập</label>
                                <input type="text" id="thnNgayLap" class="thn-input" disabled>
                            </div>
                            <div class="thn-field">
                                <label class="thn-field-label" for="thnLoaiCT">Loại chứng từ</label>
                                <input type="text" id="thnLoaiCT" class="thn-input" disabled
                                       value="{{ $C::LOAI_CHUNG_TU }}">
                            </div>
                            <div class="thn-field">
                                <label class="thn-field-label" for="thnTrangThai">Trạng thái</label>
                                <input type="text" id="thnTrangThai" class="thn-input" disabled value="Tạo mới">
                            </div>
                        </div>
                    </div>

                    {{-- 3. Thông tin hàng hoá --}}
                    <div class="thn-goods">
                        <div class="thn-goods-head">
                            <h4 class="thn-goods-title">Thông tin hàng hóa</h4>

                            <div class="thn-goods-tools">
                                <select id="thnPhieuMua" name="purchase_order_id" class="thn-input thn-input-sm"
                                        title="Chọn phiếu mua để trả hàng" disabled>
                                    <option value="0">Chọn nhà cung cấp trước</option>
                                </select>

                                <div class="thn-util" id="thnNangCao">
                                    <button type="button" class="thn-util-btn" id="thnNangCaoBtn" aria-haspopup="true" aria-expanded="false">
                                        Nâng cao
                                        <svg class="thn-util-caret" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                                    </button>
                                    <div class="thn-util-menu">
                                        <button type="button" class="thn-util-item" id="thnLineExport">Xuất file</button>
                                        <button type="button" class="thn-util-item" id="thnLineReset">Đổ lại từ phiếu mua</button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <p class="thn-import-error" id="thnLineError" style="display:none;"></p>

                        <div class="thn-lines-wrap">
                            <table class="thn-lines-table" id="thnLinesTable">
                                <thead>
                                    <tr>
                                        <th class="thn-l-idx">STT</th>
                                        <th class="thn-l-code">Mã hàng hóa</th>
                                        <th class="thn-l-name">Tên hàng hóa</th>
                                        <th class="thn-l-unit">Đơn vị tính</th>
                                        <th class="thn-l-qty">Số lượng trả</th>
                                        <th class="thn-l-bought">Số lượng nhập</th>
                                        <th class="thn-l-left">Số lượng còn lại</th>
                                        <th class="thn-l-cost">Giá nhập</th>
                                        <th class="thn-l-sub">Thành tiền trước VAT</th>
                                        <th class="thn-l-vatmoney">Tiền VAT</th>
                                        <th class="thn-l-amount">Tổng tiền sau VAT</th>
                                        <th class="thn-l-lot">Số lô</th>
                                        <th class="thn-l-exp">Hạn dùng</th>
                                        <th class="thn-l-x"></th>
                                    </tr>
                                </thead>
                                <tbody id="thnLines"></tbody>
                            </table>
                            <p class="thn-lines-empty" id="thnLinesEmpty">
                                Chọn nhà cung cấp rồi chọn phiếu mua ở trên — hàng của phiếu đó sẽ hiện ra tại đây.
                            </p>
                        </div>
                    </div>

                    {{-- 4. Khối tiền, canh phải --}}
                    <div class="thn-money">
                        <div class="thn-money-row">
                            <span class="thn-money-lb">Tổng tiền trước thuế</span>
                            <span class="thn-money-vl" id="thnTienHang">0₫</span>
                        </div>
                        <div class="thn-money-row">
                            <span class="thn-money-lb">Tổng tiền thuế</span>
                            <span class="thn-money-vl" id="thnThue">0₫</span>
                        </div>
                        <div class="thn-money-row is-total">
                            <span class="thn-money-lb">Tổng tiền</span>
                            <span class="thn-money-vl" id="thnTongCong">0₫</span>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>

    {{-- Hộp thoại Chi tiết --}}
    <div class="thn-overlay" id="thnDetailOverlay" style="display:none;">
        <div class="thn-dialog thn-dialog-lg">
            <div class="thn-modal-head">
                <h4 class="thn-modal-title" id="thnDetailTitle">Chi tiết phiếu trả hàng</h4>
                <button type="button" class="thn-modal-x" data-close>
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </div>

            <div class="thn-modal-body" id="thnDetailBody">
                <p class="thn-lines-empty">Đang đọc phiếu…</p>
            </div>

            <div class="thn-modal-foot" id="thnDetailFoot">
                <button type="button" class="thn-btn-ghost" data-close>Đóng</button>
            </div>
        </div>
    </div>

    {{-- Chỗ dựng bản in: nằm ngoài luồng, chỉ hiện khi trình duyệt in. --}}
    <div id="thnPrintArea" class="thn-print-area"></div>

    <style id="thnCotCss"></style>

    <style>
        .thn {
            /* Phá padding p-4 của <main> để tràn viền như các trang danh sách khác */
            margin: -1.5rem;
            min-height: calc(100vh - 56px);
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            color: #262626; background: #fff;
        }

        /* Header */
        .thn-head { display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; padding: 14px 20px; }
        .thn-title { margin: 0; font-size: 16px; font-weight: 700; line-height: 34px; }
        .thn-sum { font-size: 13px; color: #595959; }
        .thn-sum b { color: #262626; }
        .thn-sum em { font-style: normal; color: #bfbfbf; font-size: 12px; }
        .thn-tra { color: #d4380d; }
        .thn-callout { margin: 0 20px 12px; padding: 10px 12px; border-radius: 6px; font-size: 13px; }
        .thn-callout.is-error { background: #fff1f0; color: #cf1322; }

        /* Bộ lọc */
        .thn-filter { padding: 0 20px 12px; margin-bottom: 12px; border-bottom: 1px solid #eee; }
        .thn-toolbar { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; }
        .thn-toolbar-actions { margin-left: auto; display: flex; align-items: center; gap: 8px; }
        .thn-toolbar-adv { display: none; flex-wrap: wrap; align-items: center; gap: 20px; margin-top: 12px; }
        .thn-toolbar-adv.is-open { display: flex; }
        .thn-adv-btn {
            height: 34px; display: inline-flex; align-items: center; gap: 6px; border: 1px solid #d9d9d9;
            border-radius: 4px; background: #fff; padding: 0 12px; font-size: 13px; color: #595959; cursor: pointer;
            transition: border-color .15s, color .15s;
        }
        .thn-adv-btn:hover, .thn-adv-btn.is-open { border-color: #1890ff; color: #1890ff; }
        .thn-adv-caret { transition: transform .2s; }
        .thn-adv-btn.is-open .thn-adv-caret { transform: rotate(180deg); }
        .thn-adv-count {
            min-width: 18px; height: 18px; padding: 0 5px; border-radius: 9999px; background: #1890ff; color: #fff;
            font-size: 11px; font-weight: 700; display: inline-flex; align-items: center; justify-content: center;
        }

        .thn-searchbox { display: flex; border-radius: 4px; }
        .thn-searchbox:focus-within { box-shadow: 0 0 0 4px rgba(13, 110, 253, .25); }
        .thn-search-input {
            height: 34px; width: 320px; border: 1px solid #d9d9d9; border-right: 0; border-radius: 4px 0 0 4px;
            background: #fff; padding: 0 12px; font-size: 13px; outline: none;
        }
        .thn-search-input::placeholder { color: #bfbfbf; }
        .thn-searchbox:focus-within .thn-search-input,
        .thn-searchbox:focus-within .thn-search-btn { border-color: #86b7fe; }
        .thn-search-btn {
            height: 34px; width: 40px; display: inline-flex; align-items: center; justify-content: center;
            border: 1px solid #d9d9d9; border-radius: 0 4px 4px 0; background: #fafafa; color: #595959; cursor: pointer;
        }
        .thn-search-btn:hover { color: #1890ff; }

        /* Mọi ô chọn vẽ lại mũi tên bằng cùng một hình chevron với các nút khác. */
        select.thn-select, select.thn-input, select.thn-l-input {
            appearance: none; -webkit-appearance: none; cursor: pointer;
            background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23595959' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><path d='m6 9 6 6 6-6'/></svg>");
            background-repeat: no-repeat;
            text-overflow: ellipsis;
        }
        select.thn-select, select.thn-input { padding-right: 30px; background-position: right 9px center; }
        select.thn-l-input { padding-right: 22px; background-position: right 5px center; background-size: 13px 13px; }
        select.thn-select:hover:not(:focus),
        select.thn-input:hover:not(:focus),
        select.thn-l-input:hover:not(:focus) { border-color: #b8b8b8; }
        .thn-select {
            height: 34px; border: 1px solid #d9d9d9; border-radius: 4px; background-color: #fff;
            padding: 0 12px; font-size: 13px; color: #262626; cursor: pointer; outline: none; max-width: 220px;
        }
        .thn-select:focus { border-color: #86b7fe; box-shadow: 0 0 0 .25rem rgba(13, 110, 253, .25); }
        .thn-clear {
            display: inline-flex; align-items: center; height: 34px; padding: 0 10px; border-radius: 4px;
            font-size: 13px; color: #8c8c8c; text-decoration: none;
        }
        .thn-clear:hover { background: #f5f5f5; color: #262626; }

        /* Hàng ô tick trong Nâng cao */
        .thn-tickbox { display: inline-flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .thn-tick-label { font-size: 12px; font-weight: 600; color: #8c8c8c; }
        .thn-tick { display: inline-flex; align-items: center; gap: 5px; margin: 0; cursor: pointer; }
        .thn-tick input { width: 14px; height: 14px; accent-color: #1890ff; cursor: pointer; }
        .thn-dash { color: #bfbfbf; font-size: 12px; }

        /* Chip trạng thái — dùng chung cho bộ lọc, bảng và hộp chi tiết */
        .thn-chip {
            display: inline-flex; align-items: center; height: 22px; padding: 0 10px; border-radius: 9999px;
            font-size: 12px; font-weight: 600; white-space: nowrap;
        }
        .thn-chip.is-ok { background: #e6f7ff; color: #096dd9; }
        .thn-chip.is-warn { background: #fff7e6; color: #d46b08; }
        .thn-chip.is-off { background: #f5f5f5; color: #8c8c8c; }

        /* Nút chung */
        .thn-btn-primary, .thn-btn-ghost, .thn-btn-danger {
            height: 34px; display: inline-flex; align-items: center; justify-content: center; gap: 6px;
            padding: 0 14px; border-radius: 4px; font-size: 13px; font-weight: 500; cursor: pointer;
            border: 1px solid transparent; text-decoration: none; transition: background .15s, border-color .15s, color .15s;
        }
        .thn-btn-primary { background: #1890ff; color: #fff; }
        .thn-btn-primary:hover:not([disabled]) { background: #0f7ae5; }
        .thn-btn-primary[disabled] { opacity: .5; cursor: not-allowed; }
        .thn-btn-ghost { background: #fff; border-color: #d9d9d9; color: #262626; }
        .thn-btn-ghost:hover:not([disabled]) { border-color: #1890ff; color: #1890ff; }
        .thn-btn-ghost[disabled] { opacity: .5; cursor: not-allowed; }
        .thn-btn-danger { background: #fff; border-color: #ffa39e; color: #ff4d4f; }
        .thn-btn-danger:hover:not([disabled]) { background: #ff4d4f; border-color: #ff4d4f; color: #fff; }

        /* Dropdown tiện ích */
        .thn-util { position: relative; }
        .thn-util-btn {
            height: 34px; display: inline-flex; align-items: center; gap: 6px; border: 1px solid #d9d9d9;
            border-radius: 4px; background: #fff; padding: 0 12px; font-size: 13px; color: #595959; cursor: pointer;
        }
        .thn-util-btn:hover { border-color: #1890ff; color: #1890ff; }
        .thn-util-caret { transition: transform .2s; }
        .thn-util.open .thn-util-caret { transform: rotate(180deg); }
        .thn-util-menu {
            display: none; position: absolute; right: 0; top: calc(100% + 4px); z-index: 1050; min-width: 190px;
            background: #fff; border: 1px solid #e6e6e6; border-radius: 6px; box-shadow: 0 6px 20px rgba(0, 0, 0, .12);
            padding: 4px; flex-direction: column;
        }
        .thn-util.open .thn-util-menu { display: flex; }
        .thn-util-item {
            display: flex; align-items: center; gap: 8px; width: 100%; padding: 8px 10px; border: 0; border-radius: 4px;
            background: none; font-size: 13px; color: #262626; text-decoration: none; cursor: pointer;
        }
        .thn-util-item:hover { background: #f5f5f5; color: #1890ff; }
        .thn-btn-sq { width: 34px; padding: 0; justify-content: center; flex-shrink: 0; }
        .thn-cot-menu { min-width: 210px; max-height: 320px; overflow-y: auto; }
        .thn-cot-item {
            display: flex; align-items: center; gap: 8px; padding: 6px 10px; border-radius: 4px;
            font-size: 13px; color: #262626; cursor: pointer; margin: 0;
        }
        .thn-cot-item:hover { background: #f5f5f5; }
        .thn-cot-item input { width: 14px; height: 14px; accent-color: #1890ff; cursor: pointer; }
        .thn-cot-item.is-all { font-weight: 600; }
        .thn-cot-line { height: 1px; margin: 4px 0; background: #f0f0f0; }

        /* Bảng — mọi ô canh giữa, bề rộng khai theo % và cộng đúng 100%. */
        .thn-table-wrap { width: 100%; padding: 0 20px; overflow-x: auto; scrollbar-width: thin; }
        .thn-table-wrap::-webkit-scrollbar { height: 11px; }
        .thn-table-wrap::-webkit-scrollbar-thumb { background-color: #dcdcdc; border-radius: 8px; border: 3px solid #fff; }
        .thn-table { width: 100%; min-width: 1560px; border-collapse: collapse; font-size: 13px; table-layout: fixed; }
        .thn-table thead tr { background: #f0f0f0; color: #262626; }
        .thn-table thead th { text-align: center; padding: 14px 10px; font-size: 13px; font-weight: 700; white-space: nowrap; }
        .thn-table tbody td {
            padding: 14px 10px; border-bottom: 1px solid #f0f0f0; vertical-align: middle;
            text-align: center; white-space: nowrap; line-height: 1.5;
        }
        .thn-table tbody tr:hover { background: #fafafa; }
        .thn-table tbody tr.is-selected { background: #e6f7ff; }

        .thn-table th.thn-c-check,        .thn-table td.thn-c-check        { width: 3%; }
        .thn-table th.thn-c-stt,          .thn-table td.thn-c-stt          { width: 3%; color: #8c8c8c; }
        .thn-table th.thn-c-code,         .thn-table td.thn-c-code         { width: 8%; cursor: pointer; }
        .thn-table th.thn-c-suppliercode, .thn-table td.thn-c-suppliercode { width: 7%; color: #595959; }
        .thn-table th.thn-c-supplier,     .thn-table td.thn-c-supplier     { width: 12%; overflow: hidden; text-overflow: ellipsis; cursor: pointer; }
        .thn-table th.thn-c-docdate,      .thn-table td.thn-c-docdate      { width: 7%; color: #595959; }
        .thn-table th.thn-c-branch,       .thn-table td.thn-c-branch       { width: 8%; overflow: hidden; text-overflow: ellipsis; color: #595959; }
        .thn-table th.thn-c-items,        .thn-table td.thn-c-items        { width: 8%; color: #595959; }
        .thn-table th.thn-c-total,        .thn-table td.thn-c-total        { width: 8%; }
        .thn-table th.thn-c-status,       .thn-table td.thn-c-status       { width: 7%; }
        .thn-table th.thn-c-stock,        .thn-table td.thn-c-stock        { width: 7%; }
        .thn-table th.thn-c-creator,      .thn-table td.thn-c-creator      { width: 8%; overflow: hidden; text-overflow: ellipsis; color: #595959; }
        .thn-table th.thn-c-note,         .thn-table td.thn-c-note         { width: 7%; overflow: hidden; text-overflow: ellipsis; color: #595959; }
        .thn-table th.thn-c-act,          .thn-table td.thn-c-act          { width: 7%; }

        .thn-code { font-weight: 600; color: #1890ff; }
        .thn-name { display: block; font-weight: 500; overflow: hidden; text-overflow: ellipsis; }
        .thn-muted { color: #bfbfbf; }
        .thn-empty { padding: 40px 12px; text-align: center; color: #8c8c8c; white-space: normal; }
        .thn-check { width: 15px; height: 15px; cursor: pointer; accent-color: #1890ff; }

        .thn-rowacts { display: inline-flex; align-items: center; justify-content: center; gap: 6px; }
        .thn-rowbtn {
            width: 30px; height: 30px; display: inline-flex; align-items: center; justify-content: center;
            border: 1px solid #d9d9d9; border-radius: 4px; background: #fff; color: #595959; cursor: pointer;
            transition: border-color .15s, color .15s;
        }
        .thn-rowbtn:hover { border-color: #1890ff; color: #1890ff; }
        .thn-rowbtn.is-danger:hover { border-color: #ff4d4f; color: #ff4d4f; }

        /* Thanh thao tác hàng loạt — pill trắng nổi giữa đáy vùng nội dung */
        .thn-bulk {
            position: fixed; bottom: 24px; left: 230px; right: 0; margin-inline: auto; z-index: 1070;
            width: max-content; max-width: calc(100vw - 260px);
            display: flex; align-items: center; gap: 16px; border: 1px solid #e6e6e6; border-radius: 9999px;
            background: #fff; padding: 12px 20px; box-shadow: 0 6px 24px rgba(0, 0, 0, .15);
        }
        body:has(.jh-sidebar.collapsed) .thn-bulk { left: 48px; }
        @media (max-width: 820px) { .thn-bulk { left: 0; } }
        .thn-bulk-count { font-size: 13px; white-space: nowrap; }
        .thn-bulk-count b { color: #1890ff; }
        .thn-bulk-clear { border: 0; background: none; font-size: 13px; color: #8c8c8c; cursor: pointer; padding: 0; }
        .thn-bulk-clear:hover { color: #262626; }
        .thn-bulk-actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .thn-bulk-actions .thn-btn-ghost, .thn-bulk-actions .thn-btn-danger { height: 30px; }

        /* Modal */
        .thn-overlay {
            position: fixed; inset: 0; z-index: 1055; background: rgba(0, 0, 0, .45);
            display: flex; align-items: center; justify-content: center; padding: 16px;
        }
        .thn-dialog {
            width: 100%; max-width: 940px; max-height: 92vh; overflow-y: auto; background: #fff;
            border-radius: 10px; box-shadow: 0 12px 40px rgba(0, 0, 0, .2); scrollbar-width: thin;
        }
        .thn-dialog-xl { max-width: 1480px; }
        .thn-dialog-lg { max-width: 1000px; }
        .thn-modal-head {
            position: sticky; top: 0; z-index: 3; background: #fff;
            display: flex; align-items: center; justify-content: space-between; gap: 12px;
            padding: 14px 20px; border-bottom: 1px solid #f0f0f0;
        }
        .thn-modal-title { margin: 0; font-size: 15px; font-weight: 700; }
        .thn-modal-x { border: 0; background: none; color: #8c8c8c; cursor: pointer; padding: 4px; border-radius: 4px; }
        .thn-modal-x:hover { background: #f5f5f5; color: #262626; }
        .thn-modal-body { padding: 16px 20px; display: flex; flex-direction: column; gap: 16px; }
        /* Hàng nút chân hộp thoại luôn canh giữa */
        .thn-modal-foot {
            position: sticky; bottom: 0; z-index: 3; display: flex; align-items: center; justify-content: center;
            gap: 12px; flex-wrap: wrap; padding: 12px 20px; border-top: 1px solid #f0f0f0; background: #fafafa;
        }

        /* Hàng nút của hộp lập phiếu — nằm TRÊN thân, canh phải như bản v2 */
        .thn-form-bar {
            position: sticky; top: 49px; z-index: 2; display: flex; justify-content: flex-end; gap: 8px;
            padding: 10px 20px; background: #fff; border-bottom: 1px solid #f0f0f0;
        }

        /* Thông tin phiếu: 4 cột × 4 ô */
        .thn-info { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px 16px; }
        @media (max-width: 1000px) { .thn-info { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 560px) { .thn-info { grid-template-columns: 1fr; } }
        .thn-info-col { display: flex; flex-direction: column; gap: 10px; min-width: 0; }
        .thn-input[disabled] { background: #f5f5f5; color: #8c8c8c; cursor: default; }

        /* Ô nhập có đơn vị đi kèm: "%" nằm TRONG khung, sát mép phải. */
        .thn-donvi { position: relative; }
        .thn-donvi .thn-input { padding-right: 34px; text-align: right; }
        .thn-donvi-chu {
            position: absolute; right: 10px; top: 0; height: 34px; display: flex; align-items: center;
            font-size: 13px; color: #8c8c8c; pointer-events: none; user-select: none;
        }

        /* Form */
        .thn-field { display: flex; flex-direction: column; gap: 4px; min-width: 0; }
        .thn-field-label { font-size: 12px; font-weight: 600; color: #595959; }
        .thn-input {
            border: 1px solid #d9d9d9; border-radius: 4px; padding: 0 10px; height: 34px;
            font-size: 13px; color: #262626; outline: none; background: #fff; width: 100%;
        }
        .thn-input:focus { border-color: #86b7fe; box-shadow: 0 0 0 .2rem rgba(13, 110, 253, .2); }
        .thn-input-sm { width: auto; min-width: 240px; }
        .thn-hint { margin: 0; font-size: 12px; color: #8c8c8c; }
        .thn-req { color: #ff4d4f; }
        .thn-note-box { margin: 0; padding: 10px 12px; border-radius: 6px; background: #f6f8fa; font-size: 12px; color: #595959; }

        /* Khối "Thông tin hàng hóa" */
        .thn-goods { border-top: 1px solid #f0f0f0; padding-top: 12px; }
        .thn-goods-head {
            display: flex; align-items: center; justify-content: space-between; gap: 16px;
            flex-wrap: wrap; margin-bottom: 10px;
        }
        .thn-goods-title { margin: 0; font-size: 14px; font-weight: 700; }
        .thn-goods-tools { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-left: auto; }
        .thn-import-error {
            margin: 0 0 10px; padding: 8px 12px; border-radius: 6px;
            background: #fff1f0; color: #cf1322; font-size: 12px;
        }

        .thn-lines-wrap { overflow-x: auto; scrollbar-width: thin; }
        .thn-lines-wrap::-webkit-scrollbar { height: 11px; }
        .thn-lines-wrap::-webkit-scrollbar-thumb { background-color: #dcdcdc; border-radius: 8px; border: 3px solid #fff; }
        .thn-lines-table { width: 100%; min-width: 1500px; border-collapse: collapse; font-size: 13px; table-layout: fixed; }
        .thn-lines-table thead th {
            text-align: center; padding: 10px 8px; font-size: 12px; font-weight: 700; color: #262626;
            background: #f0f0f0; white-space: normal; line-height: 1.3;
        }
        .thn-lines-table tbody td {
            padding: 7px 8px; border-bottom: 1px solid #f5f5f5; vertical-align: middle; text-align: center;
            white-space: nowrap;
        }
        .thn-lines-table tbody tr:hover { background: #fafafa; }
        .thn-lines-table tbody tr.is-het { background: #fff7e6; }

        /* Mười bốn cột, cộng đúng 100%. */
        .thn-lines-table th.thn-l-idx,      .thn-lines-table td.thn-l-idx      { width: 3%; color: #8c8c8c; }
        .thn-lines-table th.thn-l-code,     .thn-lines-table td.thn-l-code     { width: 9%; }
        .thn-lines-table th.thn-l-name,     .thn-lines-table td.thn-l-name     { width: 14%; text-align: left; }
        .thn-lines-table th.thn-l-unit,     .thn-lines-table td.thn-l-unit     { width: 7%; }
        .thn-lines-table th.thn-l-qty,      .thn-lines-table td.thn-l-qty      { width: 8%; }
        .thn-lines-table th.thn-l-bought,   .thn-lines-table td.thn-l-bought   { width: 7%; color: #595959; }
        .thn-lines-table th.thn-l-left,     .thn-lines-table td.thn-l-left     { width: 7%; }
        .thn-lines-table th.thn-l-cost,     .thn-lines-table td.thn-l-cost     { width: 8%; }
        .thn-lines-table th.thn-l-sub,      .thn-lines-table td.thn-l-sub      { width: 9%; }
        .thn-lines-table th.thn-l-vatmoney, .thn-lines-table td.thn-l-vatmoney { width: 7%; }
        .thn-lines-table th.thn-l-amount,   .thn-lines-table td.thn-l-amount   { width: 9%; }
        .thn-lines-table th.thn-l-lot,      .thn-lines-table td.thn-l-lot      { width: 6%; }
        .thn-lines-table th.thn-l-exp,      .thn-lines-table td.thn-l-exp      { width: 4%; }
        .thn-lines-table th.thn-l-x,        .thn-lines-table td.thn-l-x        { width: 2%; }

        .thn-lines-table td.thn-l-code, .thn-lines-table td.thn-l-name { overflow: hidden; text-overflow: ellipsis; }
        .thn-l-ma { font-size: 12px; color: #595959; }

        /* Ba cột tiền canh PHẢI và dùng chữ số đều bề ngang. */
        .thn-lines-table th.thn-l-sub, .thn-lines-table td.thn-l-sub,
        .thn-lines-table th.thn-l-vatmoney, .thn-lines-table td.thn-l-vatmoney,
        .thn-lines-table th.thn-l-amount, .thn-lines-table td.thn-l-amount,
        .thn-lines-table th.thn-l-cost, .thn-lines-table td.thn-l-cost { text-align: right; }
        .thn-lines-table td.thn-l-sub,
        .thn-lines-table td.thn-l-vatmoney,
        .thn-lines-table td.thn-l-cost,
        .thn-lines-table td.thn-l-amount { font-variant-numeric: tabular-nums; }
        .thn-lines-table td.thn-l-vatmoney, .thn-lines-table td.thn-l-sub { color: #595959; }

        .thn-l-input {
            width: 100%; height: 32px; border: 1px solid #d9d9d9; border-radius: 4px; background: #fff;
            padding: 0 8px; font-size: 13px; text-align: right; outline: none; color: #262626;
            font-variant-numeric: tabular-nums;
        }
        .thn-l-input:focus { border-color: #86b7fe; box-shadow: 0 0 0 .15rem rgba(13, 110, 253, .2); }
        .thn-l-input[disabled] { background: #f5f5f5; color: #8c8c8c; cursor: not-allowed; }
        .thn-l-name-main { display: block; font-weight: 500; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .thn-l-name-sub { display: block; font-size: 12px; color: #8c8c8c; }

        /* Trần số lượng nằm ngay dưới ô nhập — chỗ dễ sai nhất của phiếu trả. */
        .thn-l-tran {
            display: block; margin-top: 2px; font-size: 11px; color: #096dd9; white-space: nowrap;
            overflow: hidden; text-overflow: ellipsis;
        }
        .thn-l-tran.is-het { color: #ff4d4f; }
        .thn-l-amount b { font-weight: 600; }
        .thn-l-x-btn {
            width: 26px; height: 26px; display: inline-flex; align-items: center; justify-content: center;
            border: 1px solid transparent; border-radius: 4px; background: none; color: #bfbfbf; cursor: pointer;
        }
        .thn-l-x-btn:hover { border-color: #ffa39e; color: #ff4d4f; }
        .thn-lines-empty { margin: 0; padding: 28px 12px; text-align: center; font-size: 13px; color: #8c8c8c; }

        /* Khối tiền — canh phải, ba dòng như v2 */
        .thn-money { margin-left: auto; width: 100%; max-width: 420px; display: flex; flex-direction: column; gap: 8px; }
        .thn-money-row { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
        .thn-money-lb { font-size: 13px; color: #595959; }
        .thn-money-vl { font-size: 13px; color: #262626; font-variant-numeric: tabular-nums; }
        .thn-money-row.is-total { margin-top: 4px; padding-top: 8px; border-top: 1px solid #f0f0f0; }
        .thn-money-row.is-total .thn-money-lb { font-weight: 700; color: #262626; }
        .thn-money-row.is-total .thn-money-vl { font-size: 16px; font-weight: 700; color: #1890ff; }

        /* Hộp chi tiết */
        .thn-view-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px 16px; }
        @media (max-width: 900px) { .thn-view-grid { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 560px) { .thn-view-grid { grid-template-columns: 1fr; } }
        .thn-cell { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
        .thn-cell.is-full { grid-column: 1 / -1; }
        .thn-lb { font-size: 12px; color: #8c8c8c; }
        .thn-vl { font-size: 13px; color: #262626; word-break: break-word; }
        .thn-view-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .thn-view-table thead th {
            text-align: center; padding: 10px 8px; font-size: 12px; font-weight: 700; color: #595959;
            background: #fafafa; white-space: nowrap;
        }
        .thn-view-table tbody td { padding: 10px 8px; border-bottom: 1px solid #f5f5f5; text-align: center; }
        .thn-view-table tbody td.is-left { text-align: left; }
        .thn-sec-title { margin: 0; font-size: 13px; font-weight: 700; color: #262626; }

        /*
           Bản in. Hai kiểu in dùng chung một đường: in danh sách thì giấu khung
           điều hướng và để nguyên bảng; in phiếu thì giấu cả trang, chỉ chừa
           vùng #thnPrintArea.
        */
        .thn-print-area { display: none; }
        @media print {
            .jh-sidebar, .jh-topbar, .toast-container,
            .thn-filter, .thn-bulk, .pgv2, .thn-c-check, .thn-c-act { display: none !important; }
            .thn { margin: 0; min-height: 0; }
            .thn-table-wrap { padding: 0; overflow: visible; }
            .thn-table { min-width: 0; font-size: 11px; }
            .thn-table tbody tr { page-break-inside: avoid; }
            .thn-chip { background: none !important; color: #000 !important; padding: 0; font-weight: 400; }
            .thn-overlay { display: none !important; }

            /* Đang in một phiếu: mọi thứ khác biến mất. */
            body.thn-in-phieu .thn, body.thn-in-phieu .thn-head { display: none !important; }
            body.thn-in-phieu .thn-print-area { display: block !important; }
        }
        .thn-print-doc { font-size: 12px; color: #000; }
        .thn-print-doc h2 { font-size: 16px; margin: 0 0 4px; text-align: center; text-transform: uppercase; }
        .thn-print-doc .thn-print-sub { text-align: center; margin: 0 0 12px; font-size: 12px; }
        .thn-print-doc .thn-print-info { width: 100%; margin-bottom: 10px; }
        .thn-print-doc .thn-print-info td { padding: 2px 4px; vertical-align: top; }
        .thn-print-doc table.thn-print-lines { width: 100%; border-collapse: collapse; }
        .thn-print-doc table.thn-print-lines th,
        .thn-print-doc table.thn-print-lines td { border: 1px solid #000; padding: 4px 6px; font-size: 11px; }
        .thn-print-doc table.thn-print-lines th { text-align: center; }
        .thn-print-doc .thn-print-sign { display: flex; justify-content: space-between; margin-top: 32px; text-align: center; }
        .thn-print-doc .thn-print-sign div { width: 45%; }
    </style>

    <script>
        (function () {
            const CSRF = '{{ csrf_token() }}';
            const RETURN_URL = @json(request()->getRequestUri());
            const URL_BASE = @json(url('/admin/supplier-returns'));
            const URL_STORE = @json(route('admin.tra-hang-nha-cung-cap.store'));
            const URL_PHIEU_MUA = @json(route('admin.tra-hang-nha-cung-cap.phieuMua'));
            const URL_DONG_PHIEU = @json(route('admin.tra-hang-nha-cung-cap.dongPhieuMua'));
            const URL_BULK_DEL = @json(route('admin.tra-hang-nha-cung-cap.bulkDestroy'));

            const ROWS = @json($list);
            const BY_ID = new Map(ROWS.map((r) => [Number(r.id), r]));
            const NHAN_TRANG_THAI = @json(\App\Http\Controllers\TraHangNhaCungCapController::TRANG_THAI);
            const MAU_TRANG_THAI = @json(\App\Http\Controllers\TraHangNhaCungCapController::MAU_TRANG_THAI);
            const NHAN_KHO = @json(\App\Http\Controllers\TraHangNhaCungCapController::TRANG_THAI_KHO);
            const MAU_KHO = @json(\App\Http\Controllers\TraHangNhaCungCapController::MAU_TRANG_THAI_KHO);
            const LOAI_CT = @json(\App\Http\Controllers\TraHangNhaCungCapController::LOAI_CHUNG_TU);
            const TEN_CHI_NHANH = @json($chiNhanh['name']);

            const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => (
                { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]
            ));
            const money = (v) => (Number(v) || 0).toLocaleString('vi-VN') + '₫';
            const soN = (v) => {
                const n = Number(String(v ?? '').replace(/[^\d.-]/g, ''));
                return Number.isFinite(n) ? n : 0;
            };
            const nhomSo = (v) => (Number(v) || 0).toLocaleString('vi-VN');
            // Số lượng trả là số NGUYÊN: sổ kho của hệ thống này đếm nguyên, nhận
            // 0,5 rồi làm tròn lúc ghi kho là mỗi phiếu lệch một ít.
            const soNguyen = (v) => Math.max(0, Math.round(Number(v) || 0));
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
            const homNay = () => {
                const d = new Date();
                const p = (n) => String(n).padStart(2, '0');
                return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`;
            };

            /** Con trỏ trong ô số đếm TỪ PHẢI SANG — chấm lại dấu nghìn không đẩy con trỏ về đầu. */
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
            const $filter = document.getElementById('thnFilter');

            (function () {
                const btn = document.getElementById('thnAdvToggle');
                const row = document.getElementById('thnAdvRow');
                const setOpen = (open) => {
                    row.classList.toggle('is-open', open);
                    btn.classList.toggle('is-open', open);
                    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
                };
                if (!row.classList.contains('is-open') && localStorage.getItem('thn-adv-open') === '1') setOpen(true);
                btn.addEventListener('click', () => {
                    const open = !row.classList.contains('is-open');
                    setOpen(open);
                    localStorage.setItem('thn-adv-open', open ? '1' : '0');
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

            const closeAll = () => {
                ['thnFormOverlay', 'thnDetailOverlay']
                    .forEach((id) => { document.getElementById(id).style.display = 'none'; });
            };
            document.querySelectorAll('[data-close]').forEach((b) => b.addEventListener('click', closeAll));
            document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeAll(); });

            // =================================================================
            //  Hộp thoại Lập / Sửa phiếu
            // =================================================================
            const $form = document.getElementById('thnForm');
            const $formOverlay = document.getElementById('thnFormOverlay');
            const $lines = document.getElementById('thnLines');
            const $linesEmpty = document.getElementById('thnLinesEmpty');
            // Câu mời ban đầu của lưới hàng — bỏ chọn phiếu mua thì quay lại câu này.
            const EMPTY_DAU = $linesEmpty.textContent.trim();
            const $lineError = document.getElementById('thnLineError');
            const $phieuMua = document.getElementById('thnPhieuMua');

            // Ô ghi được, tên khoá = tên trường API.
            const O = {
                supplier_id: document.getElementById('thnNCC'),
                expired_date: document.getElementById('thnNgayHetHan'),
                purchaser_id: document.getElementById('thnNguoiMua'),
                receiver_delivery_note: document.getElementById('thnSoGiao'),
                note: document.getElementById('thnGhiChu'),
            };
            // Ô chỉ để đọc: hồ sơ bên bán và thông tin phiếu do hệ thống đặt.
            const X = {
                tenNCC: document.getElementById('thnTenNCC'),
                diaChi: document.getElementById('thnDiaChi'),
                diaChi2: document.getElementById('thnDiaChi2'),
                dienThoai: document.getElementById('thnDienThoai'),
                sdtLienHe: document.getElementById('thnSDTLienHe'),
                ngayCT: document.getElementById('thnNgayCT'),
                ngayCTGui: document.getElementById('thnNgayCTGui'),
                ngayLap: document.getElementById('thnNgayLap'),
                trangThai: document.getElementById('thnTrangThai'),
                vat: document.getElementById('thnVat'),
                vatGui: document.getElementById('thnVatGui'),
            };

            /** Mỗi phần tử là một dòng hàng đang dựng trong hộp thoại. */
            let DONG = [];
            let dongSeq = 0;
            let VAT_PHIEU = 0;

            /** Chọn nhà cung cấp thì bốn ô hồ sơ bên dưới tự điền. */
            function veHoSoNCC() {
                const opt = O.supplier_id.selectedOptions[0];
                const d = opt ? opt.dataset : {};
                X.tenNCC.value = d.name || '';
                X.diaChi.value = d.address || '';
                X.diaChi2.value = d.address2 || '';
                X.dienThoai.value = d.phone || '';
                X.sdtLienHe.value = d.repPhone || d.phone || '';
            }

            /**
             * Đổi nhà cung cấp là đổi cả dây: nạp lại danh sách phiếu mua và bỏ
             * lưới hàng cũ — hàng của bên bán này không nằm trên phiếu của bên kia.
             */
            O.supplier_id.addEventListener('change', () => {
                veHoSoNCC();
                DONG = [];
                $lineError.style.display = 'none';
                $linesEmpty.textContent = EMPTY_DAU;
                veDong();
                napPhieuMua(Number(O.supplier_id.value) || 0);
            });

            async function napPhieuMua(nccId, chonId) {
                $phieuMua.innerHTML = '<option value="0">Đang đọc phiếu mua…</option>';
                $phieuMua.disabled = true;
                if (nccId <= 0) {
                    $phieuMua.innerHTML = '<option value="0">Chọn nhà cung cấp trước</option>';
                    return;
                }

                let ds = [];
                try {
                    const res = await fetch(`${URL_PHIEU_MUA}?supplier_id=${nccId}`, { headers: { Accept: 'application/json' } });
                    const data = await res.json();
                    if (!res.ok) throw new Error(data.message || 'Không đọc được phiếu mua.');
                    ds = data.data || [];
                } catch (e) {
                    $phieuMua.innerHTML = '<option value="0">Không đọc được phiếu mua</option>';
                    baoLoiDong(e.message || 'Không đọc được phiếu mua của nhà cung cấp.');
                    return;
                }

                if (!ds.length) {
                    $phieuMua.innerHTML = '<option value="0">Nhà cung cấp này chưa có phiếu mua đã duyệt</option>';
                    return;
                }

                $phieuMua.innerHTML = '<option value="0">Chọn phiếu mua</option>'
                    + ds.map((p) => `<option value="${p.id}">${esc(p.po_code || ('#' + p.id))}`
                        + ` — ${esc(ngay(p.document_date))} — ${esc(money(p.total_amount))}</option>`).join('');
                $phieuMua.disabled = false;
                if (chonId) $phieuMua.value = String(chonId);
            }

            /** Đổi phiếu mua thì lưới hàng đổ lại từ đầu. */
            $phieuMua.addEventListener('change', () => napDongPhieuMua(Number($phieuMua.value) || 0));

            /**
             * Đổ lưới hàng từ một phiếu mua.
             *
             * `daLuu` là bộ {purchase_item_id: số lượng} của phiếu ĐANG SỬA —
             * đổ lại từ phiếu mua rồi điền số cũ vào, để trần luôn là trần của
             * hôm nay chứ không phải của hôm lập phiếu.
             */
            async function napDongPhieuMua(id, daLuu) {
                $lineError.style.display = 'none';
                DONG = [];
                dongSeq = 0;
                if (id <= 0) { $linesEmpty.textContent = EMPTY_DAU; veDong(); return; }

                $linesEmpty.textContent = 'Đang đọc hàng của phiếu mua…';
                veDong();

                let data = null;
                try {
                    const res = await fetch(`${URL_DONG_PHIEU}?id=${id}`, { headers: { Accept: 'application/json' } });
                    data = await res.json();
                    if (!res.ok) throw new Error(data.message || 'Không đọc được phiếu mua.');
                } catch (err) {
                    baoLoiDong(err.message || 'Không đọc được phiếu mua.');
                    $linesEmpty.textContent = 'Chưa đọc được hàng của phiếu mua.';
                    veDong();
                    return;
                }

                VAT_PHIEU = Number(data.phieu?.vat_percent || 0);
                X.vat.value = String(VAT_PHIEU);
                X.vatGui.value = String(VAT_PHIEU);

                (data.data || []).forEach((it) => themDong(it, daLuu));

                $linesEmpty.textContent = 'Phiếu mua này không còn dòng hàng nào trả được.';
                veDong();
            }

            /**
             * Một dòng của phiếu mua thành một dòng trả.
             *
             * Trần là `returnable` API tính sẵn = min(đã mua − đã trả, tồn còn
             * lại). Hết phần trả được thì ô nhập khoá lại.
             */
            function themDong(ln, daLuu) {
                const poi = Number(ln.purchase_item_id || 0);
                const tran = Math.max(0, Number(ln.returnable || 0));
                const cu = daLuu ? Number(daLuu[poi] || 0) : null;

                DONG.push({
                    key: ++dongSeq,
                    purchase_item_id: poi,
                    variant_id: Number(ln.variant_id || 0),
                    product_name: ln.product_name || '',
                    variant_name: ln.variant_name || '',
                    sku: ln.variant_sku || '',
                    unit_id: Number(ln.unit_id || 0),
                    unit_name: ln.unit_name || '',
                    unit_ratio: Number(ln.unit_ratio || 1) || 1,
                    bought: Number(ln.quantity || 0),
                    returned: Number(ln.returned || 0),
                    stock: Number(ln.stock || 0),
                    max: tran,
                    // Sửa phiếu thì giữ số cũ; lập mới thì v2 điền sẵn 1 cho mỗi
                    // dòng, hết phần trả được thì 0.
                    quantity: cu !== null ? Math.min(cu, tran) : (tran > 0 ? 1 : 0),
                    unit_cost: Number(ln.unit_cost || 0),
                    vat_percent: Number(ln.vat_percent ?? VAT_PHIEU),
                    lot_number: ln.lot_number || '',
                    expire_date: (ln.expire_date || '').slice(0, 10),
                });
            }

            function veDong() {
                $linesEmpty.style.display = DONG.length ? 'none' : '';

                $lines.innerHTML = DONG.map((d, i) => {
                    const vat = Number(d.vat_percent || 0);
                    const tienHang = Math.round(d.unit_cost * d.quantity);
                    const tienThue = Math.round(tienHang * Math.max(0, vat) / 100);
                    const ten = [d.product_name, d.variant_name].filter(Boolean).join(' · ');
                    const het = d.max <= 0;

                    // Trần nói ngay dưới ô nhập: hết hàng là hết hàng, không để
                    // người ta gõ số rồi tới lượt duyệt mới biết kho không có.
                    const viSao = d.returned > 0
                        ? `đã mua ${nhomSo(d.bought)}, đã trả ${nhomSo(d.returned)}, kho còn ${nhomSo(d.stock)}`
                        : `đã mua ${nhomSo(d.bought)}, kho còn ${nhomSo(d.stock)}`;
                    const tran = het
                        ? `<span class="thn-l-tran is-het" title="${esc(viSao)}">Không còn trả được</span>`
                        : `<span class="thn-l-tran" title="Trả tối đa ${nhomSo(d.max)} ${esc(d.unit_name || '')} — ${esc(viSao)}">tối đa ${nhomSo(d.max)}</span>`;

                    return `<tr data-key="${d.key}" class="${het ? 'is-het' : ''}">
                        <td class="thn-l-idx">${i + 1}</td>
                        <td class="thn-l-code" title="${esc(d.sku)}">
                            <span class="thn-l-ma">${esc(d.sku || '—')}</span>
                        </td>
                        <td class="thn-l-name" title="${esc(ten)}">
                            <span class="thn-l-name-main">${esc(ten)}</span>
                        </td>
                        <td class="thn-l-unit">${esc(d.unit_name || '—')}</td>
                        <td class="thn-l-qty">
                            <input type="text" class="thn-l-input" data-f="quantity" inputmode="numeric"
                                   value="${nhomSo(d.quantity)}" aria-label="Số lượng trả" ${het ? 'disabled' : ''}>
                            ${tran}
                        </td>
                        <td class="thn-l-bought">${nhomSo(d.bought)}</td>
                        <td class="thn-l-left">${nhomSo(d.stock)}</td>
                        <td class="thn-l-cost">${money(d.unit_cost)}</td>
                        <td class="thn-l-sub">${money(tienHang)}</td>
                        <td class="thn-l-vatmoney">${money(tienThue)}</td>
                        <td class="thn-l-amount"><b>${money(tienHang + tienThue)}</b></td>
                        <td class="thn-l-lot">${esc(d.lot_number || '—')}</td>
                        <td class="thn-l-exp">${d.expire_date ? esc(ngay(d.expire_date)) : '—'}</td>
                        <td class="thn-l-x">
                            <button type="button" class="thn-l-x-btn" data-xoa="${d.key}" title="Bỏ dòng này">
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
                    thue += Math.round(line * Math.max(0, Number(d.vat_percent || 0)) / 100);
                });

                document.getElementById('thnTienHang').textContent = money(tienHang);
                document.getElementById('thnThue').textContent = money(thue);
                document.getElementById('thnTongCong').textContent = money(tienHang + thue);

                const coHang = DONG.some((d) => d.quantity > 0);
                document.getElementById('thnSaveApprove').disabled = !coHang;
                document.getElementById('thnSaveDraft').disabled = !coHang;
            }

            /**
             * Gõ số lượng trả.
             *
             * Vượt trần thì kéo về trần và nói ra bằng toast — im lặng sửa số
             * người ta vừa gõ là kiểu tệ nhất.
             */
            $lines.addEventListener('input', (e) => {
                const o = e.target.closest('[data-f="quantity"]');
                if (!o) return;
                const key = Number(o.closest('tr').dataset.key);
                const d = DONG.find((x) => x.key === key);
                if (!d) return;

                let sl = soNguyen(soN(o.value));
                if (sl > d.max) {
                    toastErr(`"${d.product_name}" chỉ trả được tối đa ${nhomSo(d.max)} ${d.unit_name || ''}`.trim() + '.');
                    sl = d.max;
                    o.value = nhomSo(sl);
                }
                d.quantity = sl;

                // Vẽ lại cả lưới cho tiền của dòng đổi theo, nhưng giữ con trỏ.
                const phai = viTriTuPhai(o);
                veDong();
                const lai = $lines.querySelector(`tr[data-key="${key}"] [data-f="quantity"]`);
                if (lai) { lai.focus(); datConTro(lai, phai); }
            });

            $lines.addEventListener('click', (e) => {
                const nut = e.target.closest('[data-xoa]');
                if (!nut) return;
                DONG = DONG.filter((d) => d.key !== Number(nut.dataset.xoa));
                veDong();
            });

            function baoLoiDong(cau) {
                $lineError.textContent = cau;
                $lineError.style.display = '';
            }

            // ---------- Nâng cao: xuất lưới hàng / đổ lại ----------
            const COT_DONG = ['Mã hàng hóa', 'Tên hàng hóa', 'Đơn vị tính', 'Số lượng trả', 'Số lượng nhập',
                'Đã trả trước đó', 'Tồn còn lại', 'Giá nhập', 'Thành tiền trước VAT', 'Tiền VAT',
                'Tổng tiền sau VAT', 'Số lô', 'Hạn dùng'];

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

            document.getElementById('thnLineExport').addEventListener('click', () => {
                dongDropdown();
                if (!DONG.length) { toastErr('Phiếu chưa có dòng hàng nào để xuất.'); return; }
                taiCSV('dong-hang-phieu-tra.csv', [COT_DONG].concat(DONG.map((d) => {
                    const tienHang = Math.round(d.unit_cost * d.quantity);
                    const thue = Math.round(tienHang * Math.max(0, Number(d.vat_percent || 0)) / 100);
                    return [d.sku, [d.product_name, d.variant_name].filter(Boolean).join(' · '), d.unit_name,
                        d.quantity, d.bought, d.returned, d.stock, d.unit_cost, tienHang, thue,
                        tienHang + thue, d.lot_number, d.expire_date];
                })));
            });

            document.getElementById('thnLineReset').addEventListener('click', () => {
                dongDropdown();
                const id = Number($phieuMua.value) || 0;
                if (id <= 0) { toastErr('Chưa chọn phiếu mua nào để đổ lại.'); return; }
                napDongPhieuMua(id);
            });

            // ---------- Mở hộp thoại ----------

            /** mode: 'add' | 'edit'. `p` là phiếu API trả về khi sửa. */
            async function moForm(mode, p) {
                const sua = mode === 'edit';
                const d = p || {};
                $form.action = sua ? `${URL_BASE}/${d.id}` : URL_STORE;
                document.getElementById('thnFormMethod').value = sua ? 'PUT' : 'POST';
                document.getElementById('thnId').value = sua ? (d.id || '') : '';
                document.getElementById('thnFormTitle').textContent = sua
                    ? `Sửa phiếu trả ${d.return_code || ''}`.trim()
                    : 'Lập phiếu trả hàng nhà cung cấp';

                // Nút In chỉ có nghĩa khi phiếu đã tồn tại — y hệt v2.
                const nutIn = document.getElementById('thnFormPrint');
                nutIn.style.display = sua ? '' : 'none';
                nutIn.onclick = sua ? () => inPhieu(d) : null;

                O.supplier_id.value = String(d.supplier_id || 0);
                O.expired_date.value = (d.expired_date || '').slice(0, 10);
                O.purchaser_id.value = String(d.purchaser_id || 0);
                O.receiver_delivery_note.value = d.receiver_delivery_note || '';
                O.note.value = d.note || '';
                veHoSoNCC();
                if (sua) X.tenNCC.value = d.supplier_name || X.tenNCC.value;

                const ngayCT = (d.document_date || '').slice(0, 10) || homNay();
                X.ngayCT.value = ngayCT;
                X.ngayCTGui.value = ngayCT;
                X.ngayLap.value = sua ? ngay(d.created_at) : new Date().toLocaleDateString('vi-VN');
                X.trangThai.value = sua ? (NHAN_TRANG_THAI[d.status] || 'Lưu tạm') : 'Tạo mới';

                VAT_PHIEU = Number(d.vat_percent || 0);
                X.vat.value = String(VAT_PHIEU);
                X.vatGui.value = String(VAT_PHIEU);

                $lineError.style.display = 'none';
                $linesEmpty.textContent = EMPTY_DAU;
                DONG = [];
                dongSeq = 0;
                veDong();

                $formOverlay.style.display = 'flex';
                setTimeout(() => O.supplier_id.focus(), 30);

                const poID = Number(d.purchase_order_id || 0);
                await napPhieuMua(Number(d.supplier_id || 0), poID);

                // Sửa phiếu: đổ lại lưới TỪ PHIẾU MUA rồi điền số cũ vào, chứ
                // không dựng thẳng từ dòng đã lưu. Trần trả hàng đổi theo ngày
                // (phiếu khác duyệt xen vào, kho hụt đi), nên nó phải là trần
                // của hôm nay — dựng từ dòng cũ là mở ra một ô nhập cho phép gõ
                // con số mà lượt duyệt sẽ từ chối.
                if (sua && poID > 0) {
                    const daLuu = {};
                    (d.items || []).forEach((it) => {
                        daLuu[Number(it.purchase_order_item_id || 0)] = Number(it.quantity || 0);
                    });
                    await napDongPhieuMua(poID, daLuu);
                }
            }

            document.getElementById('thnAddBtn').addEventListener('click', () => moForm('add', null));

            // Nút nào bấm thì phiếu đi đường đó: Lưu tạm chỉ ghi, Duyệt thì ghi
            // xong gọi tiếp đường duyệt (xem TraHangNhaCungCapController::store).
            document.getElementById('thnSaveDraft').addEventListener('click', () => {
                document.getElementById('thnDuyet').value = '0';
            });
            document.getElementById('thnSaveApprove').addEventListener('click', () => {
                document.getElementById('thnDuyet').value = '1';
            });

            $form.addEventListener('submit', (e) => {
                if (Number(O.supplier_id.value) <= 0) {
                    e.preventDefault();
                    toastErr('Chưa chọn nhà cung cấp.');
                    return;
                }

                const hopLe = DONG.filter((d) => d.quantity > 0);
                if (!hopLe.length) {
                    e.preventDefault();
                    toastErr('Phiếu chưa có dòng hàng nào có số lượng trả.');
                    return;
                }
                const qua = hopLe.find((d) => d.quantity > d.max);
                if (qua) {
                    e.preventDefault();
                    toastErr(`"${qua.product_name}" trả quá số cho phép — tối đa ${nhomSo(qua.max)} ${qua.unit_name || ''}`.trim() + '.');
                    return;
                }

                // Chỉ HAI khoá đi tới API: trả dòng nào, trả bao nhiêu. Giá nhập,
                // đơn vị, số lô, thuế suất API lấy lại từ dòng phiếu mua gốc.
                document.getElementById('thnItems').value = JSON.stringify(hopLe.map((d) => ({
                    purchase_item_id: d.purchase_item_id,
                    quantity: d.quantity,
                })));
                document.getElementById('thnItemsMeta').value = JSON.stringify(hopLe);
            });

            // =================================================================
            //  Hộp thoại Chi tiết
            // =================================================================
            const $detail = document.getElementById('thnDetailOverlay');
            const $detailBody = document.getElementById('thnDetailBody');
            const $detailFoot = document.getElementById('thnDetailFoot');

            const o = (nhan, giaTri, full) =>
                `<div class="thn-cell${full ? ' is-full' : ''}"><span class="thn-lb">${esc(nhan)}</span>`
                + `<span class="thn-vl">${esc(giaTri || '—')}</span></div>`;

            async function docPhieu(id) {
                const res = await fetch(`${URL_BASE}/${id}`, { headers: { Accept: 'application/json' } });
                const data = await res.json();
                if (!res.ok) throw new Error(data.message || 'Không đọc được phiếu trả hàng.');
                return data.data;
            }

            async function xemChiTiet(id) {
                $detailBody.innerHTML = '<p class="thn-lines-empty">Đang đọc phiếu…</p>';
                $detailFoot.innerHTML = '<button type="button" class="thn-btn-ghost" data-close>Đóng</button>';
                $detailFoot.querySelector('[data-close]').addEventListener('click', closeAll);
                $detail.style.display = 'flex';

                let p = null;
                try {
                    p = await docPhieu(id);
                } catch (err) {
                    $detailBody.innerHTML = `<p class="thn-lines-empty">${esc(err.message || 'Không đọc được phiếu.')}</p>`;
                    return;
                }

                veChiTiet(p);
            }

            function veChiTiet(p) {
                const tt = p.status || 'draft';
                document.getElementById('thnDetailTitle').textContent = 'Phiếu trả ' + (p.return_code || '');

                const dong = (p.items || []).map((it, i) => {
                    const ten = [it.product_name, it.variant_name].filter(Boolean).join(' · ');
                    return `<tr>
                        <td>${i + 1}</td>
                        <td class="is-left">${esc(ten)}<span class="thn-l-name-sub">${esc(it.variant_sku || '')}</span></td>
                        <td>${esc(it.unit_name || '—')}</td>
                        <td>${nhomSo(it.quantity)}</td>
                        <td>${nhomSo(it.purchase_quantity)}</td>
                        <td>${money(it.unit_cost)}</td>
                        <td>${money(it.line_amount)}</td>
                        <td><b>${money(it.total_cost)}</b></td>
                        <td>${esc(it.lot_number || '—')}</td>
                        <td>${ngay(it.expire_date)}</td>
                    </tr>`;
                }).join('');

                $detailBody.innerHTML = `
                    <div class="thn-view-grid">
                        ${o('Mã phiếu', p.return_code)}
                        ${o('Nhà cung cấp', [p.supplier_code, p.supplier_name].filter(Boolean).join(' - '))}
                        ${o('Phiếu mua gốc', p.purchase_order_code)}
                        ${o('Chi nhánh', p.branch_name)}
                        ${o('Địa chỉ', p.address)}
                        ${o('Địa chỉ 2', p.address_2)}
                        ${o('Điện thoại', p.supplier_phone)}
                        ${o('SĐT người liên hệ', p.contact_phone)}
                        ${o('Ngày chứng từ', ngay(p.document_date))}
                        ${o('Ngày hết hạn', ngay(p.expired_date))}
                        ${o('Nhân viên mua hàng', [p.purchaser_code, p.purchaser_name].filter(Boolean).join(' - '))}
                        ${o('Số phiếu giao bên nhận', p.receiver_delivery_note)}
                        ${o('VAT', (p.vat_percent ?? 0) + '%')}
                        ${o('Người lập', [p.creator_code, p.creator_name].filter(Boolean).join(' - '))}
                        ${o('Ngày lập', gioNgay(p.created_at))}
                        ${o('Loại chứng từ', LOAI_CT)}
                        <div class="thn-cell"><span class="thn-lb">Trạng thái phiếu</span>
                            <span class="thn-vl"><span class="thn-chip is-${MAU_TRANG_THAI[tt] || 'off'}">${esc(NHAN_TRANG_THAI[tt] || tt)}</span></span></div>
                        <div class="thn-cell"><span class="thn-lb">Trạng thái kho</span>
                            <span class="thn-vl"><span class="thn-chip is-${MAU_KHO[tt] || 'off'}">${esc(NHAN_KHO[tt] || '—')}</span></span></div>
                        ${p.note ? o('Ghi chú', p.note, true) : ''}
                    </div>

                    <table class="thn-view-table">
                        <thead><tr>
                            <th>#</th><th>Hàng hóa</th><th>Đơn vị tính</th><th>Số lượng trả</th><th>Số lượng nhập</th>
                            <th>Giá nhập</th><th>Thành tiền</th><th>Tổng tiền (VAT)</th><th>Số lô</th><th>Hạn dùng</th>
                        </tr></thead>
                        <tbody>${dong || '<tr><td colspan="10" class="thn-lines-empty">Phiếu không có dòng hàng nào.</td></tr>'}</tbody>
                    </table>

                    <div class="thn-money">
                        <div class="thn-money-row"><span class="thn-money-lb">Tổng tiền trước thuế</span><span class="thn-money-vl">${money(p.items_amount)}</span></div>
                        <div class="thn-money-row"><span class="thn-money-lb">Tổng tiền thuế</span><span class="thn-money-vl">${money(p.vat_amount)}</span></div>
                        <div class="thn-money-row is-total"><span class="thn-money-lb">Tổng tiền</span><span class="thn-money-vl">${money(p.total_amount)}</span></div>
                    </div>
                `;

                // Nút chân hộp dựng theo cờ API trả về, không tự đoán từ trạng thái.
                const nut = ['<button type="button" class="thn-btn-ghost" data-close>Đóng</button>',
                    `<button type="button" class="thn-btn-ghost" data-in="${p.id}">In phiếu</button>`];
                if (p.can_edit) {
                    nut.push(`<button type="button" class="thn-btn-danger" data-xoa="${p.id}">Xoá phiếu</button>`);
                    nut.push(`<button type="button" class="thn-btn-ghost" data-sua="${p.id}">Sửa</button>`);
                }
                if (p.can_approve) {
                    nut.push(`<button type="button" class="thn-btn-primary" data-duyet="${p.id}">Duyệt &amp; xuất kho</button>`);
                }
                $detailFoot.innerHTML = nut.join('');
                $detailFoot.querySelectorAll('[data-close]').forEach((b) => b.addEventListener('click', closeAll));
                $detailFoot.querySelector('[data-in]')?.addEventListener('click', () => inPhieu(p));
                $detailFoot.querySelector('[data-sua]')?.addEventListener('click', () => { closeAll(); moForm('edit', p); });
                $detailFoot.querySelector('[data-duyet]')?.addEventListener('click', () => duyet(p));
                $detailFoot.querySelector('[data-xoa]')?.addEventListener('click', () => xoa(p));
            }

            // ---------- Duyệt / xoá ----------
            function duyet(p) {
                const ten = p.return_code || ('#' + p.id);
                window.sysConfirm({
                    title: 'Duyệt phiếu trả và xuất kho',
                    message: `Duyệt phiếu ${ten}? Hàng rời kho ngay lúc này và phiếu khoá lại — `
                        + 'sau đó muốn chữa số đã trừ thì phải cân đối ở màn Tồn kho.',
                    confirmText: 'Duyệt & xuất kho',
                }).then((ok) => { if (ok) postForm(`${URL_BASE}/${p.id}/approve`, 'POST', {}); });
            }

            function xoa(p) {
                if (!p) return;
                window.sysDelete({
                    title: 'Xác nhận xoá phiếu trả hàng',
                    message: `Xoá phiếu ${p.return_code || ''}? Chỉ phiếu lưu tạm mới xoá được — `
                        + 'phiếu đã duyệt nằm lại trong sổ vì kho đã đổi theo nó.',
                    highlightText: `${p.return_code || ''} — ${p.supplier_name || ''}`,
                }).then((ok) => { if (ok) postForm(`${URL_BASE}/${p.id}`, 'DELETE', {}); });
            }

            // ---------- In một phiếu ----------
            //
            // Dựng tờ phiếu vào vùng ẩn rồi gọi in — cùng lối với bản in của v2,
            // chỉ khác là không cần iframe.
            const $printArea = document.getElementById('thnPrintArea');

            /** Dựng HTML một tờ phiếu; in một hay in nhiều đều đi qua đây. */
            function dungToPhieu(p) {
                const dong = (p.items || []).map((it, i) => `<tr>
                    <td>${i + 1}</td><td>${esc(it.variant_sku || '')}</td>
                    <td>${esc([it.product_name, it.variant_name].filter(Boolean).join(' · '))}</td>
                    <td>${esc(it.unit_name || '')}</td><td>${nhomSo(it.quantity)}</td>
                    <td>${money(it.unit_cost)}</td><td>${money(it.line_amount)}</td><td>${money(it.total_cost)}</td>
                    <td>${esc(it.lot_number || '')}</td><td>${it.expire_date ? ngay(it.expire_date) : ''}</td>
                </tr>`).join('');

                return `<div class="thn-print-doc">
                    <h2>Phiếu trả hàng nhà cung cấp</h2>
                    <p class="thn-print-sub">Số: ${esc(p.return_code || '')} · Ngày ${esc(ngay(p.document_date))}</p>
                    <table class="thn-print-info">
                        <tr><td>Nhà cung cấp:</td><td><b>${esc(p.supplier_name || '')}</b></td>
                            <td>Chi nhánh:</td><td>${esc(p.branch_name || TEN_CHI_NHANH || '')}</td></tr>
                        <tr><td>Địa chỉ:</td><td>${esc(p.address || '')}</td>
                            <td>Điện thoại:</td><td>${esc(p.supplier_phone || '')}</td></tr>
                        <tr><td>Phiếu mua gốc:</td><td>${esc(p.purchase_order_code || '')}</td>
                            <td>Người lập:</td><td>${esc(p.creator_name || '')}</td></tr>
                    </table>
                    <table class="thn-print-lines">
                        <thead><tr>
                            <th>STT</th><th>Mã hàng</th><th>Tên hàng hóa</th><th>ĐVT</th><th>SL trả</th>
                            <th>Giá nhập</th><th>Thành tiền</th><th>Tổng (VAT)</th><th>Số lô</th><th>Hạn dùng</th>
                        </tr></thead>
                        <tbody>${dong}</tbody>
                        <tfoot><tr>
                            <td colspan="6"><b>Tổng cộng</b></td>
                            <td><b>${money(p.items_amount)}</b></td>
                            <td colspan="3"><b>${money(p.total_amount)}</b></td>
                        </tr></tfoot>
                    </table>
                    ${p.note ? `<p>Ghi chú: ${esc(p.note)}</p>` : ''}
                    <div class="thn-print-sign">
                        <div>Người lập phiếu<br><i>(ký, ghi rõ họ tên)</i></div>
                        <div>Người nhận hàng<br><i>(ký, ghi rõ họ tên)</i></div>
                    </div>
                </div>`;
            }

            /** Đưa mấy tờ phiếu vào vùng in rồi gọi in; xong thì dọn sạch. */
            function inHtml(to) {
                if (!to.length) { toastErr('Không đọc được phiếu nào để in.'); return; }
                $printArea.innerHTML = to.join('<div style="page-break-after:always"></div>');
                document.body.classList.add('thn-in-phieu');
                window.print();
                setTimeout(() => {
                    document.body.classList.remove('thn-in-phieu');
                    $printArea.innerHTML = '';
                }, 300);
            }

            const inPhieu = (p) => inHtml([dungToPhieu(p)]);

            /** In từ nút ngoài bảng: phải đọc phiếu trước vì bảng không giữ dòng hàng. */
            async function inTheoId(id) {
                try {
                    inPhieu(await docPhieu(id));
                } catch (err) {
                    toastErr(err.message || 'Không đọc được phiếu để in.');
                }
            }

            /** In nhiều phiếu một lượt — mỗi phiếu một trang giấy. */
            async function inNhieu(ids) {
                const to = [];
                for (const id of ids) {
                    try {
                        to.push(dungToPhieu(await docPhieu(id)));
                    } catch (e) { /* thiếu một phiếu còn hơn hỏng cả lượt in */ }
                }
                inHtml(to);
            }

            // =================================================================
            //  Sự kiện bảng
            // =================================================================
            const tbody = document.querySelector('.thn-table tbody');
            tbody.addEventListener('click', (e) => {
                if (e.target.closest('a') || e.target.closest('input')) return;

                const rm = e.target.closest('[data-remove]');
                if (rm) { xoa(BY_ID.get(Number(rm.dataset.remove))); return; }
                const pr = e.target.closest('[data-print]');
                if (pr) { inTheoId(Number(pr.dataset.print)); return; }
                const ed = e.target.closest('[data-edit]');
                if (ed) { moSua(Number(ed.dataset.edit)); return; }
                const dt = e.target.closest('[data-detail]');
                if (dt) { xemChiTiet(Number(dt.dataset.detail)); }
            });

            /** Sửa phải đọc lại phiếu: dòng hàng không nằm trong bảng danh sách. */
            async function moSua(id) {
                try {
                    moForm('edit', await docPhieu(id));
                } catch (err) {
                    toastErr(err.message || 'Không đọc được phiếu.');
                }
            }

            // ---------- Chọn dòng + thanh hàng loạt ----------
            const chon = new Set();
            const $bulk = document.getElementById('thnBulkMount');
            const checkAll = document.getElementById('thnCheckAll');
            const rowChecks = () => Array.from(document.querySelectorAll('.thn-row-check'));

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
                    <div class="thn-bulk">
                        <span class="thn-bulk-count">Đã chọn <b>${n}</b> phiếu</span>
                        <button type="button" class="thn-bulk-clear" id="thnBulkClear">Bỏ chọn</button>
                        <div class="thn-bulk-actions">
                            <button type="button" class="thn-btn-ghost" data-bulk="print">In (${n})</button>
                            ${nhap > 0 ? `<button type="button" class="thn-btn-danger" data-bulk="del">Xoá (${nhap})</button>` : ''}
                            ${nhap === 0 ? '<span class="thn-bulk-count thn-muted">Phiếu đã duyệt không xoá được nữa</span>' : ''}
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
                if (e.target.closest('#thnBulkClear')) {
                    chon.clear();
                    rowChecks().forEach((cb) => { cb.checked = false; cb.closest('tr').classList.remove('is-selected'); });
                    syncHeader(); veBulk();
                    return;
                }

                const nut = e.target.closest('[data-bulk]');
                if (!nut) return;

                if (nut.dataset.bulk === 'print') {
                    inNhieu([...chon]);
                    return;
                }

                // Chỉ gửi phiếu lưu tạm: phiếu khác API từ chối.
                const ids = [...chon].filter((id) => (BY_ID.get(id)?.status) === 'draft');
                if (!ids.length) return;

                window.sysDelete({
                    title: 'Xác nhận xoá hàng loạt',
                    message: `Xoá ${ids.length} phiếu trả lưu tạm đã chọn?`,
                    highlightText: `Số lượng: ${ids.length} phiếu`,
                }).then((ok) => { if (ok) postForm(URL_BULK_DEL, 'POST', idFields(ids)); });
            });

            // ---------- Ba dropdown: Tiện ích, Xem cột và Nâng cao ----------
            const dropdowns = [
                { box: document.getElementById('thnUtil'), btn: document.getElementById('thnUtilBtn') },
                { box: document.getElementById('thnCotBox'), btn: document.getElementById('thnCotBtn') },
                { box: document.getElementById('thnNangCao'), btn: document.getElementById('thnNangCaoBtn') },
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
                d.box.querySelector('.thn-util-menu').addEventListener('click', (e) => e.stopPropagation());
            });
            document.addEventListener('click', dongDropdown);
            document.getElementById('thnPrintBtn').addEventListener('click', () => { dongDropdown(); window.print(); });

            // ---------- Xem cột ----------
            const COT_KEY = 'thn-cot-an';
            const $cotCss = document.getElementById('thnCotCss');
            const $cotCbs = Array.from(document.querySelectorAll('.thn-cot-cb'));
            const $cotAll = document.getElementById('thnCotAll');

            function veCot() {
                const an = $cotCbs.filter((cb) => !cb.checked).map((cb) => cb.dataset.cot);
                $cotCss.textContent = an.length
                    ? an.map((c) => `.thn-table .thn-c-${c}`).join(',') + '{display:none}'
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

            // Lưu hỏng thì mở lại hộp thoại kèm ĐÚNG những gì vừa gõ, cả lưới hàng.
            @if(old('items'))
                (function () {
                    const cu = @json(old());
                    toastErr(@json(session('error') ?: 'Phiếu chưa lưu được — dữ liệu vừa nhập vẫn còn trong hộp thoại.'));

                    const id = Number(cu.id) || 0;
                    moForm(id ? 'edit' : 'add', id ? { id, supplier_id: cu.supplier_id } : null).then(() => {
                        O.supplier_id.value = String(cu.supplier_id || 0);
                        O.expired_date.value = cu.expired_date || '';
                        O.purchaser_id.value = String(cu.purchaser_id || 0);
                        O.receiver_delivery_note.value = cu.receiver_delivery_note || '';
                        O.note.value = cu.note || '';
                        veHoSoNCC();
                        if (cu.purchase_order_id) $phieuMua.value = String(cu.purchase_order_id);
                        X.vat.value = String(cu.vat_percent ?? 0);
                        X.vatGui.value = String(cu.vat_percent ?? 0);

                        // Lưới hàng đổ lại TỪ PHIẾU MUA rồi điền số vừa gõ vào —
                        // cùng lối với lượt mở phiếu để sửa.
                        try {
                            const dong = JSON.parse(cu.items_meta || '[]');
                            const daGo = {};
                            (Array.isArray(dong) ? dong : []).forEach((d) => {
                                daGo[Number(d.purchase_item_id || 0)] = Number(d.quantity || 0);
                            });
                            const po = Number(cu.purchase_order_id || 0);
                            if (po > 0) napDongPhieuMua(po, daGo);
                        } catch (e) { /* mất lưới hàng còn hơn mất cả trang */ }
                    });
                })();
            @endif
        })();
    </script>
@endsection
