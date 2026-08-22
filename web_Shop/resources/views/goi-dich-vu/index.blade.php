@extends('layouts.app')

@section('title', \App\Http\Controllers\GoiDichVuController::TITLE)

@section('content')
    {{--
        Các gói dịch vụ — hợp đồng phần mềm của CHÍNH cửa hàng này.

        Đọc từ trên xuống đúng thứ tự người ta cần: "tôi đang dùng gì, còn bao
        lâu" trước, "hạn mức đã ký là gì" giữa, "gia hạn thì có những mức nào"
        sau.

        Dùng lại đúng hệ hình của nhóm Báo cáo (nền #f5f6fa, thẻ bo 10px viền
        #f0f0f0, tile có vệt màu bên trái, chữ 13px) để trang này không nhìn như
        đến từ một phần mềm khác. Class mang tiền tố riêng `gdv-` vì bộ .rp-* nằm
        trong partial của Báo cáo, không dùng chung được.

        KHÁCH TỰ TRẢ TIỀN ĐƯỢC ở đây: mỗi gói có giá công khai đều kèm nút gia
        hạn → đơn → cổng thanh toán → hợp đồng tự dài thêm. Trước đây trang này
        chỉ đọc và mời gửi email, nên mỗi lần gia hạn là một cuộc gọi điện.

        Gói "Liên hệ" (chưa công bố giá) KHÔNG có nút trả tiền: không có số nào để
        thu, và API cũng từ chối đặt đơn cho nó. Chỗ đó là một đường email — mời
        bấm một nút chắc chắn bị từ chối là mời người dùng đi vào ngõ cụt.

        Hạn mức của HỢP ĐỒNG đọc thẳng từ hợp đồng, không tra sang bảng giá bên
        dưới: bảng giá đổi được, hợp đồng đã ký thì không. Hai khối trên cùng
        trang vì thế có quyền nói hai con số khác nhau, và đó là đúng.
    --}}
    @php
        $support = \App\Http\Controllers\GoiDichVuController::SUPPORT_EMAIL;
        $shopName = app(\App\Services\ApiClient::class)->settingString('site_name', config('app.name'));
        $mailto = 'mailto:'.$support.'?subject='.rawurlencode('Gia hạn phần mềm — '.$shopName);

        $tien = fn ($v) => number_format((float) $v, 0, ',', '.').'₫';
        $chuKy = fn ($ma) => $ma === 'nam' ? 'năm' : 'tháng';

        // Giờ hết hạn in kèm ngày: hợp đồng dùng thử chết giữa buổi làm việc, và
        // "hết hạn ngày 15/08" đọc lúc 10:47 ngày 15/08 thì không nói được là đã
        // qua hay chưa.
        $gio = function ($iso) {
            try {
                return \Illuminate\Support\Carbon::parse($iso)->format('H:i');
            } catch (\Throwable $e) {
                return '';
            }
        };

        $ngay = function ($iso) {
            if (empty($iso)) {
                return '—';
            }
            try {
                return \Illuminate\Support\Carbon::parse($iso)->format('d/m/Y');
            } catch (\Throwable $e) {
                return '—';
            }
        };

        // Hạn mức ĐÃ KÝ: 0 nghĩa là không giới hạn (bản dịch của 'vo_han' bên bảng
        // giá) — in số 0 ra thì khách đọc thành "không được cái nào".
        $hanMuc = fn ($so) => ((int) $so) === 0 ? 'Không giới hạn' : number_format((int) $so, 0, ',', '.');

        // Dòng phụ dưới mỗi hạn mức. Vế đáng giá nhất ở đây là SỐ ĐANG DÙNG:
        // "tối đa 20 tài khoản" không nói được còn mấy chỗ, và người dùng chỉ
        // biết mình đụng trần vào đúng lúc bị từ chối tạo.
        //
        // $khoa null = hạn mức chưa có chỗ đếm. Khi đó in đúng câu cũ; đoán một
        // con số cho nó là bịa ra thông tin trên màn hình hợp đồng.
        $so = fn ($n) => number_format((int) $n, 0, ',', '.');
        $soDangDung = $daDung ?? null;
        $hanMucPhu = function ($tran, $donVi, $khoa = null) use ($soDangDung, $so) {
            $dung = ($khoa !== null && isset($soDangDung[$khoa])) ? (int) $soDangDung[$khoa] : null;

            // 0 = 'vo_han' bên bảng giá. Không có trần để so, nhưng số đang dùng
            // vẫn đáng in: nó là câu trả lời cho "tiệm mình đang có bao nhiêu".
            if (((int) $tran) === 0) {
                return $dung === null
                    ? 'Gói không giới hạn'
                    : 'Đang dùng '.$so($dung).' — gói không giới hạn';
            }

            return $dung === null
                ? $donVi
                : 'Đang dùng '.$so($dung).' / '.$so($tran).' '.$donVi;
        };

        // ĐÃ HẾT HẠN CHƯA — hỏi DỮ LIỆU, không hỏi con số ngày.
        //
        // `da_het_han` do máy chủ so tới từng giây. Suy từ `con_lai_ngay < 0` thì
        // suốt 24 giờ quanh mốc hết hạn con số đó bằng 0, và trang trả lời "chưa
        // hết hạn" đúng vào lúc phải báo động nhất — hợp đồng chết lúc 10:45 mà
        // 10:47 màn hình vẫn xanh.
        $quaHan = (bool) ($hopDong['da_het_han'] ?? false);

        // Cửa hàng bị KHOÁ: phiên chỉ mở được đúng trang đang đọc (xem middleware
        // KhoaKhiHetHan và, bên Go, choPhepKhiCuaHangKhoa).
        //
        // Hộp thoại bật theo CẢ HAI vế: cờ khoá (API đã khẳng định) HOẶC hợp đồng
        // đã quá mốc (biết ngay, không chờ lượt quét nền 5 phút của máy chủ).
        $khoa = \App\Services\HanSuDung::daKhoa() || $quaHan;

        $conLai = (int) ($hopDong['con_lai_ngay'] ?? 0);

        // Số giây tới lúc hết hạn — dùng để hẹn giờ bật hộp thoại ngay tại giây
        // hợp đồng chết, thay vì bắt người dùng F5 mới biết. Tính ở máy chủ nên
        // không lệ thuộc đồng hồ máy khách.
        $giayConLai = null;
        if ($hopDong && ! empty($hopDong['het_han'])) {
            try {
                $giayConLai = (int) round(now()->diffInSeconds(\Illuminate\Support\Carbon::parse($hopDong['het_han']), false));
            } catch (\Throwable $e) {
                $giayConLai = null;
            }
        }
        $dungThu = (bool) ($hopDong['dung_thu'] ?? false);

        // Tông của cả khối hợp đồng. `past_due` có tông riêng chứ không gộp với
        // dùng thử: một bên là "sắp phải quyết định", bên kia là "đang mất quyền
        // dùng phần mềm".
        $tone = 'blue';
        $trangThaiLabel = 'Đang sử dụng';
        if ($hopDong && ($quaHan || ($hopDong['trang_thai'] ?? '') === 'past_due')) {
            $tone = 'red';
            $trangThaiLabel = 'Đã quá hạn';
        } elseif ($dungThu) {
            $tone = 'amber';
            $trangThaiLabel = 'Đang dùng thử';
        } elseif ($conLai <= 15) {
            $tone = 'amber';
            $trangThaiLabel = 'Sắp hết hạn';
        }

        // Vòng đếm ngược: phần TRĂM THỜI GIAN CÒN LẠI của kỳ hiện tại. Tính theo
        // cả kỳ (bắt đầu → hết hạn) chứ không theo một mốc cố định 30 ngày, để
        // hợp đồng năm và hợp đồng tháng đọc cùng một cách.
        $phanTram = 0;
        if ($hopDong) {
            try {
                $batDau = \Illuminate\Support\Carbon::parse($hopDong['bat_dau']);
                $hetHan = \Illuminate\Support\Carbon::parse($hopDong['het_han']);
                $caKy = max(1, $batDau->diffInDays($hetHan));
                $phanTram = max(0, min(100, (int) round($conLai / $caKy * 100)));
            } catch (\Throwable $e) {
                $phanTram = 0;
            }
        }
        // Chu vi vòng tròn r=54 — dùng để cắt nét thành phần trăm bằng dasharray.
        $chuVi = 2 * M_PI * 54;
    @endphp

    <div class="gdv">
        {{-- ===== Đầu trang ===== --}}
        <div class="gdv-head">
            <div class="gdv-head-text">
                <h1 class="gdv-title">{{ \App\Http\Controllers\GoiDichVuController::TITLE }}</h1>
                <p class="gdv-sub">Gói phần mềm cửa hàng bạn đang dùng, hạn sử dụng và bảng giá để gia hạn.</p>
            </div>
            <div class="gdv-head-actions">
                <a class="gdv-btn-primary" href="{{ $mailto }}">
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="2.5" y="4.5" width="19" height="15" rx="2"/><path d="m3 6 9 6.5L21 6"/></svg>
                    Liên hệ gia hạn
                </a>
            </div>
        </div>

        @if(!empty($error))
            <p class="gdv-alert">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 8v5M12 16h.01"/></svg>
                <span>{{ $error }}</span>
            </p>
        @endif

        {{-- ===== Gói đang dùng ===== --}}
        @if($hopDong)
            <div class="gdv-hero gdv-hero--{{ $tone }}">
                <div class="gdv-hero-main">
                    <p class="gdv-eyebrow">{{ $hopDong['ten_app'] ?: 'Phần mềm' }}</p>
                    <div class="gdv-plan-row">
                        <h2 class="gdv-plan">{{ $hopDong['ten_goi'] }}</h2>
                        <span class="gdv-chip gdv-chip--{{ $tone }}">
                            <span class="gdv-dot"></span>{{ $trangThaiLabel }}
                        </span>
                    </div>

                    <p class="gdv-price">
                        {{ $tien($hopDong['gia']) }}<span class="gdv-price-unit">/{{ $chuKy($hopDong['chu_ky']) }}</span>
                    </p>

                    {{-- Thanh thời gian: nhìn một cái là biết kỳ này đã đi tới đâu.
                         Hai đầu ghi ngày thật để con số phần trăm có chỗ neo. --}}
                    <div class="gdv-track" role="img"
                         aria-label="Đã dùng {{ 100 - $phanTram }}% thời gian của kỳ hiện tại">
                        <span class="gdv-track-fill" style="width: {{ $tone === 'red' ? 100 : 100 - $phanTram }}%"></span>
                    </div>
                    <div class="gdv-track-meta">
                        <span>Bắt đầu {{ $ngay($hopDong['bat_dau']) }}</span>
                        <span>Hết hạn {{ $ngay($hopDong['het_han']) }}</span>
                    </div>

                    {{-- Câu quan trọng nhất của cả trang: phải làm gì tiếp. Ba tình
                         huống, ba việc khác nhau — quá hạn là gọi ngay, dùng thử là
                         quyết định có mua không, đang chạy là chưa cần làm gì. --}}
                    @if($tone === 'red')
                        {{-- Xét theo TÔNG chứ không riêng số ngày âm: API nói
                             `past_due` thì khối này phải nói lý do, kể cả khi hai
                             con số lệch nhau vì đồng hồ hai máy lệch giờ. --}}
                        <p class="gdv-callout is-danger">
                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/><path d="M12 9v4M12 17h.01"/></svg>
                            <span>Hợp đồng đã hết hạn từ {{ $ngay($hopDong['het_han']) }}. Cửa hàng có thể bị tạm khoá bất cứ lúc nào — vui lòng liên hệ gia hạn.</span>
                        </p>
                    @elseif($dungThu)
                        <p class="gdv-callout is-warn">
                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                            <span>Đây là bản dùng thử. Hết {{ $ngay($hopDong['het_han']) }}, cửa hàng sẽ tạm khoá cho tới khi chuyển sang gói chính thức.</span>
                        </p>
                    @elseif($conLai <= 15)
                        <p class="gdv-callout is-warn">
                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                            <span>Sắp tới hạn. Liên hệ trước vài ngày để việc gia hạn không rơi vào đúng hôm cửa hàng bận.</span>
                        </p>
                    @endif
                </div>

                {{-- Vòng đếm ngược — con số duy nhất người ta mở trang này để xem. --}}
                <div class="gdv-ring-wrap">
                    <svg class="gdv-ring" viewBox="0 0 128 128" aria-hidden="true">
                        <circle class="gdv-ring-bg" cx="64" cy="64" r="54"/>
                        <circle class="gdv-ring-fg" cx="64" cy="64" r="54"
                                stroke-dasharray="{{ round($chuVi, 1) }}"
                                {{-- Quá hạn thì vòng để TRỐNG: hết sạch thời gian.
                                     Vẽ đầy 100% đỏ thì hình lại đọc thành "đã xong",
                                     ngược hẳn nghĩa cần nói. --}}
                                stroke-dashoffset="{{ round($chuVi * (1 - ($tone === 'red' ? 0 : $phanTram / 100)), 1) }}"/>
                    </svg>
                    <div class="gdv-ring-text">
                        {{-- con_lai_ngay = 0 mang hai nghĩa, phải đọc kèm $quaHan mới
                             phân biệt: hết hạn trong hôm nay, hay vừa hết hạn hôm nay.
                             In "0 ngày quá hạn" cho vế sau là một câu vô nghĩa. --}}
                        @if($quaHan && $conLai === 0)
                            <span class="gdv-ring-num gdv-ring-num--chu">Hết hạn</span>
                            <span class="gdv-ring-label">hôm nay</span>
                        @else
                            <span class="gdv-ring-num">{{ abs($conLai) }}</span>
                            <span class="gdv-ring-label">{{ $quaHan ? 'ngày quá hạn' : 'ngày còn lại' }}</span>
                        @endif
                    </div>
                </div>
            </div>

            {{-- ===== Điều khoản ĐÃ KÝ =====
                 Cố ý đứng cạnh bảng giá bên dưới mà không tham chiếu tới nó: gói
                 của bạn là những con số này, kể cả khi bảng giá hôm nay ghi khác. --}}
            <div class="gdv-tiles">
                @foreach([
                    ['Chi nhánh', $hanMuc($hopDong['chi_nhanh']), $hanMucPhu($hopDong['chi_nhanh'], 'điểm bán', 'chi_nhanh'), 'blue',
                        '<path d="M3 21h18"/><path d="M5 21V8l7-5 7 5v13"/><path d="M9.5 21v-6h5v6"/>'],
                    ['Tài khoản', $hanMuc($hopDong['tai_khoan']), $hanMucPhu($hopDong['tai_khoan'], 'người dùng', 'tai_khoan'), 'violet',
                        '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20v-1a6.5 6.5 0 0 1 13 0v1"/><path d="M16.5 5.2a3.5 3.5 0 0 1 0 6.6"/><path d="M17 13.5a5.5 5.5 0 0 1 4.5 5.4v1.1"/>'],
                    ['Sản phẩm', $hanMuc($hopDong['san_pham']), $hanMucPhu($hopDong['san_pham'], 'mặt hàng', 'san_pham'), 'teal',
                        '<path d="M3.5 7.5 12 3l8.5 4.5v9L12 21l-8.5-4.5Z"/><path d="M3.5 7.5 12 12l8.5-4.5M12 12v9"/>'],
                    ['Tên miền riêng', ($hopDong['ten_mien_rieng'] ?? false) ? 'Có' : 'Không',
                        ($hopDong['ten_mien_rieng'] ?? false) ? 'Đã bao gồm trong gói' : 'Không có trong gói', 'green',
                        '<circle cx="12" cy="12" r="9"/><path d="M3 12h18"/><path d="M12 3a14 14 0 0 1 0 18a14 14 0 0 1 0-18Z"/>'],
                ] as [$label, $value, $note, $tileTone, $icon])
                    <div class="gdv-tile gdv-tile--{{ $tileTone }}">
                        <div class="gdv-tile-top">
                            <span class="gdv-tile-label">{{ $label }}</span>
                            <span class="gdv-tile-icon">
                                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round">{!! $icon !!}</svg>
                            </span>
                        </div>
                        <span class="gdv-tile-value">{{ $value }}</span>
                        <span class="gdv-tile-note">{{ $note }}</span>
                    </div>
                @endforeach
            </div>
        @elseif(empty($error))
            {{-- Không có hợp đồng KHÔNG phải lỗi: cửa hàng dựng tay trước khi nhà
                 cung cấp có sổ hợp đồng thì rơi vào đây. Nói đúng như vậy, đừng
                 hiện một khối trống để người đọc tự đoán. --}}
            <div class="gdv-card gdv-empty">
                <span class="gdv-empty-icon">
                    <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="2.5" y="5" width="19" height="14" rx="2"/><path d="M2.5 9.5h19"/><path d="M6 14h5"/></svg>
                </span>
                <div>
                    <p class="gdv-empty-title">Chưa có hợp đồng nào trong sổ nhà cung cấp</p>
                    <p class="gdv-empty-sub">
                        Cửa hàng vẫn hoạt động bình thường, chỉ là chưa gắn với gói dịch vụ nào trong sổ hợp đồng.
                        Liên hệ <a href="mailto:{{ $support }}">{{ $support }}</a> để được ghi nhận đúng gói đang dùng.
                    </p>
                </div>
            </div>
        @endif

        {{-- ===== Bảng giá ===== --}}
        @if(!empty($bangGia))
            <div class="gdv-section">
                <h2 class="gdv-section-title">Bảng giá</h2>
                <p class="gdv-section-sub">Mức giá hiện hành của nhà cung cấp. Gói bạn đang dùng giữ nguyên điều khoản đã ký.</p>
            </div>

            <div class="gdv-plans">
                @foreach($bangGia as $goi)
                    @php
                        $dangDung = $hopDong && ($hopDong['goi'] ?? '') === ($goi['code'] ?? '')
                            && ($hopDong['chu_ky'] ?? '') === ($goi['billing_cycle'] ?? '');
                        $ngungBan = ($goi['status'] ?? '') === 'retired';
                        $features = $goi['features'] ?? [];
                    @endphp
                    <div class="gdv-card gdv-plan-card {{ $dangDung ? 'is-current' : '' }} {{ $ngungBan ? 'is-retired' : '' }}">
                        @if($dangDung)
                            <span class="gdv-ribbon">Gói của bạn</span>
                        @elseif($ngungBan)
                            {{-- Gói ngừng bán chỉ lọt vào danh sách khi khách đang dùng
                                 chính nó; nói rõ để không ai ngồi chờ nó được bán lại. --}}
                            <span class="gdv-ribbon is-muted">Ngừng bán</span>
                        @endif

                        <p class="gdv-plan-name">{{ $goi['name'] }}</p>
                        <p class="gdv-plan-tagline">{{ $goi['tagline'] ?: 'Gói dịch vụ phần mềm' }}</p>

                        <p class="gdv-plan-price">
                            @if($goi['price'] === null)
                                {{-- null = "Liên hệ" (chưa công bố giá), KHÁC 0 là miễn phí --}}
                                <span class="gdv-plan-contact">Liên hệ</span>
                            @else
                                {{ $tien($goi['price']) }}<span class="gdv-price-unit">/{{ $chuKy($goi['billing_cycle'] ?? '') }}</span>
                            @endif
                        </p>

                        {{-- Điều khoản dựng từ `fields` do API trả về (registry bên Go),
                             nên thêm một hạn mức mới ở đó là trang này tự có thêm dòng,
                             không phải sửa Blade. --}}
                        <ul class="gdv-feats">
                            @foreach($fields as $f)
                                @php
                                    $key = $f['key'] ?? '';
                                    $co = array_key_exists($key, $features);
                                    $raw = $co ? (string) $features[$key] : (string) ($f['khong_co_dong'] ?? '');
                                    $laCoKhong = ($f['type'] ?? '') === 'co_khong';
                                    // Khai trước vòng if để dòng tính $tick bên dưới không
                                    // đọc phải giá trị còn sót của gói ở vòng lặp trước.
                                    $bat = false;

                                    // Ba trạng thái của một khoá, đừng trộn làm một:
                                    // có số · không giới hạn · bảng giá không quy định.
                                    if ($laCoKhong) {
                                        $bat = $raw === '1';
                                        $text = $f['label'] ?? $key;
                                        $mo = ! $bat;
                                    } elseif (! $co && $raw === '') {
                                        $text = ($f['label'] ?? $key).': thoả thuận khi ký';
                                        $mo = true;
                                    } elseif ($raw === 'vo_han') {
                                        $text = ($f['label'] ?? $key).': không giới hạn';
                                        $mo = false;
                                    } else {
                                        $text = ($f['label'] ?? $key).': '.number_format((int) $raw, 0, ',', '.')
                                            .(($f['don_vi'] ?? '') !== '' ? ' '.$f['don_vi'] : '');
                                        $mo = false;
                                    }
                                    $tick = ($laCoKhong && ! $bat) ? 'off' : 'on';
                                @endphp
                                <li class="{{ $mo ? 'is-soft' : '' }}">
                                    <span class="gdv-tick is-{{ $tick }}">
                                        <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                                            <path d="{{ $tick === 'on' ? 'm5 13 4 4 10-10' : 'M6 12h12' }}"/>
                                        </svg>
                                    </span>
                                    <span>{{ $text }}</span>
                                </li>
                            @endforeach
                        </ul>

                        <div class="gdv-plan-foot">
                            @if(($goi['trial_days'] ?? 0) > 0)
                                <span class="gdv-plan-trial">Dùng thử {{ (int) $goi['trial_days'] }} ngày</span>
                            @endif

                            {{-- NÚT TRẢ TIỀN. Chỉ hiện khi gói CÓ GIÁ và còn bán:
                                 gói "Liên hệ" chưa có số nào để thu, và API cũng từ
                                 chối đặt đơn cho nó — mời bấm một nút chắc chắn bị từ
                                 chối là mời người dùng đi vào ngõ cụt. --}}
                            {{-- Nút trả tiền CHỈ hiện khi có hợp đồng để đẩy hạn.

                                 Cửa hàng chưa có hợp đồng trong sổ nền tảng (dựng tay
                                 trước khi có control plane) mà vẫn chìa nút thì mỗi
                                 lượt bấm là một lỗi "không tìm thấy dữ liệu" — API cần
                                 một hợp đồng sẵn có để cộng hạn vào. Với họ, đường đúng
                                 là nhà cung cấp ký hợp đồng trước, nên chỗ này thành
                                 nút liên hệ. --}}
                            @if($hopDong && ! $ngungBan && $goi['price'] !== null && (float) $goi['price'] > 0)
                                <form method="POST" action="{{ route('admin.goi-dich-vu.gia-han') }}" class="gdv-buy">
                                    @csrf
                                    <input type="hidden" name="plan_id" value="{{ $goi['id'] }}">
{{-- Ô SỐ + ĐƠN VỊ, không phải một danh sách cố định.

                                         Danh sách cứng (1/3/6/12) chỉ đúng với gói bán
                                         theo tháng; gói bán theo NĂM thì "3 tháng" là
                                         một thứ không bán được, và máy chủ từ chối.
                                         Cho gõ số rồi chọn đơn vị thì cùng một ô phục
                                         vụ được cả hai kiểu gói.

                                         Gói năm mặc định đơn vị NĂM và khoá lại: đổi
                                         sang tháng ở đó chỉ dẫn tới một lượt bấm bị từ
                                         chối (xem GiaHanService.Dat). --}}
                                    @php $laGoiNam = ($goi['billing_cycle'] ?? '') === 'nam'; @endphp
                                    <input type="number" name="so_luong" class="gdv-so"
                                           value="1" min="1" max="{{ $laGoiNam ? 2 : 24 }}" step="1"
                                           inputmode="numeric" aria-label="Số kỳ gia hạn">
                                    <select name="don_vi" class="gdv-donvi" aria-label="Đơn vị"
                                            @if($laGoiNam) data-khoa @endif>
                                        <option value="thang" @unless($laGoiNam) selected @endunless
                                                @if($laGoiNam) disabled @endif>tháng</option>
                                        <option value="nam" @if($laGoiNam) selected @endif>năm</option>
                                    </select>
                                    <button type="submit" class="gdv-btn-buy">
                                        {{ $dangDung ? 'Gia hạn' : 'Chuyển gói' }}
                                    </button>
                                </form>
                            @elseif(! $dangDung && ! $ngungBan)
                                {{-- Hai đường cùng về email nhưng nói hai câu khác nhau:
                                     gói "Liên hệ" thì chưa có giá công khai, còn cửa hàng
                                     chưa có hợp đồng thì cần được ký trước — nút bấm vào
                                     báo lỗi không thay được câu nào trong hai câu đó. --}}
                                <a class="gdv-btn-ghost" href="{{ $mailto }}">
                                    {{ $goi['price'] === null ? 'Liên hệ báo giá' : 'Liên hệ đăng ký gói này' }}
                                </a>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        {{-- ===== Đầu mối liên hệ ===== --}}
        <div class="gdv-card gdv-contact">
            <div>
                <p class="gdv-contact-title">Cần gia hạn hoặc đổi gói?</p>
                <p class="gdv-contact-sub">
                    Bấm gia hạn ở gói bạn muốn là trả được ngay, hạn cộng thêm ngay khi tiền vào.
                    Cần hoá đơn đỏ, gói riêng hay thoả thuận dài hạn thì gửi email — chỗ đó cần người nói chuyện với nhau.
                </p>
            </div>
            <a class="gdv-btn-primary" href="{{ $mailto }}">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="2.5" y="4.5" width="19" height="15" rx="2"/><path d="m3 6 9 6.5L21 6"/></svg>
                {{ $support }}
            </a>
        </div>

        {{-- ===== Hộp thoại HẾT HẠN =====
             Chỉ hiện khi phiên đang bị khoá. KHÔNG đóng được bằng nền hay phím
             Esc: đóng nó đi cũng không mở ra được chức năng nào, nên một nút "X"
             ở đây chỉ tạo cảm giác còn đường vòng. Hai lối ra, đúng hai việc người
             ta làm được lúc này — xem mức giá để gia hạn, hoặc thoát ra. --}}
        @if($khoa)
            <div class="gdv-modal" id="gdvModal" role="dialog" aria-modal="true" aria-labelledby="gdvModalTitle">
                <div class="gdv-modal-box">
                    <span class="gdv-modal-icon">
                        <svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="10.5" width="16" height="10.5" rx="2"/><path d="M8 10.5V7a4 4 0 0 1 8 0v3.5"/><path d="M12 15v2"/></svg>
                    </span>
                    <h2 class="gdv-modal-title" id="gdvModalTitle">Phần mềm đã hết hạn</h2>
                    <p class="gdv-modal-sub">
                        @if($hopDong)
                            Hợp đồng của cửa hàng hết hạn {{ $gio($hopDong['het_han']) }} ngày {{ $ngay($hopDong['het_han']) }}.
                        @endif
                        Các chức năng bán hàng, kho và cấu hình đang tạm khoá. Gia hạn xong là mở lại ngay,
                        dữ liệu của cửa hàng vẫn còn nguyên.
                    </p>
                    <div class="gdv-modal-actions">
                        <button type="button" class="gdv-btn-primary" id="gdvModalXem">
                            Xem tuỳ chọn gia hạn
                        </button>
                        {{-- Đăng xuất là form POST vì route logout đòi CSRF; một thẻ <a>
                             ở đây sẽ nhận 405 và người dùng kẹt lại trong hộp thoại. --}}
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="gdv-btn-ghost">Thoát ra</button>
                        </form>
                    </div>
                </div>
            </div>
        @endif
    </div>

    <style>
        .gdv {
            /* Bù padding p-4 của <main> để nền trải hết bề ngang, giống nhóm Báo cáo. */
            margin: -1.5rem;
            min-height: calc(100vh - 56px);
            padding: 20px 24px 32px;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            color: #262626; background: #f5f6fa;
            display: flex; flex-direction: column; gap: 16px;
        }

        /* ----- Đầu trang ----- */
        .gdv-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; flex-wrap: wrap; }
        .gdv-head-text { min-width: 0; }
        .gdv-title { margin: 0; font-size: 19px; font-weight: 700; letter-spacing: -.02em; }
        .gdv-sub { margin: 3px 0 0; font-size: 13px; color: #8c8c8c; }
        .gdv-head-actions { display: flex; align-items: center; gap: 8px; flex-shrink: 0; }

        .gdv-btn-primary {
            height: 36px; display: inline-flex; align-items: center; gap: 7px; border: 0; border-radius: 8px;
            background: #1890ff; color: #fff; padding: 0 16px; font-size: 13px; font-weight: 600;
            text-decoration: none; white-space: nowrap;
            box-shadow: 0 1px 2px rgba(24, 144, 255, .35); transition: background .15s, box-shadow .15s;
        }
        .gdv-btn-primary:hover { background: #40a9ff; color: #fff; box-shadow: 0 2px 8px rgba(24, 144, 255, .3); }
        .gdv-btn-ghost {
            height: 32px; display: inline-flex; align-items: center; border: 1px solid #d9d9d9; border-radius: 8px;
            background: #fff; padding: 0 14px; font-size: 12.5px; font-weight: 600; color: #595959;
            text-decoration: none; white-space: nowrap; transition: border-color .15s, color .15s;
        }
        .gdv-btn-ghost:hover { border-color: #1890ff; color: #1890ff; }

        .gdv-alert {
            display: flex; align-items: flex-start; gap: 8px; margin: 0;
            padding: 11px 14px; border: 1px solid #ffe58f; border-radius: 10px;
            background: #fffbe6; color: #874d00; font-size: 13px; line-height: 1.65;
        }
        .gdv-alert svg { flex-shrink: 0; margin-top: 2px; }

        .gdv-card {
            background: #fff; border: 1px solid #f0f0f0; border-radius: 10px;
            box-shadow: 0 1px 2px rgba(0, 0, 0, .03);
        }

        /* ----- Khối hợp đồng đang chạy ----- */
        /* Tông màu chỉ để nói TÌNH TRẠNG từ xa (xanh yên tâm, hổ phách sắp tới hạn,
           đỏ phải làm gì đó ngay), không mã hoá dữ liệu nào khác. */
        .gdv-hero {
            --gdv: #1890ff; --gdv-soft: #f0f7ff; --gdv-edge: #ddeeff;
            position: relative; overflow: hidden;
            display: flex; align-items: center; justify-content: space-between; gap: 28px; flex-wrap: wrap;
            padding: 22px 26px; border: 1px solid #f0f0f0; border-radius: 12px;
            background: linear-gradient(115deg, var(--gdv-edge) 0%, #fff 62%);
            box-shadow: 0 1px 2px rgba(0, 0, 0, .03);
        }
        .gdv-hero::before { content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 4px; background: var(--gdv); }
        .gdv-hero--blue { --gdv: #1890ff; --gdv-soft: #f0f7ff; --gdv-edge: #ddeeff; }
        .gdv-hero--amber { --gdv: #fa8c16; --gdv-soft: #fff7e9; --gdv-edge: #ffedd2; }
        .gdv-hero--red { --gdv: #cf1322; --gdv-soft: #fff2f0; --gdv-edge: #ffe2de; }
        .gdv-hero-main { min-width: 280px; flex: 1; }

        .gdv-eyebrow { margin: 0; font-size: 11.5px; font-weight: 600; color: #8c8c8c; text-transform: uppercase; letter-spacing: .05em; }
        .gdv-plan-row { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-top: 4px; }
        .gdv-plan { margin: 0; font-size: 26px; font-weight: 700; letter-spacing: -.02em; line-height: 1.15; }
        .gdv-chip {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 3px 10px; border-radius: 999px; font-size: 11.5px; font-weight: 600;
            background: var(--gdv-soft); color: var(--gdv); border: 1px solid var(--gdv-edge);
        }
        .gdv-dot { width: 6px; height: 6px; border-radius: 50%; background: currentColor; }
        .gdv-price { margin: 8px 0 0; font-size: 15px; font-weight: 600; font-variant-numeric: tabular-nums; }
        .gdv-price-unit { margin-left: 2px; font-size: 12.5px; font-weight: 400; color: #8c8c8c; }

        /* Thanh thời gian của kỳ hiện tại */
        .gdv-track { margin-top: 16px; max-width: 520px; height: 6px; border-radius: 999px; background: rgba(0, 0, 0, .07); overflow: hidden; }
        .gdv-track-fill { display: block; height: 100%; border-radius: 999px; background: var(--gdv); }
        .gdv-track-meta {
            display: flex; justify-content: space-between; gap: 12px;
            max-width: 520px; margin-top: 6px; font-size: 12px; color: #8c8c8c;
        }

        .gdv-callout {
            display: flex; align-items: flex-start; gap: 8px;
            margin: 14px 0 0; max-width: 560px; font-size: 13px; line-height: 1.65;
        }
        .gdv-callout svg { flex-shrink: 0; margin-top: 2px; }
        .gdv-callout.is-warn { color: #874d00; }
        .gdv-callout.is-danger { color: #a8071a; font-weight: 500; }

        /* Vòng đếm ngược */
        .gdv-ring-wrap { position: relative; width: 148px; height: 148px; flex-shrink: 0; }
        .gdv-ring { width: 148px; height: 148px; transform: rotate(-90deg); }
        .gdv-ring-bg { fill: none; stroke: rgba(0, 0, 0, .06); stroke-width: 10; }
        /* Quá hạn: vòng trống, nên chính cái rãnh phải mang màu cảnh báo — không
           thì khối đỏ lại có một vòng xám vô nghĩa nằm giữa. */
        .gdv-hero--red .gdv-ring-bg { stroke: rgba(207, 19, 34, .16); }
        .gdv-hero--red .gdv-ring-num { color: #cf1322; }
        .gdv-ring-fg { fill: none; stroke: var(--gdv); stroke-width: 10; stroke-linecap: round; }
        .gdv-ring-text {
            position: absolute; inset: 0; display: flex; flex-direction: column;
            align-items: center; justify-content: center; gap: 2px;
        }
        .gdv-ring-num { font-size: 34px; font-weight: 700; line-height: 1; letter-spacing: -.03em; font-variant-numeric: tabular-nums; }
        /* Chữ thay cho số ("Hết hạn") cần nhỏ hơn để nằm vừa trong vòng tròn. */
        .gdv-ring-num--chu { font-size: 22px; }
        .gdv-ring-label { font-size: 11.5px; color: #8c8c8c; }

        /* ----- Tile hạn mức đã ký ----- */
        .gdv-tiles { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; }
        .gdv-tile {
            --t: #1890ff; --t-soft: #f0f7ff;
            position: relative; overflow: hidden;
            display: flex; flex-direction: column; gap: 6px; min-width: 0;
            padding: 14px 16px 13px 18px; border: 1px solid #f0f0f0; border-radius: 10px;
            background: #fff; box-shadow: 0 1px 2px rgba(0, 0, 0, .03);
        }
        .gdv-tile::before { content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 3px; background: var(--t); opacity: .85; }
        .gdv-tile--blue { --t: #1890ff; --t-soft: #f0f7ff; }
        .gdv-tile--violet { --t: #722ed1; --t-soft: #f7f1ff; }
        .gdv-tile--teal { --t: #13c2c2; --t-soft: #edfbfb; }
        .gdv-tile--green { --t: #52c41a; --t-soft: #f4fded; }
        .gdv-tile-top { display: flex; align-items: flex-start; justify-content: space-between; gap: 8px; }
        .gdv-tile-label { font-size: 11.5px; font-weight: 600; color: #8c8c8c; text-transform: uppercase; letter-spacing: .04em; }
        .gdv-tile-icon {
            flex-shrink: 0; width: 28px; height: 28px; display: inline-flex; align-items: center; justify-content: center;
            border-radius: 8px; background: var(--t-soft); color: var(--t);
        }
        .gdv-tile-value { font-size: 20px; font-weight: 700; line-height: 1.15; letter-spacing: -.02em; font-variant-numeric: tabular-nums; }
        .gdv-tile-note { font-size: 12px; color: #8c8c8c; }

        /* ----- Chưa có hợp đồng ----- */
        .gdv-empty { display: flex; align-items: flex-start; gap: 14px; padding: 20px 22px; }
        .gdv-empty-icon {
            flex-shrink: 0; width: 40px; height: 40px; display: inline-flex; align-items: center; justify-content: center;
            border-radius: 10px; background: #f5f6fa; color: #8c8c8c;
        }
        .gdv-empty-title { margin: 2px 0 0; font-size: 15px; font-weight: 600; }
        .gdv-empty-sub { margin: 5px 0 0; max-width: 680px; font-size: 13px; color: #8c8c8c; line-height: 1.7; }

        /* ----- Bảng giá ----- */
        .gdv-section { margin-top: 8px; }
        .gdv-section-title { margin: 0; font-size: 15px; font-weight: 700; }
        .gdv-section-sub { margin: 3px 0 0; font-size: 12.5px; color: #8c8c8c; }
        /* auto-fit chứ không auto-fill: ba gói phải trải hết bề ngang thay vì nép
           trái và chừa một cột trống — cột trống đó đọc như "còn một gói chưa tải
           xong". */
        .gdv-plans { display: grid; grid-template-columns: repeat(auto-fit, minmax(270px, 1fr)); gap: 16px; align-items: stretch; }
        .gdv-plan-card { position: relative; display: flex; flex-direction: column; padding: 20px; }
        .gdv-plan-card.is-current { border-color: #91caff; box-shadow: 0 0 0 1px #91caff inset, 0 4px 14px rgba(24, 144, 255, .1); }
        /* Gói ngừng bán chỉ hiện khi khách đang dùng chính nó — làm nhạt để không
           ai tưởng đây là thứ còn chọn được. */
        .gdv-plan-card.is-retired { background: #fafafa; }
        .gdv-ribbon {
            position: absolute; top: 14px; right: 14px;
            padding: 3px 9px; border-radius: 999px; font-size: 11px; font-weight: 600;
            background: #e6f4ff; color: #0958d9;
        }
        .gdv-ribbon.is-muted { background: #f5f5f5; color: #8c8c8c; }
        .gdv-plan-name { margin: 0; padding-right: 84px; font-size: 16px; font-weight: 700; }
        .gdv-plan-tagline { margin: 4px 0 0; font-size: 12.5px; color: #8c8c8c; line-height: 1.6; min-height: 20px; }
        .gdv-plan-price {
            margin: 14px 0 0; padding-bottom: 14px; border-bottom: 1px solid #f0f0f0;
            font-size: 24px; font-weight: 700; letter-spacing: -.02em; font-variant-numeric: tabular-nums;
        }
        .gdv-plan-contact { font-size: 20px; }
        .gdv-feats { list-style: none; margin: 14px 0 0; padding: 0; display: flex; flex-direction: column; gap: 9px; flex: 1; }
        .gdv-feats li { display: flex; align-items: flex-start; gap: 9px; font-size: 13px; line-height: 1.5; }
        .gdv-feats li.is-soft { color: #8c8c8c; }
        .gdv-tick {
            flex-shrink: 0; width: 18px; height: 18px; margin-top: 1px;
            display: inline-flex; align-items: center; justify-content: center; border-radius: 50%;
        }
        .gdv-tick.is-on { background: #f6ffed; color: #389e0d; }
        .gdv-tick.is-off { background: #f5f5f5; color: #bfbfbf; }
        .gdv-plan-foot {
            display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap;
            margin-top: 16px; min-height: 32px;
        }
        .gdv-plan-trial { margin: 0; font-size: 12px; font-weight: 600; color: #389e0d; }

        /* Nút TRẢ TIỀN và ô số tháng đứng cùng một hàng ở chân thẻ gói. Nút đặc
           màu vì đây là hành động chính của cả trang — mọi thứ khác chỉ để đọc. */
        .gdv-buy { display: flex; align-items: center; gap: 8px; }
        /* Ô số hẹp vừa đủ hai chữ số: đây là "mấy kỳ", không phải một con số dài. */
        .gdv-so {
            width: 56px; height: 32px; padding: 0 8px;
            border: 1px solid #d9d9d9; border-radius: 8px;
            font-size: 12.5px; color: #262626; text-align: center;
            font-variant-numeric: tabular-nums;
        }
        /* Dropdown NGẮN: chỉ hai lựa chọn, để nó rộng bằng ô số là chiếm chỗ vô ích. */
        .gdv-donvi {
            height: 32px; padding: 0 24px 0 8px; border: 1px solid #d9d9d9; border-radius: 8px;
            background: #fff; font-size: 12.5px; color: #262626; cursor: pointer;
            appearance: none; -webkit-appearance: none;
            background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%23595959' stroke-width='2.4' stroke-linecap='round' stroke-linejoin='round'><path d='m6 9 6 6 6-6'/></svg>");
            background-repeat: no-repeat; background-position: right 7px center;
        }

        .gdv-btn-buy {
            height: 32px; padding: 0 16px; border: 0; border-radius: 8px;
            background: #1890ff; color: #fff; font-size: 12.5px; font-weight: 600;
            font-family: inherit; cursor: pointer; white-space: nowrap;
            transition: background .15s;
        }
        .gdv-btn-buy:hover { background: #40a9ff; }


        /* ----- Đầu mối liên hệ ----- */
        .gdv-contact {
            display: flex; align-items: center; justify-content: space-between; gap: 20px; flex-wrap: wrap;
            padding: 18px 22px;
        }
        .gdv-contact-title { margin: 0; font-size: 14.5px; font-weight: 700; }
        .gdv-contact-sub { margin: 4px 0 0; max-width: 640px; font-size: 12.5px; color: #8c8c8c; line-height: 1.7; }


        /* ----- Hộp thoại hết hạn ----- */
        .gdv-modal {
            position: fixed; inset: 0; z-index: 1080;
            display: flex; align-items: center; justify-content: center; padding: 20px;
            background: rgba(15, 23, 42, .55);
            /* Không có hiệu ứng mờ dần: hộp thoại này không phải thứ bất chợt hiện
               ra giữa lúc làm việc, nó là tình trạng của cả trang. */
        }
        .gdv-modal.is-hidden { display: none; }
        .gdv-modal-box {
            width: 100%; max-width: 460px; padding: 26px 26px 22px;
            background: #fff; border-radius: 14px; text-align: center;
            box-shadow: 0 18px 50px rgba(0, 0, 0, .25);
        }
        .gdv-modal-icon {
            width: 52px; height: 52px; margin: 0 auto 14px;
            display: flex; align-items: center; justify-content: center;
            border-radius: 50%; background: #fff1f0; color: #cf1322;
        }
        .gdv-modal-title { margin: 0; font-size: 19px; font-weight: 700; letter-spacing: -.02em; }
        .gdv-modal-sub { margin: 8px 0 0; font-size: 13.5px; color: #595959; line-height: 1.7; }
        .gdv-modal-actions {
            display: flex; align-items: center; justify-content: center; gap: 10px; flex-wrap: wrap;
            margin-top: 20px;
        }
        .gdv-modal-actions .gdv-btn-primary,
        .gdv-modal-actions .gdv-btn-ghost { height: 38px; border: 0; cursor: pointer; font-family: inherit; }
        .gdv-modal-actions .gdv-btn-ghost { border: 1px solid #d9d9d9; padding: 0 16px; font-size: 13px; }

        @media (max-width: 1100px) { .gdv-tiles { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 720px) {
            .gdv { padding: 16px 14px 28px; }
            .gdv-hero { padding: 18px; gap: 18px; }
            .gdv-ring-wrap, .gdv-ring { width: 120px; height: 120px; }
            .gdv-ring-num { font-size: 28px; }
            .gdv-tiles { grid-template-columns: 1fr; }
        }
    </style>

    @if(! $khoa && $giayConLai !== null && $giayConLai > 0 && $giayConLai < 86400)
        {{-- CÒN HẠN NHƯNG SẮP CHẾT TRONG HÔM NAY: hẹn giờ đúng tới giây đó rồi tự
             tải lại trang. Không có nó thì người đang mở sẵn trang này ngồi nhìn
             một màn hình xanh sau khi hợp đồng đã hết — phải F5 mới biết.

             Chỉ đặt hẹn khi còn dưới 24 giờ: setTimeout không đáng tin với khoảng
             thời gian dài, và một cái hẹn 30 ngày thì tab nào cũng đóng trước đó. --}}
        <script>
            setTimeout(function () { window.location.reload(); }, {{ $giayConLai * 1000 }} + 1500);
        </script>
    @endif

    @if($khoa)
        <script>
            (function () {
                var modal = document.getElementById('gdvModal');
                var xem = document.getElementById('gdvModalXem');
                if (!modal || !xem) return;

                // "Xem tuỳ chọn gia hạn" chỉ đóng hộp thoại và đưa mắt xuống bảng
                // giá — nó KHÔNG mở khoá gì cả, và cũng không giả vờ như vậy.
                xem.addEventListener('click', function () {
                    modal.classList.add('is-hidden');
                    var bang = document.querySelector('.gdv-plans') || document.querySelector('.gdv-hero');
                    if (bang) bang.scrollIntoView({ behavior: 'smooth', block: 'start' });
                });
            })();
        </script>
    @endif
@endsection
