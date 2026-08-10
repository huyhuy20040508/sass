@extends('layouts.app')

@section('title', $meta['title'])

@section('content')
    {{--
        Khuôn chung cho MỌI trang cấu hình (Cấu hình cửa hàng / Vận chuyển / Kho).
        Mỗi trang là một nhóm khoá, chọn bằng $group; nội dung form dựng từ `fields`
        do API trả về (registry trong setting_service.go) nên thêm khoá mới bên Go
        là hiện ngay, không phải sửa Blade.

        KHÔNG phải trang danh sách nên không có bộ lọc, phân trang hay thanh thao
        tác hàng loạt. Vẫn dùng chung hệ màu, hiệu ứng focus, icon SVG và toast với
        các trang còn lại.

        Nút Lưu KHÔNG bị disable khi chưa có thay đổi — bấm vào sẽ nói rõ "chưa có
        gì để lưu" thay vì im lặng.
    --}}
    @php
        $MONEY_KEYS = \App\Http\Controllers\SettingController::MONEY_KEYS;
        // Giá trị đang hiển thị: ưu tiên dữ liệu vừa nhập (khi lưu hỏng quay lại),
        // sau đó mới tới giá trị đọc từ API.
        $valueOf = fn (string $key) => old('items.'.$key, $values[$key] ?? '');
    @endphp

    <div class="set">
        {{-- Header --}}
        <div class="set-head">
            <div class="set-head-text">
                <h1 class="set-title">{{ $meta['title'] }}</h1>
                <p class="set-sub">{{ $meta['sub'] }}</p>
            </div>

            <div class="set-head-actions">
                <button type="button" class="set-btn-ghost" id="setResetBtn">
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/></svg>
                    Hoàn tác
                </button>
                <button type="submit" form="setForm" class="set-btn-primary" id="setSaveBtn">
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2Z"/><path d="M17 21v-8H7v8M7 3v5h8"/></svg>
                    Lưu thay đổi
                </button>
            </div>
        </div>

        @if(empty($sections))
            {{-- Không đọc được cấu hình: nói thẳng, không dựng form rỗng cho người
            dùng gõ vào rồi mất trắng lúc bấm Lưu. --}}
            <div class="set-empty">
                <svg viewBox="0 0 24 24" width="32" height="32" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16.5v.01"/></svg>
                <p class="set-empty-title">Chưa tải được cấu hình</p>
                <p class="set-empty-sub">Máy chủ API không trả về danh sách khoá cấu hình. Kiểm tra API rồi tải lại trang.</p>
                <button type="button" class="set-btn-primary" onclick="window.location.reload()">Tải lại trang</button>
            </div>
        @else
            <form method="POST" action="{{ route('admin.settings.update', $group) }}" id="setForm" class="set-form">
                @csrf
                @method('PUT')

                {{-- Trang một khối KHÔNG hiện tiêu đề khối: tên khối lúc đó chỉ lặp
                     lại tiêu đề trang, đẩy ô nhập xuống thêm một tầng vô ích.
                     Controller trả `title` rỗng đúng cho trường hợp đó. --}}
                @foreach($sections as $section)
                    @php $controller = $section['controlled_by'] ?? ''; @endphp

                    @if($controller !== '')
                        {{-- Khối chỉ dùng tới khi một công tắc đang bật (tài khoản nhận
                             chuyển khoản ~ công tắc "Chuyển khoản ngân hàng").

                             Tắt công tắc thì khối gập lại và KHÔNG mở ra được: mấy ô đó
                             lúc ấy không chạy vào đâu cả, để mở toang chỉ mời người dùng
                             điền một thứ vô tác dụng. Bật lên thì tự trượt ra, và từ đó
                             bấm tiêu đề để đóng/mở tuỳ ý.

                             Ô nhập vẫn nằm nguyên trong form khi gập, nên tắt chuyển
                             khoản rồi lưu KHÔNG xoá mất số tài khoản đã khai. --}}
                        <div class="set-block" data-collapse data-controller="set_{{ $controller }}">
                            <button type="button" class="set-section set-section-btn"
                                    data-collapse-head aria-expanded="true" aria-controls="set_body_{{ $section['code'] }}">
                                <svg class="set-collapse-caret" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                                <span>{{ $section['title'] }}</span>
                                <span class="set-collapse-note" data-collapse-note hidden>Bật công tắc ở trên rồi mới khai được</span>
                            </button>

                            <div class="set-collapse" id="set_body_{{ $section['code'] }}" data-collapse-body>
                                <div class="set-collapse-inner" data-collapse-inner>
                                    @include('settings.partials.fields-grid', ['section' => $section])
                                </div>
                            </div>
                        </div>
                    @else
                        @if($section['title'] !== '')
                            <h3 class="set-section">{{ $section['title'] }}</h3>
                        @endif

                        @include('settings.partials.fields-grid', ['section' => $section])
                    @endif
                @endforeach
            </form>

            {{-- Input file dùng chung cho mọi ô ảnh, nằm ngoài form để không bị gửi kèm. --}}
            <input type="file" id="setLogoFile" accept="image/*" hidden>
        @endif
    </div>

    <style>
        .set {
            /* Phá padding p-4 của <main> để tràn viền như mọi trang quản trị khác */
            margin: -1.5rem;
            min-height: calc(100vh - 56px);
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            color: #262626; background: #fff;
        }

        /* Header — cùng chiều cao, cỡ chữ và khoảng cách với header trang danh sách */
        .set-head {
            display: flex; align-items: center; justify-content: space-between;
            gap: 16px; flex-wrap: wrap;
            padding: 14px 20px; border-bottom: 1px solid #eee;
        }
        .set-head-text { min-width: 0; }
        .set-title { margin: 0; font-size: 16px; font-weight: 700; color: #262626; line-height: 24px; }
        .set-sub { margin: 2px 0 0; font-size: 13px; color: #8c8c8c; }
        .set-head-actions { display: flex; align-items: center; gap: 8px; flex-shrink: 0; }

        /* Nút — cùng khuôn với .sup-btn-* của các trang danh sách */
        .set-btn-primary {
            height: 34px; display: inline-flex; align-items: center; gap: 6px; border: 0; border-radius: 4px;
            background: #1890ff; color: #fff; padding: 0 16px; font-size: 13px; font-weight: 500; cursor: pointer;
            transition: background .15s;
        }
        .set-btn-primary:hover { background: #40a9ff; }
        .set-btn-primary svg { flex-shrink: 0; }
        .set-btn-ghost {
            height: 34px; display: inline-flex; align-items: center; gap: 6px; border: 1px solid #d9d9d9;
            border-radius: 4px; background: #fff; padding: 0 16px; font-size: 13px; font-weight: 500;
            color: #595959; cursor: pointer; transition: border-color .15s, color .15s;
        }
        .set-btn-ghost:hover { border-color: #bfbfbf; }
        .set-btn-danger:hover { border-color: #ff4d4f; color: #ff4d4f; }
        .set-btn-primary:focus-visible, .set-btn-ghost:focus-visible {
            outline: none; box-shadow: 0 0 0 .25rem rgba(13, 110, 253, .25);
        }

        /* Lưới ô nhập trải hết chiều ngang: nhãn nằm TRÊN ô, giống mọi form khác
           trong hệ thống (modal thêm/sửa của Nhà cung cấp, Người dùng…). */
        /* Tiêu đề khối — chỉ hiện khi trang có từ 2 khối trở lên.
           Khối thứ hai trở đi có đường kẻ phía trên để mắt tách được các khối. */
        .set-section {
            margin: 0; padding: 20px 20px 0;
            font-size: 12px; font-weight: 600; color: #8c8c8c;
            text-transform: uppercase; letter-spacing: .04em;
        }
        .set-grid + .set-section,
        .set-grid + .set-block {
            margin-top: 4px; padding-top: 24px; border-top: 1px solid #f0f0f0;
        }
        .set-section + .set-grid { padding-top: 14px; }

        /* ----- Khối gập được ----- */
        /* Tiêu đề khối thành nút bấm nhưng giữ nguyên dáng chữ của .set-section,
           để hai kiểu khối trên cùng trang không nhìn như hai hệ thống khác nhau. */
        .set-block { padding-top: 24px; }
        .set-section-btn {
            display: flex; align-items: center; gap: 8px; width: 100%;
            border: 0; background: transparent; cursor: pointer; text-align: left;
            font-family: inherit; padding: 0 20px;
        }
        .set-section-btn:focus-visible { outline: none; box-shadow: 0 0 0 .25rem rgba(13, 110, 253, .25); }
        .set-collapse-caret { flex-shrink: 0; color: #bfbfbf; transition: transform .2s ease; }
        .set-block:not(.is-open) .set-collapse-caret { transform: rotate(-90deg); }
        /* Khối đang khoá: chữ nhạt đi để thấy ngay là chưa dùng tới, kèm câu nói rõ
           vì sao — không để người dùng bấm mãi vào một tiêu đề không mở ra gì. */
        .set-block.is-locked .set-section-btn { cursor: default; }
        .set-block.is-locked .set-section-btn > span:first-of-type { color: #bfbfbf; }
        .set-collapse-note { font-size: 11px; font-weight: 400; color: #bfbfbf; text-transform: none; letter-spacing: 0; }

        /* Trượt ra/vào bằng grid-template-rows: 0fr -> 1fr — cách duy nhất chạy
           mượt với nội dung cao bao nhiêu cũng được mà không phải đo bằng script.
           Trình duyệt cũ không chạy hiệu ứng thì vẫn đóng/mở đúng, chỉ là nhảy cái. */
        .set-collapse {
            display: grid; grid-template-rows: 0fr; opacity: 0;
            transition: grid-template-rows .24s ease, opacity .18s ease;
        }
        .set-block.is-open .set-collapse { grid-template-rows: 1fr; opacity: 1; }
        /* overflow: hidden là thứ cắt phần thừa lúc đang gập. Nhưng nó cũng cắt luôn
           danh sách ngân hàng đổ xuống, nên mở xong thì script mở khoá lại (is-done). */
        /* visibility: ô nhập trong khối đang gập vẫn nằm trong form (để không mất dữ
           liệu đã khai) nhưng KHÔNG được nhận con trỏ khi bấm Tab — gõ vào một ô
           không nhìn thấy là kiểu lỗi không ai lần ra được. Đổi trễ đúng bằng thời
           gian gập để nội dung không biến mất giữa chừng lúc đang trượt vào. */
        .set-collapse-inner {
            overflow: hidden; min-height: 0;
            visibility: hidden; transition: visibility 0s linear .24s;
        }
        .set-block.is-open .set-collapse-inner { visibility: visible; transition: visibility 0s; }
        .set-block.is-open .set-collapse.is-done .set-collapse-inner { overflow: visible; }

        /* Nháy sáng dòng công tắc khi người dùng bấm vào khối đang khoá */
        @keyframes setFlash {
            0%, 100% { background: transparent; }
            25%, 75% { background: #e6f4ff; }
        }
        .set-field.is-flash { animation: setFlash 1.4s ease; }

        @media (prefers-reduced-motion: reduce) {
            .set-collapse, .set-collapse-caret { transition: none; }
            .set-field.is-flash { animation: none; box-shadow: inset 0 0 0 2px #91caff; }
        }

        /* ĐÚNG 2 cột. Số ô của mỗi khối đều chẵn (hoặc có ô trải hàng ở giữa) nên
           2 cột lấp kín mọi hàng; 3 cột thì hàng nào cũng hụt một ô, nhìn rách.
           align-items: start để ô chữ không bị kéo cao bằng ô ảnh cùng hàng. */
        .set-grid {
            display: grid; grid-template-columns: repeat(2, minmax(0, 1fr));
            align-items: start;
            gap: 20px 24px; padding: 18px 20px 28px;
        }
        @media (max-width: 820px) { .set-grid { grid-template-columns: 1fr; } }

        .set-field { min-width: 0; }
        .set-field.is-wide { grid-column: 1 / -1; }

        /* Dòng công tắc: trải hết chiều ngang, nhãn trái — công tắc phải.
           Kẻ ngang giữa các dòng để mắt bám được từng dòng một, giống bảng dữ liệu. */
        .set-field.is-toggle {
            grid-column: 1 / -1;
            display: flex; align-items: center; justify-content: space-between; gap: 24px;
            padding: 14px 0; border-bottom: 1px solid #f0f0f0;
        }
        .set-field.is-toggle:first-child { padding-top: 4px; }
        .set-field.is-toggle:last-child { border-bottom: 0; padding-bottom: 4px; }
        /* Lời giải thích dài hơn nửa trang thì mắt phải quét quá xa mới tới công tắc */
        .set-toggle-text { min-width: 0; max-width: 620px; }
        .set-field.is-toggle .set-label { margin: 0; }
        .set-field.is-toggle .set-hint,
        .set-field.is-toggle .set-error { margin-top: 2px; }
        .set-label {
            margin: 0 0 4px; font-size: 13px; font-weight: 500; color: #262626;
            display: flex; align-items: center; gap: 6px; flex-wrap: wrap;
        }
        .set-req { color: #ff4d4f; }
        /* Nhãn "Nội bộ" — xám nhạt, chỉ để phân biệt chứ không kéo mắt về phía nó */
        .set-tag {
            padding: 1px 6px; border-radius: 4px;
            background: #f5f5f5; color: #8c8c8c;
            font-size: 11px; font-weight: 500; cursor: help;
        }
        .set-control { min-width: 0; }

        .set-input {
            width: 100%; height: 36px; box-sizing: border-box;
            padding: 0 12px; border: 1px solid #d9d9d9; border-radius: 4px;
            font-size: 13px; color: #262626; background: #fff; outline: none;
            transition: border-color .15s;
        }
        .set-input::placeholder { color: #bfbfbf; }
        .set-input.is-invalid { border-color: #ff4d4f; }
        .set-input-wrap { position: relative; }
        .set-input-num { padding-right: 76px; text-align: right; }
        .set-unit {
            position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
            font-size: 12px; color: #8c8c8c; pointer-events: none;
        }
        /* Công tắc bật/tắt — cao 36px đúng bằng ô nhập để các ô cùng hàng thẳng đáy */
        .set-switch {
            display: inline-flex; align-items: center; gap: 10px; height: 36px;
            cursor: pointer; user-select: none;
        }
        .set-switch input { position: absolute; opacity: 0; width: 0; height: 0; }
        .set-switch-track {
            position: relative; width: 40px; height: 22px; flex-shrink: 0;
            border-radius: 9999px; background: #d9d9d9; transition: background .15s;
        }
        .set-switch-knob {
            position: absolute; top: 3px; left: 3px; width: 16px; height: 16px;
            border-radius: 9999px; background: #fff; transition: transform .15s;
            box-shadow: 0 1px 2px rgba(0, 0, 0, .2);
        }
        .set-switch input:checked + .set-switch-track { background: #1890ff; }
        .set-switch input:checked + .set-switch-track .set-switch-knob { transform: translateX(18px); }
        .set-switch input:focus-visible + .set-switch-track { box-shadow: 0 0 0 .25rem rgba(13, 110, 253, .25); }
        /* Rộng cố định: "Đang bật" và "Đang tắt" lệch nhau vài pixel, không ghim lại
           thì công tắc của hai dòng kề nhau không thẳng cột. */
        .set-switch-text { font-size: 13px; color: #595959; min-width: 58px; }

        /* Ô chọn có tìm kiếm */
        .set-combo { position: relative; }
        .set-combo-input { padding-right: 34px; }
        .set-combo-caret {
            position: absolute; right: 1px; top: 1px; height: 34px; width: 32px;
            display: flex; align-items: center; justify-content: center;
            border: 0; background: transparent; color: #8c8c8c; cursor: pointer; border-radius: 0 4px 4px 0;
        }
        .set-combo-caret:hover { color: #262626; }
        .set-combo.is-open .set-combo-caret svg { transform: rotate(180deg); }
        /* Panel: đóng sẵn, bấm vào mới trượt ra.
           Trạng thái đóng dùng visibility + opacity chứ không display:none, vì
           display không có bước chuyển tiếp nào để chạy hiệu ứng. visibility đổi
           TRỄ đúng bằng thời gian trượt lúc đóng (transition-delay), để panel biến
           mất hẳn sau khi trượt xong chứ không nhấp nháy mất trước. */
        .set-combo-panel {
            position: absolute; z-index: 30; left: 0; right: 0; top: calc(100% + 4px);
            background: #fff; border: 1px solid #e5e7eb; border-radius: 4px;
            box-shadow: 0 6px 16px rgba(0, 0, 0, .12);
            max-height: 268px; overflow-y: auto; overscroll-behavior: contain;
            opacity: 0; visibility: hidden; transform: translateY(-6px);
            transform-origin: top center;
            transition: opacity .14s ease, transform .16s ease, visibility 0s linear .16s;
        }
        .set-combo.is-open .set-combo-panel {
            opacity: 1; visibility: visible; transform: none;
            transition: opacity .14s ease, transform .16s ease, visibility 0s;
        }
        /* Máy đặt "giảm chuyển động": bỏ hiệu ứng, chỉ hiện/ẩn. */
        @media (prefers-reduced-motion: reduce) {
            .set-combo-panel, .set-combo.is-open .set-combo-panel { transition: none; }
        }
        .set-combo-list { margin: 0; padding: 4px 0; list-style: none; }
        .set-combo-item {
            display: flex; flex-direction: column; gap: 1px;
            padding: 7px 12px; cursor: pointer;
        }
        .set-combo-item b { font-size: 13px; font-weight: 500; color: #262626; }
        .set-combo-item span { font-size: 11px; color: #8c8c8c; }
        /* is-active = dòng đang chọn bằng phím mũi tên; hover dùng chung một màu để
           bàn phím và chuột không vẽ ra hai "dòng đang chọn" khác nhau. */
        .set-combo-item:hover, .set-combo-item.is-active { background: #f5f5f5; }
        .set-combo-item.is-current b { color: #1890ff; }
        .set-combo-empty { margin: 0; padding: 12px; font-size: 12px; color: #8c8c8c; line-height: 1.5; }

        .set-hint { margin: 4px 0 0; font-size: 11px; color: #8c8c8c; line-height: 1.5; }
        .set-error { margin: 4px 0 0; font-size: 11px; color: #ff4d4f; line-height: 1.5; }

        /* Ô ảnh logo */
        .set-logo { display: flex; align-items: center; gap: 16px; flex-wrap: wrap; }
        .set-logo-preview {
            width: 96px; height: 96px; flex-shrink: 0;
            display: flex; align-items: center; justify-content: center;
            border: 1px solid #e5e7eb; border-radius: 4px; background: #fafafa;
            overflow: hidden;
        }
        .set-logo-preview.is-empty { border-style: dashed; color: #bfbfbf; }
        .set-logo-preview img { max-width: 100%; max-height: 100%; object-fit: contain; }
        .set-logo-actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }

        /* Trạng thái không tải được cấu hình — trải hết bảng như dòng rỗng của bảng dữ liệu */
        .set-empty {
            display: flex; flex-direction: column; align-items: center; gap: 8px;
            padding: 64px 24px; color: #bfbfbf;
        }
        .set-empty-title { margin: 8px 0 0; font-size: 15px; font-weight: 600; color: #262626; }
        .set-empty-sub { margin: 0 0 8px; font-size: 13px; color: #8c8c8c; text-align: center; }
    </style>

    <script>
        (function () {
            var form = document.getElementById('setForm');
            if (!form) return;

            var saveBtn = document.getElementById('setSaveBtn');
            var resetBtn = document.getElementById('setResetBtn');
            var filePicker = document.getElementById('setLogoFile');

            // ----- Định dạng ô tiền kiểu Việt Nam: 1000000 -> 1.000.000 -----
            function fmtMoney(el) {
                var digits = (el.value || '').replace(/[^0-9]/g, '').replace(/^0+(?=\d)/, '');
                var max = parseInt(el.dataset.max || '0', 10);
                if (max > 0 && digits !== '' && Number(digits) > max) {
                    digits = String(max);
                }
                el.value = digits ? Number(digits).toLocaleString('vi-VN') : '';
            }

            form.querySelectorAll('[data-money]').forEach(function (el) {
                fmtMoney(el);
                el.addEventListener('input', function () { fmtMoney(el); });
            });

            // Ô số không phải tiền: chỉ giữ chữ số, không chấm phân cách.
            form.querySelectorAll('.set-input-num:not([data-money])').forEach(function (el) {
                el.addEventListener('input', function () {
                    var digits = (el.value || '').replace(/[^0-9]/g, '');
                    var max = parseInt(el.dataset.max || '0', 10);
                    if (max > 0 && digits !== '' && Number(digits) > max) digits = String(max);
                    el.value = digits;
                });
            });

            // ----- Ô chọn có tìm kiếm -----
            // Bỏ dấu tiếng Việt trước khi so khớp: gõ "ngoai thuong" hay "vietcom"
            // đều phải ra Vietcombank, không ai bật bộ gõ dấu chỉ để tìm một dòng.
            function plain(s) {
                return (s || '').toLowerCase()
                    .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
                    .replace(/\u0111/g, 'd');
            }

            form.querySelectorAll('[data-combo]').forEach(function (combo) {
                var input = combo.querySelector('[data-combo-input]');
                var panel = combo.querySelector('[data-combo-panel]');
                var empty = combo.querySelector('[data-combo-empty]');
                var items = Array.prototype.slice.call(combo.querySelectorAll('.set-combo-item'));
                var active = -1;
                // muted = đang tự thao tác lên ô nhập (chọn một dòng). Không có cờ này
                // thì việc gán giá trị + trả con trỏ về ô lại kích hoạt đúng hai thứ
                // vừa tắt: sự kiện input và sự kiện focus, panel mở lại ngay sau khi
                // người dùng vừa chọn xong.
                var muted = false;

                function visible() {
                    return items.filter(function (li) { return !li.hidden; });
                }

                function mark(list, index) {
                    list.forEach(function (li) { li.classList.remove('is-active'); });
                    active = index;
                    if (index >= 0 && list[index]) {
                        list[index].classList.add('is-active');
                        list[index].scrollIntoView({ block: 'nearest' });
                    }
                }

                // Lọc theo TỪNG TỪ: "vcb ngoai" hay "ngoai vcb" đều tìm ra, vì người
                // dùng không nhớ thứ tự chữ trong tên đầy đủ.
                function filter() {
                    var words = plain(input.value).split(/\s+/).filter(Boolean);
                    items.forEach(function (li) {
                        var hay = li.dataset.plain;
                        li.hidden = !words.every(function (w) { return hay.indexOf(w) !== -1; });
                        li.classList.toggle('is-current', li.dataset.value === input.value.trim());
                    });
                    var list = visible();
                    empty.hidden = list.length > 0;
                    mark(list, list.length ? 0 : -1);
                }

                // Mở panel để XEM cả danh sách, không lọc theo giá trị đang có.
                //
                // Ô đang là "Vietcombank" mà bấm mũi tên lại chỉ thấy đúng dòng
                // Vietcombank thì cái nút đó vô dụng — người bấm nó là người muốn đổi
                // sang ngân hàng khác. Dòng đang chọn được tô và cuộn tới, để biết
                // mình đang ở đâu trong danh sách.
                function showAll() {
                    var current = input.value.trim();
                    var at = -1;
                    items.forEach(function (li, i) {
                        li.hidden = false;
                        var isCurrent = li.dataset.value === current;
                        li.classList.toggle('is-current', isCurrent);
                        if (isCurrent) at = i;
                    });
                    empty.hidden = true;
                    mark(items, at);
                }

                // Trạng thái đóng/mở chỉ nằm ở MỘT chỗ: class .is-open của khung ngoài.
                // CSS bám vào đó để trượt panel ra/vào, nên ở đây không đụng tới
                // thuộc tính hidden nữa — hidden là display:none, hiệu ứng không chạy.
                function isOpen() {
                    return combo.classList.contains('is-open');
                }

                // mode 'all' = xem cả danh sách (bấm vào ô hoặc bấm mũi tên),
                // 'filter' = lọc theo chữ đang gõ.
                function open(mode) {
                    combo.classList.add('is-open');
                    input.setAttribute('aria-expanded', 'true');
                    if (mode === 'all') { showAll(); } else { filter(); }
                }

                function close() {
                    combo.classList.remove('is-open');
                    input.setAttribute('aria-expanded', 'false');
                    active = -1;
                }

                function choose(li) {
                    muted = true;
                    input.value = li.dataset.value;
                    // Sự kiện input phải tự bắn ra: gán value bằng script không kích
                    // hoạt nó, mà cả việc theo dõi "có thay đổi chưa" đang nghe ở đó.
                    input.dispatchEvent(new Event('input', { bubbles: true }));
                    close();
                    // Trả con trỏ về ô để gõ sửa tiếp được ngay (VD thêm tên chi nhánh).
                    input.focus();
                    muted = false;
                }

                items.forEach(function (li) {
                    li.dataset.plain = plain(li.dataset.search);
                    li.addEventListener('mousedown', function (e) {
                        // mousedown chứ không phải click: blur của input chạy trước
                        // click và đã đóng panel, cú click rơi vào khoảng không.
                        e.preventDefault();
                        choose(li);
                    });
                });

                // Mở khi BẤM VÀO ô, không phải khi ô nhận con trỏ. Chuyển tab qua ô
                // này, hay quay con trỏ về sau khi vừa chọn xong, đều không phải là
                // lúc người dùng muốn thấy danh sách bung ra.
                input.addEventListener('click', function () {
                    if (!isOpen()) open('all');
                });
                input.addEventListener('input', function () {
                    if (!muted) open('filter');
                });

                var toggle = combo.querySelector('[data-combo-toggle]');
                // Giữ con trỏ ở ô nhập khi bấm mũi tên: để nó nhảy sang nút thì ô nhập
                // mất focus, panel đóng theo blur rồi cú click lại mở ra — nút đóng
                // biến thành nút không bao giờ đóng được.
                toggle.addEventListener('mousedown', function (e) { e.preventDefault(); });
                toggle.addEventListener('click', function () {
                    if (isOpen()) { close(); } else { input.focus(); open('all'); }
                });

                // Bấm vào thanh cuộn hay khoảng trống trong panel cũng làm ô nhập mất
                // focus -> panel tự đóng giữa lúc người dùng đang cuộn tìm.
                panel.addEventListener('mousedown', function (e) { e.preventDefault(); });

                input.addEventListener('keydown', function (e) {
                    var list = visible();
                    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                        e.preventDefault();
                        if (!isOpen()) { open('all'); return; }
                        if (!list.length) return;
                        var next = active + (e.key === 'ArrowDown' ? 1 : -1);
                        if (next < 0) next = list.length - 1;
                        if (next >= list.length) next = 0;
                        mark(list, next);
                    } else if (e.key === 'Enter') {
                        // Chỉ nuốt phím Enter khi đang có dòng được chọn; nếu không thì
                        // để nguyên hành vi gửi form như mọi ô nhập khác.
                        if (isOpen() && active >= 0 && list[active]) {
                            e.preventDefault();
                            choose(list[active]);
                        }
                    } else if (e.key === 'Escape') {
                        if (isOpen()) { e.stopPropagation(); close(); }
                    }
                });

                input.addEventListener('blur', close);
            });

            // ----- Khối gập theo một công tắc -----
            form.querySelectorAll('[data-collapse]').forEach(function (block) {
                var head = block.querySelector('[data-collapse-head]');
                var body = block.querySelector('[data-collapse-body]');
                var note = block.querySelector('[data-collapse-note]');
                var control = document.getElementById(block.dataset.controller);

                function setOpen(open) {
                    block.classList.toggle('is-open', open);
                    head.setAttribute('aria-expanded', open ? 'true' : 'false');
                    // is-done chỉ gắn khi đã trượt xong: trong lúc trượt vẫn phải cắt
                    // phần thừa, mở xong mới thả để danh sách ngân hàng đổ ra ngoài được.
                    body.classList.remove('is-done');
                    if (open) {
                        window.setTimeout(function () {
                            if (block.classList.contains('is-open')) body.classList.add('is-done');
                        }, 260);
                    }
                }

                function sync(animate) {
                    var on = !control || control.checked;
                    block.classList.toggle('is-locked', !on);
                    note.hidden = on;
                    if (!animate) {
                        // Lúc dựng trang: đặt thẳng trạng thái, không cho trượt — mở
                        // trang mà thấy khối tự bung ra một cái thì tưởng mình lỡ bấm.
                        body.style.transition = 'none';
                    }
                    setOpen(on);
                    if (!animate) {
                        body.offsetHeight; // ép trình duyệt áp ngay rồi mới trả hiệu ứng lại
                        body.style.transition = '';
                    }
                }

                if (control) {
                    control.addEventListener('change', function () { sync(true); });
                }

                head.addEventListener('click', function () {
                    if (block.classList.contains('is-locked')) {
                        // Không mở, nhưng cũng không im lặng: nháy sáng đúng cái công
                        // tắc cần bật để người dùng biết bấm vào đâu.
                        var row = control && control.closest('.set-field');
                        if (row) {
                            row.classList.remove('is-flash');
                            row.offsetHeight;
                            row.classList.add('is-flash');
                            window.setTimeout(function () { row.classList.remove('is-flash'); }, 1400);
                        }
                        return;
                    }
                    setOpen(!block.classList.contains('is-open'));
                });

                sync(false);
            });

            // Chữ cạnh công tắc phải đổi theo, nếu không thì bấm tắt rồi vẫn đọc
            // thấy "Đang bật" — người dùng sẽ tưởng cú bấm không ăn.
            form.querySelectorAll('[data-bool]').forEach(function (el) {
                el.addEventListener('change', function () {
                    var text = el.parentElement.querySelector('.set-switch-text');
                    if (text) text.textContent = el.checked ? 'Đang bật' : 'Đang tắt';
                });
            });

            // ----- Theo dõi thay đổi -----
            // Chụp trạng thái ban đầu SAU khi đã định dạng lại ô tiền, nếu không mọi
            // trang vừa mở đã bị coi là "có thay đổi".
            function snapshot() {
                return new URLSearchParams(new FormData(form)).toString();
            }
            var initial = snapshot();
            var dirty = false;
            // saved = true khi form đang thực sự được gửi đi, để cảnh báo rời trang
            // không nổ ngay lúc bấm Lưu.
            var saved = false;

            function refresh() {
                dirty = snapshot() !== initial;
            }
            form.addEventListener('input', refresh);
            form.addEventListener('change', refresh);

            // Nút Lưu không bao giờ bị disable: chưa đổi gì thì nói ra lý do.
            form.addEventListener('submit', function (e) {
                if (!dirty) {
                    e.preventDefault();
                    window.sysConfirm({
                        type: 'info',
                        title: 'Chưa có thay đổi',
                        message: 'Các ô đang giữ nguyên giá trị đã lưu, không có gì để ghi lại.',
                        confirmText: 'Đã hiểu',
                        cancelText: 'Đóng'
                    });
                    return;
                }
                saved = true;
                saveBtn.disabled = true;
                saveBtn.style.opacity = '.7';
            });

            resetBtn.addEventListener('click', function () {
                if (!dirty) {
                    window.sysConfirm({
                        type: 'info',
                        title: 'Chưa có thay đổi',
                        message: 'Không có thay đổi nào để hoàn tác.',
                        confirmText: 'Đã hiểu',
                        cancelText: 'Đóng'
                    });
                    return;
                }
                window.sysConfirm({
                    title: 'Hoàn tác thay đổi',
                    message: 'Mọi chỉnh sửa chưa lưu trên trang này sẽ bị bỏ. Tiếp tục?',
                    confirmText: 'Hoàn tác'
                }).then(function (ok) {
                    if (ok) window.location.reload();
                });
            });

            // Rời trang khi còn thay đổi chưa lưu — cảnh báo của trình duyệt.
            window.addEventListener('beforeunload', function (e) {
                if (dirty && !saved) {
                    e.preventDefault();
                    e.returnValue = '';
                }
            });

            // ----- Ô ảnh logo -----
            var activeBox = null;

            form.querySelectorAll('[data-logo-box]').forEach(function (box) {
                var input = box.parentElement.querySelector('[data-logo-input]');
                var preview = box.querySelector('[data-logo-preview]');
                var clearBtn = box.querySelector('[data-logo-clear]');

                box.querySelector('[data-logo-pick]').addEventListener('click', function () {
                    activeBox = { input: input, preview: preview, clearBtn: clearBtn, label: box.dataset.label || '' };
                    filePicker.value = '';
                    filePicker.click();
                });

                clearBtn.addEventListener('click', function () {
                    input.value = '';
                    preview.classList.add('is-empty');
                    preview.innerHTML = '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/></svg>';
                    clearBtn.hidden = true;
                    refresh();
                });
            });

            if (filePicker) {
                filePicker.addEventListener('change', function () {
                    var file = filePicker.files && filePicker.files[0];
                    if (!file || !activeBox) return;

                    var data = new FormData();
                    data.append('image', file);
                    data.append('_token', form.querySelector('input[name=_token]').value);

                    var box = activeBox;
                    fetch('{{ route('admin.settings.uploadLogo') }}', { method: 'POST', body: data })
                        .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, body: j }; }); })
                        .then(function (res) {
                            if (!res.ok || !res.body.url) {
                                throw new Error(res.body.message || 'Tải ảnh lên thất bại.');
                            }
                            box.input.value = res.body.url;
                            box.preview.classList.remove('is-empty');
                            // alt lấy theo nhãn của chính ô đó — trang này có cả logo,
                            // favicon lẫn mã QR, dán chung một chữ là mô tả sai ảnh.
                            box.preview.innerHTML = '<img alt="">';
                            box.preview.firstChild.alt = box.label;
                            box.preview.firstChild.src = res.body.url;
                            box.clearBtn.hidden = false;
                            refresh();
                        })
                        .catch(function (err) {
                            window.sysConfirm({
                                type: 'danger',
                                title: 'Không tải được ảnh',
                                message: err.message || 'Tải ảnh lên thất bại.',
                                confirmText: 'Đóng',
                                cancelText: 'Đóng'
                            });
                        });
                });
            }
        })();
    </script>
@endsection
