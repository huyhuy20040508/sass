@extends('layouts.app')

@section('title', 'Quản lý thuế')

@section('content')
    {{--
        Trang "Quản lý thuế" — dựng theo màn Quản lý thuế của bản cũ v2:
        [ header ] + [ bảng bốn dòng cố định ] + [ modal sửa bộ mức ].

        Không có thanh lọc, không có nút Thêm, không có nút Xoá — bốn loại thuế
        ứng với bốn điểm nghiệp vụ, cửa hàng chỉ chọn mức nào cho hiện ra.

        Khác bản v2 ở ba chỗ, đều là chỗ bản v2 hỏng:
        - Bộ mức cho chọn lấy từ API (chon_duoc), không chép cứng trong JavaScript.
        - Công tắc Trạng thái bấm ĂN THẬT (v2 vẽ ra nhưng không nối vào đâu).
        - Mức -1/-2 giữ nguyên là KCT/KKKNT suốt từ bảng tới lúc lưu.
    --}}
    @php
        $rows = collect($taxes ?? []);
    @endphp

    <div class="tax">
        {{-- Header --}}
        <div class="tax-head">
            <h1 class="tax-title">Quản lý thuế</h1>
            <span class="tax-head-note">
                {{ $rows->count() }} loại thuế · {{ $rows->where('is_active', true)->count() }} loại đang bật
            </span>
        </div>

        {{-- Bảng --}}
        <div class="tax-table-wrap">
            <table class="tax-table">
                <thead>
                    <tr>
                        <th class="tax-c-stt">STT</th>
                        <th class="tax-c-code">Mã</th>
                        <th class="tax-c-name">Loại thuế</th>
                        <th class="tax-c-value">% Thuế suất</th>
                        <th class="tax-c-status">Trạng thái</th>
                        <th class="tax-c-act">Hành động</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($rows as $i => $t)
                        @php
                            $id = (int) ($t['id'] ?? 0);
                            $isOn = (bool) ($t['is_active'] ?? false);
                            $nhan = $t['muc_nhan'] ?? [];
                        @endphp
                        <tr data-id="{{ $id }}">
                            <td class="tax-c-stt">{{ $i + 1 }}</td>
                            <td class="tax-c-code"><span class="tax-code">{{ $t['loai'] ?? '—' }}</span></td>
                            <td class="tax-c-name">
                                <span class="tax-name">{{ $t['ten'] ?? '—' }}</span>
                                <span class="tax-sub">{{ $t['mo_ta'] ?? '' }}</span>
                            </td>
                            <td class="tax-c-value">
                                @if(count($nhan))
                                    <span class="tax-rates">
                                        @foreach($nhan as $n)
                                            <span class="tax-rate {{ in_array($n, ['KCT', 'KKKNT'], true) ? 'is-word' : '' }}">{{ $n }}</span>
                                        @endforeach
                                    </span>
                                @else
                                    <span class="tax-muted">—</span>
                                @endif
                            </td>
                            <td class="tax-c-status">
                                <button type="button" class="tax-switch {{ $isOn ? 'on' : '' }}"
                                        data-toggle="{{ $id }}" data-on="{{ $isOn ? 1 : 0 }}"
                                        title="{{ $isOn ? 'Đang bật — bấm để tắt loại thuế này' : 'Đang tắt — bấm để bật lại' }}">
                                    <span class="tax-switch-knob"></span>
                                </button>
                            </td>
                            <td class="tax-c-act">
                                {{-- Trang này chỉ sửa được bộ mức: không có nút thêm, không có nút xoá. --}}
                                <div class="tax-rowacts">
                                    <button type="button" class="tax-rowbtn tax-edit" data-edit="{{ $id }}" title="Sửa bộ mức thuế suất">
                                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="tax-empty">
                                Không đọc được danh sách thuế từ máy chủ. Tải lại trang, hoặc kiểm tra kết nối API.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- <p class="tax-foot-note">
            Tắt một loại thuế thì màn nghiệp vụ tương ứng thôi bày ô chọn thuế — đơn mua hàng rơi về 0%,
            màn thu ngân ẩn hẳn dòng thuế. Bộ mức vẫn giữ nguyên, bật lại là dùng tiếp.
        </p> --}}
    </div>

    {{-- Modal sửa bộ mức --}}
    <div class="tax-overlay" id="taxFormOverlay" style="display:none;">
        <div class="tax-dialog">
            <div class="tax-modal-head">
                <h4 class="tax-modal-title">Sửa mức thuế suất</h4>
                <button type="button" class="tax-modal-x" data-close>
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </div>

            <form id="taxForm" method="POST" action="">
                @csrf
                <input type="hidden" name="_method" value="PUT">

                <div class="tax-modal-body">
                    <div>
                        {{-- Tên khoá lại y như bản v2: bốn loại là bốn điểm nghiệp vụ, đổi tên là mất chỗ neo. --}}
                        <label class="tax-field-label" for="taxName">Loại thuế</label>
                        <input type="text" id="taxName" class="tax-input" disabled>
                        <p class="tax-hint" id="taxDesc"></p>
                    </div>

                    <div>
                        <label class="tax-field-label">% Thuế suất <span class="tax-req">*</span></label>
                        <div class="tax-picker" id="taxPicker"></div>
                        <p class="tax-hint">
                            Bấm để chọn hoặc bỏ chọn. Những mức được chọn là những mức hiện ra trong ô thuế
                            ở màn nghiệp vụ — KCT là không chịu thuế, KKKNT là không kê khai nộp thuế.
                        </p>
                        <p class="tax-error" id="taxError" style="display:none;">Giữ lại ít nhất một mức thuế.</p>
                    </div>
                </div>

                <div class="tax-modal-foot">
                    <button type="button" class="tax-btn-ghost" data-close>Đóng</button>
                    <button type="submit" class="tax-btn-primary" id="taxSubmit">Lưu</button>
                </div>
            </form>
        </div>
    </div>

    {{-- Form ngầm cho công tắc trạng thái --}}
    <form id="taxStatusForm" method="POST" action="" style="display:none;">
        @csrf
        <input type="hidden" name="_method" value="PUT">
        <input type="hidden" name="is_active" id="taxStatusValue" value="1">
    </form>

    <style>
        .tax {
            /* Phá padding p-4 của <main> để tràn viền như các trang danh sách khác */
            margin: -1.5rem;
            min-height: calc(100vh - 56px);
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            color: #262626; background: #fff;
        }

        /* Header */
        .tax-head {
            display: flex; align-items: center; justify-content: space-between; gap: 16px;
            padding: 14px 20px;
        }
        .tax-title { margin: 0; font-size: 16px; font-weight: 700; color: #262626; line-height: 34px; }
        .tax-head-note { font-size: 12px; color: #8c8c8c; }

        /* Bảng */
        .tax-table-wrap { width: 100%; overflow: auto; border-left: 20px solid #fff; border-right: 20px solid #fff; }
        .tax-table { width: 100%; min-width: 900px; border-collapse: collapse; font-size: 14px; table-layout: fixed; }
        .tax-table thead tr { background: #f0f0f0; color: #262626; }
        /* Canh giữa mọi ô + khoảng cách lấy theo bản v2; MÀU giữ của bản hiện tại */
        .tax-table th, .tax-table td { padding: 15px 14px; vertical-align: middle; text-align: center; white-space: nowrap; }
        /* Ô nằm một dòng; cột chữ dài cắt bằng dấu ba chấm, không thì
           chữ tràn sang ô bên cạnh. Riêng dòng "chưa có dữ liệu" là một
           câu dài nên cho xuống hàng. */
        .tax-table td.tax-c-name { overflow: hidden; text-overflow: ellipsis; }
        .tax-empty { white-space: normal; }

        .tax-table th { font-weight: 700; white-space: nowrap; }
        /* Bề rộng theo TỈ LỆ, tổng đúng 100% -> phần dư chia đều cho mọi cột. */
        .tax-table th.tax-c-stt,    .tax-table td.tax-c-stt    { width: 8%; }
        .tax-table th.tax-c-code,   .tax-table td.tax-c-code   { width: 19%; }
        .tax-table th.tax-c-name,   .tax-table td.tax-c-name   { width: 28%; }
        .tax-table th.tax-c-value,  .tax-table td.tax-c-value  { width: 25%; }
        .tax-table th.tax-c-status, .tax-table td.tax-c-status { width: 10%; }
        .tax-table th.tax-c-act,    .tax-table td.tax-c-act    { width: 10%; }
        .tax-table tbody tr { border-bottom: 1px solid #f0f0f0; }
        .tax-table tbody tr:hover { background: #fafafa; }

        .tax-code { font-variant-numeric: tabular-nums; letter-spacing: .3px; color: #595959; }
        .tax-name { display: block; font-weight: 500; }
        .tax-sub { display: block; margin-top: 2px; font-size: 12px; color: #8c8c8c; }
        .tax-muted { color: #bfbfbf; }
        .tax-empty { padding: 48px 12px; text-align: center; color: #8c8c8c; }

        /* Mức thuế: mỗi mức một thẻ nhỏ, KCT/KKKNT khác màu vì không phải phần trăm */
        .tax-rates { display: flex; flex-wrap: wrap; justify-content: center; gap: 4px; }
        .tax-rate {
            display: inline-flex; align-items: center; height: 22px; border-radius: 4px;
            background: #f5f5f5; padding: 0 8px; font-size: 12px; color: #595959; white-space: nowrap;
        }
        .tax-rate.is-word { background: #fff7e6; color: #d46b08; }

        /* Switch trạng thái */
        .tax-switch {
            position: relative; display: inline-flex; align-items: center; height: 22px; width: 42px; border: 0;
            border-radius: 9999px; background: #ddd; cursor: pointer; padding: 0; transition: background .15s; vertical-align: middle;
        }
        .tax-switch.on { background: #7083b6; }
        .tax-switch-knob {
            display: inline-block; height: 16px; width: 16px; border-radius: 50%; background: #fff;
            box-shadow: 0 0 3px rgba(0,0,0,.3); transform: translateX(3px); transition: transform .15s;
        }
        .tax-switch.on .tax-switch-knob { transform: translateX(23px); }

        /* Nút thao tác: ô vuông bo góc có viền, lúc thường xám, rê chuột mới ăn màu. */
        .tax-rowacts { display: flex; align-items: center; justify-content: center; gap: 6px; }
        .tax-rowbtn {
            width: 32px; height: 32px; padding: 0; border: 1px solid #d9d9d9; border-radius: 6px;
            background: #fff; color: #595959; cursor: pointer;
            display: inline-flex; align-items: center; justify-content: center;
            transition: border-color .15s, background .15s, color .15s;
        }
        .tax-rowbtn:hover { border-color: #91d5ff; background: #e6f7ff; color: #1890ff; }

        .tax-foot-note { margin: 16px 20px 24px; font-size: 12px; color: #8c8c8c; max-width: 760px; }

        /* Modal */
        .tax-overlay { position: fixed; inset: 0; z-index: 1080; display: flex; align-items: center; justify-content: center; padding: 16px; background: rgba(0,0,0,.4); }
        .tax-dialog { max-height: 90vh; width: 100%; max-width: 560px; overflow-y: auto; border-radius: 6px; background: #fff; box-shadow: 0 10px 40px rgba(0,0,0,.2); }
        .tax-modal-head {
            position: sticky; top: 0; display: flex; align-items: center; justify-content: space-between;
            border-bottom: 1px solid #f0f0f0; background: #fff; padding: 12px 20px;
        }
        .tax-modal-title { margin: 0; font-size: 15px; font-weight: 700; color: #262626; }
        .tax-modal-x { border: 0; background: none; padding: 0; color: #8c8c8c; cursor: pointer; display: inline-flex; transition: color .15s; }
        .tax-modal-x:hover { color: #262626; }
        .tax-modal-body { padding: 16px 20px; display: flex; flex-direction: column; gap: 16px; }
        .tax-field-label { display: block; margin-bottom: 4px; font-size: 13px; font-weight: 500; color: #262626; }
        .tax-req { color: #ff4d4f; }
        .tax-input {
            height: 36px; width: 100%; border: 1px solid #d9d9d9; border-radius: 4px; padding: 0 12px;
            font-size: 13px; outline: none; transition: border-color .15s;
        }
        .tax-input:disabled { background: #f5f5f5; color: #8c8c8c; }
        .tax-hint { margin: 6px 0 0; font-size: 12px; color: #8c8c8c; }
        .tax-error { margin: 6px 0 0; font-size: 12px; color: #ff4d4f; }

        /* Bộ chọn mức: mỗi mức một ô bấm, không dùng thư viện ngoài */
        .tax-picker { display: flex; flex-wrap: wrap; gap: 8px; }
        .tax-chip {
            min-width: 56px; height: 34px; border: 1px solid #d9d9d9; border-radius: 4px; background: #fff;
            padding: 0 12px; font-size: 13px; color: #595959; cursor: pointer;
            display: inline-flex; align-items: center; justify-content: center;
            transition: border-color .15s, background .15s, color .15s;
        }
        .tax-chip:hover { border-color: #91d5ff; }
        .tax-chip.on { border-color: #1890ff; background: #e6f7ff; color: #1890ff; font-weight: 500; }

        .tax-modal-foot {
            position: sticky; bottom: 0; display: flex; justify-content: center; gap: 8px;
            border-top: 1px solid #f0f0f0; background: #fff; padding: 12px 20px;
        }
        .tax-btn-primary {
            height: 34px; display: inline-flex; align-items: center; gap: 6px; border: 0; border-radius: 4px;
            background: #1890ff; color: #fff; padding: 0 16px; font-size: 13px; font-weight: 500; cursor: pointer;
            transition: background .15s;
        }
        .tax-btn-primary:hover { background: #40a9ff; }
        .tax-btn-primary:disabled { opacity: .5; cursor: default; }
        .tax-btn-ghost {
            height: 34px; border: 1px solid #d9d9d9; border-radius: 4px; padding: 0 16px; font-size: 13px; font-weight: 500;
            color: #595959; background: #fff; cursor: pointer; transition: border-color .15s;
        }
        .tax-btn-ghost:hover { border-color: #bfbfbf; }
    </style>

    <script>
        (function () {
            const TAXES = @json($rows->values());
            const UPDATE_URL = @json(route('admin.thue.update', ['id' => '__ID__']));
            const STATUS_URL = @json(route('admin.thue.toggleStatus', ['id' => '__ID__']));

            const overlay = document.getElementById('taxFormOverlay');
            const form = document.getElementById('taxForm');
            const picker = document.getElementById('taxPicker');
            const nameInput = document.getElementById('taxName');
            const descEl = document.getElementById('taxDesc');
            const errEl = document.getElementById('taxError');
            const submitBtn = document.getElementById('taxSubmit');

            const statusForm = document.getElementById('taxStatusForm');
            const statusValue = document.getElementById('taxStatusValue');

            const urlFor = (tpl, id) => tpl.replace('__ID__', String(id));
            const find = (id) => TAXES.find((t) => Number(t.id) === Number(id));

            // ---- Modal sửa bộ mức ----

            function moModal(id) {
                const t = find(id);
                if (!t) return;

                nameInput.value = t.ten || '';
                descEl.textContent = t.mo_ta || '';
                form.action = urlFor(UPDATE_URL, id);
                errEl.style.display = 'none';

                const dangChon = new Set((t.muc || []).map(Number));
                picker.innerHTML = '';
                (t.chon_duoc || []).forEach((m) => {
                    const btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'tax-chip' + (dangChon.has(Number(m.gia_tri)) ? ' on' : '');
                    btn.dataset.value = m.gia_tri;
                    btn.textContent = m.nhan;
                    picker.appendChild(btn);
                });

                overlay.style.display = 'flex';
            }

            function dongModal() {
                overlay.style.display = 'none';
            }

            picker.addEventListener('click', (e) => {
                const chip = e.target.closest('.tax-chip');
                if (!chip) return;
                chip.classList.toggle('on');
                errEl.style.display = 'none';
            });

            // Gửi đi dưới dạng muc[]: dựng lúc submit thay vì giữ sẵn input ẩn,
            // để danh sách gửi lên luôn khớp đúng những ô đang sáng.
            form.addEventListener('submit', (e) => {
                form.querySelectorAll('input[name="muc[]"]').forEach((el) => el.remove());

                const chon = [...picker.querySelectorAll('.tax-chip.on')].map((c) => c.dataset.value);
                if (chon.length === 0) {
                    e.preventDefault();
                    errEl.style.display = 'block';
                    return;
                }

                chon.forEach((v) => {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = 'muc[]';
                    input.value = v;
                    form.appendChild(input);
                });

                submitBtn.disabled = true;
            });

            // ---- Công tắc trạng thái ----

            document.addEventListener('click', (e) => {
                const sw = e.target.closest('[data-toggle]');
                if (sw) {
                    statusForm.action = urlFor(STATUS_URL, sw.dataset.toggle);
                    statusValue.value = sw.dataset.on === '1' ? '0' : '1';
                    statusForm.submit();
                    return;
                }

                const edit = e.target.closest('[data-edit]');
                if (edit) {
                    moModal(edit.dataset.edit);
                    return;
                }

                if (e.target.closest('[data-close]') || e.target === overlay) {
                    dongModal();
                }
            });

            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape' && overlay.style.display !== 'none') dongModal();
            });
        })();
    </script>
@endsection
