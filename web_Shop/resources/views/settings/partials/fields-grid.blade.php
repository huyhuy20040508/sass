{{--
    Lưới ô nhập của MỘT khối cấu hình. Nhận $section (khối đã bồi thêm dữ liệu
    giao diện ở SettingController::decorate).

    Tách khỏi form.blade.php vì khối gập được và khối thường dựng ô nhập y hệt
    nhau, chỉ khác cái vỏ bọc ngoài — để hai bản copy thì sửa một kiểu ô là phải
    nhớ sửa cả hai chỗ.

    $valueOf và $MONEY_KEYS lấy từ view cha (Blade cho partial dùng chung phạm vi).
--}}
<div class="set-grid">
    @foreach($section['fields'] as $f)
        @php
            $key = $f['key'];
            $name = 'items['.$key.']';
            $id = 'set_'.$key;
            $val = $valueOf($key);
            $invalid = $errors->has('items.'.$key);
            $maxLen = (int) ($f['max_len'] ?? 0);
            $options = $f['options'] ?? [];

            // Ô chữ dài (địa chỉ, lời dặn) trải trọn một hàng: nhét
            // vào ô hẹp bằng ô hotline thì phải kéo ngang mới đọc hết.
            $wide = $f['type'] === 'text' && $maxLen >= 200;
        @endphp

        @if($f['type'] === 'bool')
            {{-- Công tắc là DÒNG trải hết chiều ngang, không phải một ô
                 trong lưới 2 cột: một cái công tắc rộng 40px đứng giữa
                 nửa trang trống thì cả khối chỉ toàn khoảng trắng. Nhãn
                 và lời giải thích nằm trái, công tắc nằm phải — đọc
                 thành từng dòng "việc này — đang bật/tắt". --}}
            <div class="set-field is-toggle">
                <div class="set-toggle-text">
                    @include('settings.partials.field-label', ['f' => $f, 'id' => $id])
                    @if($invalid)
                        <p class="set-error">{{ $errors->first('items.'.$key) }}</p>
                    @elseif($f['hint'] !== '')
                        <p class="set-hint">{{ $f['hint'] }}</p>
                    @endif
                </div>

                {{-- Ô ẩn value="0" đứng TRƯỚC ô tick: checkbox không tick
                     thì trình duyệt không gửi gì cả, thiếu ô ẩn này thì
                     "tắt" sẽ thành "không đụng tới" và giá trị cũ ở lại. --}}
                <label class="set-switch">
                    <input type="hidden" name="{{ $name }}" value="0">
                    <input type="checkbox" name="{{ $name }}" id="{{ $id }}" value="1"
                           data-bool {{ $val === '1' ? 'checked' : '' }}>
                    <span class="set-switch-track"><span class="set-switch-knob"></span></span>
                    <span class="set-switch-text">{{ $val === '1' ? 'Đang bật' : 'Đang tắt' }}</span>
                </label>
            </div>
            @continue
        @endif

        <div class="set-field {{ $wide ? 'is-wide' : '' }}">
            @include('settings.partials.field-label', ['f' => $f, 'id' => $id])

            <div class="set-control">
                @if($f['type'] === 'image')
                    {{-- Ảnh: URL nằm trong input ẩn, người dùng thao tác qua nút. --}}
                    <input type="hidden" name="{{ $name }}" id="{{ $id }}" value="{{ $val }}" data-logo-input>
                    <div class="set-logo" data-logo-box data-label="{{ $f['label'] }}">
                        <div class="set-logo-preview {{ $val === '' ? 'is-empty' : '' }}" data-logo-preview>
                            @if($val !== '')
                                <img src="{{ $val }}" alt="{{ $f['label'] }}">
                            @else
                                <svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/></svg>
                            @endif
                        </div>
                        <div class="set-logo-actions">
                            <button type="button" class="set-btn-ghost" data-logo-pick>
                                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 9 5-5 5 5"/><path d="M12 4v12"/></svg>
                                Chọn ảnh
                            </button>
                            <button type="button" class="set-btn-ghost set-btn-danger" data-logo-clear {{ $val === '' ? 'hidden' : '' }}>
                                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6"/></svg>
                                Gỡ ảnh
                            </button>
                        </div>
                    </div>
                @elseif($f['type'] === 'select')
                    {{-- Ô chọn ĐÓNG: khoá loại này chỉ nhận đúng mấy giá trị
                         registry khai, API từ chối thứ khác. Khác hẳn ô chọn có
                         tìm kiếm bên dưới — cái đó cho gõ tên ngoài danh sách. --}}
                    <select name="{{ $name }}" id="{{ $id }}"
                            class="set-input {{ $invalid ? 'is-invalid' : '' }}">
                        @foreach($options as $opt)
                            <option value="{{ $opt['value'] }}" @selected($val === $opt['value'])>
                                {{ $opt['label'] }}
                            </option>
                        @endforeach
                    </select>
                @elseif($options !== [])
                    {{-- Ô chọn có tìm kiếm. Vẫn là <input type="text"> chứ
                         không phải <select>: danh sách ngân hàng dài mấy chục
                         dòng, cuộn tay tìm lâu hơn gõ; và cái tên nằm ngoài
                         danh sách (quỹ tín dụng, ngân hàng nước ngoài) vẫn
                         phải gõ vào được, <select> thì chặn hẳn. --}}
                    <div class="set-combo" data-combo>
                        <input type="text" name="{{ $name }}" id="{{ $id }}"
                               class="set-input set-combo-input {{ $invalid ? 'is-invalid' : '' }}"
                               value="{{ $val }}" autocomplete="off" role="combobox"
                               aria-expanded="false" aria-autocomplete="list"
                               placeholder="Gõ để tìm, hoặc chọn trong danh sách"
                               @if($maxLen > 0) maxlength="{{ $maxLen }}" @endif
                               data-combo-input>
                        <button type="button" class="set-combo-caret" data-combo-toggle
                                aria-label="Mở danh sách" tabindex="-1">
                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                        </button>

                        {{-- KHÔNG dùng thuộc tính hidden: display:none không
                             chạy hiệu ứng được. Trạng thái đóng/mở nằm ở class
                             .is-open của khung ngoài, CSS lo phần trượt ra. --}}
                        <div class="set-combo-panel" data-combo-panel>
                            <ul class="set-combo-list" role="listbox" data-combo-list>
                                @foreach($options as $opt)
                                    <li class="set-combo-item" role="option" tabindex="-1"
                                        data-value="{{ $opt['value'] }}"
                                        data-search="{{ $opt['value'] }} {{ $opt['label'] }} {{ $opt['code'] }}">
                                        <b>{{ $opt['value'] }}</b>
                                        <span>{{ $opt['label'] }}</span>
                                    </li>
                                @endforeach
                            </ul>
                            {{-- Gõ tên lạ thì danh sách rỗng — nói rõ là vẫn lưu
                                 được, đừng để người dùng tưởng mình gõ sai. --}}
                            <p class="set-combo-empty" data-combo-empty hidden>
                                Không có dòng nào khớp. Cứ gõ tên bạn muốn, hệ thống vẫn lưu.
                            </p>
                        </div>
                    </div>
                @elseif($f['type'] === 'number')
                    <div class="set-input-wrap">
                        <input type="text" inputmode="numeric" name="{{ $name }}" id="{{ $id }}"
                               class="set-input set-input-num {{ $invalid ? 'is-invalid' : '' }}"
                               value="{{ $val }}" autocomplete="off"
                               @if(in_array($key, $MONEY_KEYS, true)) data-money @endif
                               @if(!empty($f['max_num'])) data-max="{{ (int) $f['max_num'] }}" @endif>
                        @if($f['unit'] !== '')
                            <span class="set-unit">{{ $f['unit'] }}</span>
                        @endif
                    </div>
                @else
                    @php
                        $type = match($f['type']) {
                            'email' => 'email',
                            'phone' => 'tel',
                            'url' => 'url',
                            default => 'text',
                        };
                        // Ô đường dẫn có placeholder mẫu: server bắt buộc có
                        // http:// hoặc https://, nói trước còn hơn để người dùng
                        // gõ "facebook.com/shop" rồi mới nhận lỗi lúc bấm Lưu.
                        $ph = $f['type'] === 'url' ? 'https://…' : '';
                    @endphp
                    <input type="{{ $type }}" name="{{ $name }}" id="{{ $id }}"
                           class="set-input {{ $invalid ? 'is-invalid' : '' }}"
                           value="{{ $val }}" autocomplete="off"
                           @if($ph !== '') placeholder="{{ $ph }}" @endif
                           @if($maxLen > 0) maxlength="{{ $maxLen }}" @endif>
                @endif

                @if($invalid)
                    <p class="set-error">{{ $errors->first('items.'.$key) }}</p>
                @elseif($f['hint'] !== '')
                    <p class="set-hint">{{ $f['hint'] }}</p>
                @endif
            </div>
        </div>
    @endforeach
</div>
