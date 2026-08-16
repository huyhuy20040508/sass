@extends('layouts.app')

@section('title', \App\Http\Controllers\ChiNhanhController::TITLE)

@section('content')
    {{--
        Trang "Chi nhánh" — các ĐIỂM BÁN của chính cửa hàng này.

        Dựng theo khuôn các trang danh sách khác: [header] + [bảng] + [modal CRUD].
        Không có thanh lọc và phân trang: số chi nhánh bị hạn mức hợp đồng chặn ở
        mức hàng đơn vị, nên một danh sách đầy đủ luôn vừa một màn hình — thêm bộ
        lọc vào đây là thêm thứ để bảo trì mà không ai dùng.

        HẠN MỨC ĐỨNG NGAY TRÊN ĐẦU chứ không giấu trong thông báo lỗi: người mở
        trang này ra là người đang cân nhắc mở thêm điểm bán, và họ cần biết còn
        mấy chỗ TRƯỚC khi điền form. Đụng trần thì nút "Mở chi nhánh" tự tắt kèm
        lý do — API vẫn chặn lại lần nữa, đây chỉ là nói trước.
    --}}
    @php
        $TITLE = \App\Http\Controllers\ChiNhanhController::TITLE;
        $EMPTY_TEXT = \App\Http\Controllers\ChiNhanhController::EMPTY_TEXT;

        $so = fn ($n) => number_format((int) $n, 0, ',', '.');

        // Trần 0 = không giới hạn (bản dịch của 'vo_han' bên bảng giá), KHÁC hẳn
        // "không được cái nào".
        $tran = $hanMuc['tran'] ?? null;
        $khongGioiHan = $tran === 0;
        $dangDung = $hanMuc['dang_dung'] ?? count($list);
        $hetCho = $tran !== null && ! $khongGioiHan && $dangDung >= $tran;

        // Số chi nhánh đang MỞ — khác tổng số: chi nhánh đã đóng vẫn chiếm một chỗ
        // của hạn mức nhưng không bán được gì.
        $dangMo = collect($list)->filter(fn ($c) => ! empty($c['is_active']))->count();

        $dt = fn ($s) => filled($s) ? \Illuminate\Support\Carbon::parse($s)->format('d/m/Y') : '';
    @endphp

    <div class="cnh">
        {{-- Header --}}
        <div class="cnh-head">
            <div>
                <h1 class="cnh-title">{{ $TITLE }}</h1>
                <p class="cnh-sub">
                    Điểm bán của cửa hàng bạn — kho, quầy, cơ sở. Đang mở
                    <b>{{ $so($dangMo) }}</b>/{{ $so(count($list)) }} chi nhánh.
                </p>
            </div>

            <div class="cnh-head-right">
                @if($tran !== null)
                    <span class="cnh-quota {{ $hetCho ? 'is-full' : '' }}">
                        <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="2.5" y="5" width="19" height="14" rx="2"/><path d="M2.5 9.5h19"/><path d="M6 14h4"/></svg>
                        @if($khongGioiHan)
                            <span>Gói {{ $hanMuc['ten_goi'] ?: 'hiện tại' }}: <b>không giới hạn</b> chi nhánh</span>
                        @else
                            <span>Gói {{ $hanMuc['ten_goi'] ?: 'hiện tại' }}: <b>{{ $so($dangDung) }}/{{ $so($tran) }}</b> chi nhánh</span>
                        @endif
                    </span>
                @endif

                {{-- Hết chỗ thì tắt nút kèm lý do, thay vì để người ta điền xong
                     form rồi mới nhận lỗi. --}}
                @if($hetCho)
                    <button type="button" class="cnh-btn-primary" disabled
                            title="Gói {{ $hanMuc['ten_goi'] ?: 'hiện tại' }} chỉ gồm {{ $so($tran) }} chi nhánh">
                        Mở chi nhánh
                    </button>
                @else
                    <button type="button" class="cnh-btn-primary" data-cnh-add>
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
                        Mở chi nhánh
                    </button>
                @endif
            </div>
        </div>

        @if($hetCho)
            <p class="cnh-callout">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16.2v.1"/></svg>
                <span>
                    Gói <b>{{ $hanMuc['ten_goi'] ?: 'hiện tại' }}</b> đã dùng hết {{ $so($tran) }} chi nhánh.
                    Đóng bớt một chi nhánh, hoặc
                    <a href="{{ route('admin.goi-dich-vu.index') }}">nâng gói dịch vụ</a> để mở thêm điểm bán.
                </span>
            </p>
        @endif

        @if(!empty($error))
            <p class="cnh-callout is-error">{{ $error }}</p>
        @endif

        {{-- Bảng --}}
        <div class="cnh-table-wrap">
            <table class="cnh-table">
                <thead>
                    <tr>
                        <th style="width:44px">#</th>
                        <th style="width:180px">Mã</th>
                        <th>Tên chi nhánh</th>
                        <th style="width:150px">Điện thoại</th>
                        <th>Địa chỉ</th>
                        <th style="width:120px">Trạng thái</th>
                        <th style="width:110px">Ngày mở</th>
                        <th style="width:120px"></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($list as $i => $cn)
                        <tr>
                            <td class="cnh-muted">{{ $i + 1 }}</td>
                            <td><code class="cnh-code">{{ $cn['code'] }}</code></td>
                            <td class="cnh-name">{{ $cn['name'] }}</td>
                            <td>{{ $cn['phone'] ?: '—' }}</td>
                            <td class="cnh-muted">{{ $cn['address'] ?: '—' }}</td>
                            <td>
                                @if(!empty($cn['is_active']))
                                    <span class="cnh-tag is-on">Đang mở</span>
                                @else
                                    <span class="cnh-tag is-off">Đã đóng</span>
                                @endif
                            </td>
                            <td class="cnh-muted">{{ $dt($cn['created_at'] ?? null) }}</td>
                            <td class="cnh-actions">
                                <button type="button" class="cnh-btn-icon" title="Sửa"
                                        data-cnh-edit
                                        data-id="{{ $cn['id'] }}"
                                        data-code="{{ $cn['code'] }}"
                                        data-name="{{ $cn['name'] }}"
                                        data-phone="{{ $cn['phone'] }}"
                                        data-address="{{ $cn['address'] }}"
                                        data-active="{{ !empty($cn['is_active']) ? 1 : 0 }}">
                                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                                </button>

                                {{-- Xoá là form riêng cho từng dòng: một nút xoá dùng
                                     chung với id nhét bằng JS thì lúc JS hỏng, nút vẫn
                                     bấm được và xoá nhầm dòng cuối cùng đã gán. --}}
                                <form method="POST" action="{{ route('admin.chi-nhanh.destroy', $cn['id']) }}"
                                      class="cnh-inline-form"
                                      onsubmit="return confirm('Xoá chi nhánh “{{ $cn['name'] }}”? Dữ liệu cũ phát sinh tại đây vẫn tra lại được.');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="cnh-btn-icon is-danger" title="Xoá">
                                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M6 6v14a1 1 0 0 0 1 1h10a1 1 0 0 0 1-1V6"/><path d="M10 11v6M14 11v6"/></svg>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="cnh-empty">{{ $EMPTY_TEXT }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Modal thêm / sửa --}}
    <div class="cnh-overlay" id="cnhOverlay" style="display:none;">
        <div class="cnh-dialog">
            <div class="cnh-modal-head">
                <h4 class="cnh-modal-title" id="cnhModalTitle">Mở chi nhánh</h4>
                <button type="button" class="cnh-modal-x" data-close>
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </div>

            <form id="cnhForm" method="POST" action="{{ route('admin.chi-nhanh.store') }}">
                @csrf
                {{-- _method rỗng khi TẠO, "PUT" khi sửa — Laravel bỏ qua giá trị rỗng. --}}
                <input type="hidden" name="_method" id="cnhMethod" value="">

                <div class="cnh-modal-body">
                    <div>
                        <label class="cnh-field-label" for="cnhName">Tên chi nhánh <span class="cnh-req">*</span></label>
                        <input type="text" id="cnhName" name="name" class="cnh-input" maxlength="150" required
                               autocomplete="off" placeholder="Ví dụ: Kho miền Bắc">
                    </div>

                    <div>
                        <label class="cnh-field-label" for="cnhCode">Mã chi nhánh</label>
                        <input type="text" id="cnhCode" name="code" class="cnh-input" maxlength="30" autocomplete="off"
                               placeholder="Bỏ trống để hệ thống tự đặt">
                        <p class="cnh-hint" id="cnhCodeHint">
                            Chữ thường không dấu, số, dấu chấm, gạch ngang hoặc gạch dưới.
                            Mã đi vào chứng từ của chi nhánh nên đặt xong thì hạn chế đổi.
                        </p>
                    </div>

                    <div class="cnh-row">
                        <div>
                            <label class="cnh-field-label" for="cnhPhone">Điện thoại</label>
                            <input type="text" id="cnhPhone" name="phone" class="cnh-input" maxlength="20" autocomplete="off">
                        </div>
                        <div>
                            <label class="cnh-field-label" for="cnhAddress">Địa chỉ</label>
                            <input type="text" id="cnhAddress" name="address" class="cnh-input" maxlength="255" autocomplete="off">
                        </div>
                    </div>

                    <label class="cnh-check">
                        <input type="checkbox" id="cnhActive" name="is_active" value="1" checked>
                        <span>Đang mở</span>
                    </label>
                    <p class="cnh-hint">
                        Chi nhánh đã đóng không bán được nữa nhưng vẫn giữ mã và vẫn chiếm
                        một chỗ trong hạn mức của gói — muốn nhả chỗ thì phải xoá hẳn.
                    </p>
                </div>

                <div class="cnh-modal-foot">
                    <button type="button" class="cnh-btn-ghost" data-close>Đóng</button>
                    <button type="submit" class="cnh-btn-primary" id="cnhSubmit">Mở chi nhánh</button>
                </div>
            </form>
        </div>
    </div>

    <style>
        .cnh {
            /* Phá padding p-4 của <main> để tràn viền như các trang danh sách khác */
            margin: -1.5rem;
            min-height: calc(100vh - 56px);
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            color: #262626; background: #fff;
        }

        .cnh-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; flex-wrap: wrap; padding: 14px 20px; }
        .cnh-title { margin: 0; font-size: 16px; font-weight: 700; line-height: 28px; }
        .cnh-sub { margin: 2px 0 0; font-size: 13px; color: #595959; }
        .cnh-sub b { color: #262626; }
        .cnh-head-right { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }

        .cnh-quota {
            display: inline-flex; align-items: center; gap: 6px;
            height: 34px; padding: 0 12px; border-radius: 4px;
            background: #f0f5ff; color: #1d39c4; border: 1px solid #adc6ff; font-size: 13px;
        }
        .cnh-quota.is-full { background: #fff2e8; color: #d4380d; border-color: #ffbb96; }

        .cnh-callout {
            display: flex; align-items: flex-start; gap: 8px;
            margin: 0 20px 12px; padding: 10px 12px; border-radius: 4px;
            background: #fff7e6; border: 1px solid #ffd591; color: #874d00; font-size: 13px;
        }
        .cnh-callout a { color: #874d00; font-weight: 600; }
        .cnh-callout.is-error { background: #fff1f0; border-color: #ffa39e; color: #a8071a; }

        .cnh-table-wrap { padding: 0 20px 24px; overflow-x: auto; }
        .cnh-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .cnh-table th {
            text-align: left; font-weight: 600; color: #595959; background: #fafafa;
            padding: 10px 12px; border-bottom: 1px solid #f0f0f0; white-space: nowrap;
        }
        .cnh-table td { padding: 10px 12px; border-bottom: 1px solid #f5f5f5; vertical-align: middle; }
        .cnh-table tbody tr:hover td { background: #fafafa; }
        .cnh-name { font-weight: 600; }
        .cnh-muted { color: #8c8c8c; }
        .cnh-code { font-size: 12px; background: #f5f5f5; border-radius: 3px; padding: 2px 6px; color: #595959; }
        .cnh-empty { text-align: center; color: #8c8c8c; padding: 32px 12px; }

        .cnh-tag { display: inline-block; padding: 1px 8px; border-radius: 10px; font-size: 12px; }
        .cnh-tag.is-on { background: #f6ffed; color: #389e0d; border: 1px solid #b7eb8f; }
        .cnh-tag.is-off { background: #fafafa; color: #8c8c8c; border: 1px solid #e8e8e8; }

        .cnh-actions { display: flex; align-items: center; gap: 4px; }
        .cnh-inline-form { display: inline; margin: 0; }
        .cnh-btn-icon {
            display: inline-flex; align-items: center; justify-content: center;
            width: 30px; height: 30px; border-radius: 4px;
            border: 1px solid #d9d9d9; background: #fff; color: #595959; cursor: pointer;
        }
        .cnh-btn-icon:hover { border-color: #0d6efd; color: #0d6efd; }
        .cnh-btn-icon.is-danger:hover { border-color: #ff4d4f; color: #ff4d4f; }

        .cnh-btn-primary {
            display: inline-flex; align-items: center; gap: 6px;
            height: 34px; padding: 0 14px; border-radius: 4px; border: 0;
            background: #0d6efd; color: #fff; font-size: 13px; font-weight: 600; cursor: pointer;
        }
        .cnh-btn-primary:hover { background: #0b5ed7; }
        .cnh-btn-primary:disabled { background: #d9d9d9; color: #8c8c8c; cursor: not-allowed; }
        .cnh-btn-ghost {
            height: 34px; padding: 0 14px; border-radius: 4px;
            border: 1px solid #d9d9d9; background: #fff; color: #595959; font-size: 13px; cursor: pointer;
        }

        /* Modal */
        .cnh-overlay { position: fixed; inset: 0; background: rgba(0,0,0,.45); z-index: 1050; display: flex; align-items: center; justify-content: center; padding: 16px; }
        .cnh-dialog { width: 100%; max-width: 560px; background: #fff; border-radius: 6px; box-shadow: 0 6px 24px rgba(0,0,0,.2); }
        .cnh-modal-head { display: flex; align-items: center; justify-content: space-between; padding: 14px 16px; border-bottom: 1px solid #f0f0f0; }
        .cnh-modal-title { margin: 0; font-size: 15px; font-weight: 700; }
        .cnh-modal-x { border: 0; background: none; color: #8c8c8c; cursor: pointer; line-height: 0; }
        .cnh-modal-body { padding: 16px; display: flex; flex-direction: column; gap: 14px; }
        .cnh-modal-foot { display: flex; justify-content: flex-end; gap: 8px; padding: 12px 16px; border-top: 1px solid #f0f0f0; }
        .cnh-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .cnh-field-label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 4px; }
        .cnh-req { color: #ff4d4f; }
        .cnh-input {
            width: 100%; height: 34px; border: 1px solid #d9d9d9; border-radius: 4px;
            padding: 0 12px; font-size: 13px; outline: none;
        }
        .cnh-input:focus { border-color: #0d6efd; box-shadow: 0 0 0 3px rgba(13,110,253,.15); }
        .cnh-hint { margin: 4px 0 0; font-size: 12px; color: #8c8c8c; }
        .cnh-check { display: inline-flex; align-items: center; gap: 8px; font-size: 13px; cursor: pointer; }

        @media (max-width: 640px) {
            .cnh-row { grid-template-columns: 1fr; }
        }
    </style>

    <script>
        (function () {
            var overlay = document.getElementById('cnhOverlay');
            var form = document.getElementById('cnhForm');
            var method = document.getElementById('cnhMethod');
            var title = document.getElementById('cnhModalTitle');
            var submit = document.getElementById('cnhSubmit');
            var codeHint = document.getElementById('cnhCodeHint');
            var f = {
                code: document.getElementById('cnhCode'),
                name: document.getElementById('cnhName'),
                phone: document.getElementById('cnhPhone'),
                address: document.getElementById('cnhAddress'),
                active: document.getElementById('cnhActive'),
            };

            var STORE = @json(route('admin.chi-nhanh.store'));
            // Khuôn đường sửa: thay 0 bằng id thật. Dựng bằng route() để đường dẫn
            // vẫn đúng nếu prefix của nhóm route đổi.
            var UPDATE = @json(route('admin.chi-nhanh.update', 0));

            function mo() { overlay.style.display = 'flex'; f.name.focus(); }
            function dong() { overlay.style.display = 'none'; }

            document.querySelectorAll('[data-close]').forEach(function (el) {
                el.addEventListener('click', dong);
            });
            overlay.addEventListener('click', function (e) { if (e.target === overlay) dong(); });
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && overlay.style.display === 'flex') dong();
            });

            var them = document.querySelector('[data-cnh-add]');
            if (them) {
                them.addEventListener('click', function () {
                    form.action = STORE;
                    method.value = '';
                    title.textContent = 'Mở chi nhánh';
                    submit.textContent = 'Mở chi nhánh';
                    codeHint.style.display = '';
                    f.code.value = '';
                    f.code.placeholder = 'Bỏ trống để hệ thống tự đặt';
                    f.name.value = '';
                    f.phone.value = '';
                    f.address.value = '';
                    f.active.checked = true;
                    mo();
                });
            }

            document.querySelectorAll('[data-cnh-edit]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    form.action = UPDATE.replace(/0$/, btn.dataset.id);
                    method.value = 'PUT';
                    title.textContent = 'Sửa chi nhánh';
                    submit.textContent = 'Cập nhật';
                    // Bỏ trống ô mã khi SỬA nghĩa là giữ nguyên mã cũ (API xử lý như
                    // vậy), nên điền sẵn mã hiện tại và nói rõ điều đó.
                    codeHint.style.display = 'none';
                    f.code.value = btn.dataset.code || '';
                    f.name.value = btn.dataset.name || '';
                    f.phone.value = btn.dataset.phone || '';
                    f.address.value = btn.dataset.address || '';
                    f.active.checked = btn.dataset.active === '1';
                    mo();
                });
            });
        })();
    </script>
@endsection
