{{--
    Danh sách hợp đồng của phần mềm Sellio Order.

    MỘT view cho cả "Người dùng thử" (nhom=dung_thu) lẫn "Người dùng chính thức"
    (nhom=chinh_thuc): hai màn hình chỉ khác bộ lọc và lời dẫn, chứ cột thì giống
    hệt. Tách đôi ra thì mỗi lần thêm cột phải sửa hai chỗ và chúng sẽ lệch nhau.

    LỌC THEO NHÓM, KHÔNG THEO TRẠNG THÁI. Bảng vì thế có cả hợp đồng đang chạy,
    đã quá hạn và đã huỷ nằm cạnh nhau — cố ý: khách vừa hết hạn là khách phải
    gọi điện, mà lọc theo trạng thái thì họ biến mất khỏi màn hình đúng lúc đó.
    Trạng thái trở thành thứ để ĐỌC (cột "Còn lại") và để lọc thêm nếu muốn.

    Ba hạn mức (chi nhánh / tài khoản / sản phẩm) lấy THẲNG từ hợp đồng chứ
    không tra sang bảng giá: bảng giá được phép sửa, hợp đồng đã ký thì không.
    Giá trị 0 nghĩa là KHÔNG GIỚI HẠN — in ra chữ, không in số 0.

    BA THỨ CHỈ CÓ Ở MÀN HÌNH DÙNG THỬ, và cả ba đều xoay theo $nhom chứ
    không phải một bản Blade riêng:
      · nút "Thêm tài khoản dùng thử" (cần $plans để dựng bộ chọn gói);
      · thanh tiến độ trong cột hạn — "còn 3 ngày" không nói được 3 trên 7 hay 3
        trên 30, mà hai thứ đó dẫn tới hai hành động khác nhau;
      · nút hàng đọc là "Chuyển sang chính thức" thay vì "Gia hạn". CÙNG một
        đường bên Go: gia hạn đưa hợp đồng về `active` và xoá mốc hết dùng thử.

    Tìm · lọc · sắp xếp làm Ở TRÌNH DUYỆT trên đúng bộ dòng đã tải. Không phân
    trang, không gọi lại API: danh sách này đếm bằng chục, và mỗi lượt lọc mà
    phải đi vòng qua máy chủ thì ô tìm kiếm gõ tới đâu giật tới đó.
--}}
@extends('layouts.app')
@section('title', $tieuDe)

@php
    $la_dung_thu = $nhom === 'dung_thu';

    // Nhãn trạng thái HỢP ĐỒNG, dùng cho bộ lọc trạng thái. Cần từ khi bảng lọc
    // theo NHÓM: trước đây mọi dòng chắc chắn cùng một trạng thái nên không có
    // gì để lọc, giờ thì khách đang chạy, khách vừa hết hạn và khách đã huỷ nằm
    // cạnh nhau.
    //
    // Cùng bộ chữ với trang chi tiết: hai màn hình gọi cùng một thứ bằng hai cái
    // tên là cách chắc chắn khiến người dùng tưởng chúng khác nhau.
    //
    // THỨ TỰ CỦA MẢNG LÀ THỨ TỰ TRONG BỘ CHỌN — đi theo vòng đời hợp đồng (đang
    // thử → chính thức → quá hạn → đã huỷ), không phải theo bảng chữ cái.
    $nhan_trang_thai = [
        'trial' => 'Đang dùng thử',
        'active' => 'Chính thức',
        'past_due' => 'Quá hạn',
        'canceled' => 'Đã huỷ',
    ];
    // Ô của riêng form "Thêm tài khoản dùng thử". Dùng ở hai chỗ: quyết định có
    // mở sẵn hộp thoại không, và (trong chính partial đó) tách những lỗi KHÔNG
    // khớp ô nào ra một dải riêng thay vì để chúng rơi mất.
    $o_form_them = ['plan_id', 'so_ngay_dung_thu', 'ma_cua_hang', 'ten_cua_hang',
                    'ten_dang_nhap', 'mat_khau', 'nguoi_lien_he', 'dien_thoai',
                    'email_lien_he', 'ghi_chu'];

    // Mở lại hộp thoại sau khi máy chủ trả lỗi: người ta vừa gõ tám ô, đóng hộp
    // lại rồi bắt tự bấm mở ra xem sai chỗ nào là đánh mất cả tám ô trong đầu họ.
    //
    // Xét theo old() chứ không chỉ theo $errors: lỗi có thể mang tên trường Go
    // ("MaCuaHang") không khớp ô nào, và lúc đó vẫn phải mở hộp ra — chính là lúc
    // cần mở nhất, vì người dùng không biết chuyện gì vừa xảy ra.
    $mo_lai_form = old('ma_cua_hang') !== null || $errors->hasAny($o_form_them);
@endphp

@section('content')
    {{-- Nút việc chính của TRANG đứng ngang tiêu đề, không nằm trong hàng công cụ
         của bảng: đứng cạnh mấy ô lọc thì nó đọc như một nút thao tác trên bảng,
         mà "mở tài khoản cho khách mới" thì không phải việc của bảng. --}}
    <div class="page-bar">
        <div class="page-head">
            <span class="eyebrow">QLTK khách hàng order</span>
            <h2>{{ $tieuDe }}</h2>
            <p>{{ $moTa }}</p>
        </div>

        {{-- Xuất Excel đứng TRƯỚC nút thêm, cùng hàng với tiêu đề trang. --}}
        @if (count($hopDong))
            <form method="POST" action="{{ route('platform.khach-hang-order.hop-dong.xuat') }}"
                  data-xuat style="margin:0">
                @csrf
                <input type="hidden" name="nhom" value="{{ $nhom }}">
                <input type="hidden" name="ids" data-xuat-ids>
                <button type="submit" class="btn-ghost">Xuất Excel</button>
            </form>
        @endif

        @if ($ghiDuoc && count($plans))
            @if ($la_dung_thu)
                <button type="button" class="btn btn-plum" data-mo="sheet-them">Thêm tài khoản dùng thử</button>
            @else
                {{-- Đối ứng của nút bên trang dùng thử: cũng dựng cửa hàng + tài
                     khoản đăng nhập + hợp đồng trong một lượt, chỉ khác là hợp đồng
                     ra `active` ngay. Hộp thoại còn có chế độ thứ hai cho cửa hàng
                     đã tồn tại — xem partial. --}}
                <button type="button" class="btn btn-plum" data-mo="sheet-chinhthuc">Thêm hợp đồng chính thức</button>
            @endif
        @endif
    </div>

    @if ($loi)
        <div class="notice">{{ $loi }}</div>
    @endif

    @if ($la_dung_thu && $loiGoi)
        <div class="notice">Chưa lấy được bảng giá nên tạm thời không mở tài khoản dùng thử được: {{ $loiGoi }}</div>
    @endif

    <section class="panel">
        @if (count($hopDong))
            {{-- Hàng trên bảng chỉ có BỘ LỌC. Con số tổng kết chuyển xuống chân
                 bảng: đặt nó ở đây thì cả dải đọc như một thanh phân trang, và
                 người ta đi tìm nút sang trang không có. --}}
            <div class="toolbar">
                <input type="search" class="form-control tim" data-tim-o
                       placeholder="Tìm cửa hàng, mã, người liên hệ…"
                       aria-label="Tìm trong bảng">

                {{-- Bộ lọc gói dựng từ CHÍNH các dòng đang có, không phải từ bảng
                     giá: gói không ai đang dùng mà vẫn nằm trong bộ chọn thì chọn
                     vào chỉ ra bảng trống.

                     Lọc theo MÃ (giá trị option) nhưng hiện TÊN cho người đọc —
                     mã là thứ ổn định, tên là thứ đọc được. Gói đổi tên trong bảng
                     giá thì bộ lọc vẫn khớp đúng những dòng cũ. --}}
                @php
                    $cac_goi = collect($hopDong)
                        ->filter(fn ($h) => data_get($h, 'goi'))
                        ->mapWithKeys(fn ($h) => [
                            data_get($h, 'goi') => data_get($h, 'ten_goi') ?: data_get($h, 'goi'),
                        ])
                        ->sort();
                @endphp
                @if ($cac_goi->count() > 1)
                    <select class="form-select" data-loc-goi aria-label="Lọc theo gói">
                        <option value="">Mọi gói</option>
                        @foreach ($cac_goi as $ma => $ten_goi)
                            <option value="{{ $ma }}">{{ $ten_goi }}</option>
                        @endforeach
                    </select>
                @endif

                {{-- Lọc theo TRẠNG THÁI hợp đồng. Có mặt từ khi bảng giữ lại cả
                     khách đã hết hạn và đã huỷ: danh sách đầy đủ là thứ đúng để
                     lưu, nhưng "hôm nay tôi chỉ muốn xem khách đang chạy" cũng là
                     một câu hỏi có thật.

                     Dựng từ CHÍNH các dòng đang có, giống bộ lọc gói: bảng không
                     có dòng nào đã huỷ thì cũng không có mục "Đã huỷ" để chọn
                     nhầm rồi ra bảng trống. --}}
                @php
                    $cac_tt = collect($hopDong)
                        ->map(fn ($h) => data_get($h, 'trang_thai'))
                        ->filter()
                        ->unique()
                        ->sortBy(fn ($tt) => array_search($tt, array_keys($nhan_trang_thai), true));
                @endphp
                @if ($cac_tt->count() > 1)
                    <select class="form-select" data-loc-tt aria-label="Lọc theo trạng thái">
                        <option value="">Mọi trạng thái</option>
                        @foreach ($cac_tt as $tt)
                            <option value="{{ $tt }}">{{ $nhan_trang_thai[$tt] ?? $tt }}</option>
                        @endforeach
                    </select>
                @endif

                <span class="spacer"></span>

                {{-- BỘ LỌC THEO HẠN — ba mốc, và ba mốc đó là ba việc khác nhau:
                     quá hạn là việc đã hỏng, dưới 7 ngày là việc phải gọi khách
                     hôm nay, dưới 30 ngày là việc của tuần này.

                     Con số nằm ngay trên nút chứ không phải một dải riêng: người
                     đọc thấy "3" rồi bấm luôn vào đúng chỗ đó, không phải đọc một
                     con số ở trên rồi đi tìm bộ lọc ở dưới.

                     Mốc nào KHÔNG có dòng nào thì không hiện nút — một nút bấm vào
                     ra bảng trống là một nút không nên có. --}}
                @php
                    $dem = fn ($loc) => collect($hopDong)->filter($loc)->count();
                    $mocHan = [
                        ['qua-han', 'quá hạn', $dem(fn ($h) => (int) data_get($h, 'con_lai_ngay') < 0), 'tag-bad'],
                        ['7', '≤ 7 ngày', $dem(fn ($h) => (int) data_get($h, 'con_lai_ngay') >= 0 && (int) data_get($h, 'con_lai_ngay') <= 7), 'tag-warn'],
                        ['30', '≤ 30 ngày', $dem(fn ($h) => (int) data_get($h, 'con_lai_ngay') >= 0 && (int) data_get($h, 'con_lai_ngay') <= 30), 'tag-mute'],
                    ];
                @endphp
                @if (collect($mocHan)->sum(fn ($m) => $m[2]))
                    <div class="loc-han" role="group" aria-label="Lọc theo hạn">
                        @foreach ($mocHan as [$gia_tri, $nhan, $so, $mau])
                            @continue(! $so)
                            <button type="button" class="chip" data-loc-han="{{ $gia_tri }}">
                                {{ $nhan }} <span class="tag {{ $mau }}">{{ $so }}</span>
                            </button>
                        @endforeach
                    </div>
                @endif
            </div>

            <div class="tbl dense">
                <table data-bang>
                    <thead>
                    <tr>
                        {{-- SÁU cột, không phải tám. Hai cột bị bỏ và lý do:
                             · "Hạn mức" in `1 · ∞ · ∞` — không nói được con số nào
                               là chi nhánh, cái nào là sản phẩm, phải rê chuột đọc
                               tooltip mới hiểu. Giờ nằm dưới tên gói, bằng chữ.
                             · "Tên miền" là một cột trọn vẹn cho một giá trị đúng/sai
                               mà hầu hết dòng đều "Không" — cả cột chỉ để thỉnh
                               thoảng nói một chữ. Giờ chỉ hiện khi CÓ. --}}
                        <th class="sortable" data-sort="text">Cửa hàng</th>
                        <th class="sortable" data-sort="text">Người liên hệ</th>
                        <th class="sortable" data-sort="text">Gói dịch vụ</th>
                        <th class="num sortable" data-sort="so">Giá</th>
                        @if (! $la_dung_thu)
                            {{-- Chỉ khách TRẢ TIỀN mới có cột này: khách dùng thử
                                 chưa trả đồng nào, và một cột toàn số 0 chỉ làm
                                 bảng rộng thêm. --}}
                            <th class="num sortable" data-sort="so">Đã thu</th>
                        @endif
                        <th class="tight sortable" data-sort="so" aria-sort="ascending">
                            {{ $la_dung_thu ? 'Dùng thử tới' : 'Hết hạn' }}
                        </th>
                        <th class="tight sortable" data-sort="so">Còn lại</th>
                        {{-- Cột thao tác LUÔN có: nút "Chi tiết" hiện cho mọi vai
                             trò, kể cả `support` — đó là đường đọc. --}}
                        <th class="act"><span class="visually-hidden">Thao tác</span></th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($hopDong as $hd)
                        @php
                            $con_lai = (int) data_get($hd, 'con_lai_ngay');
                            // Ba mốc, không phải hai: quá hạn là việc đã hỏng,
                            // dưới 7 ngày là việc phải gọi khách hôm nay, còn
                            // lại thì không cần ai để mắt tới.
                            $mau_han = $con_lai < 0 ? 'tag-bad' : ($con_lai <= 7 ? 'tag-warn' : 'tag-mute');
                            // Hợp đồng đã đóng: API từ chối cả gia hạn lẫn thu tiền
                            // trên nó (ErrHopDongDaHuy), nên hai nút đó phải biến
                            // mất chứ không phải hiện ra rồi báo lỗi khi bấm.
                            $da_huy = data_get($hd, 'trang_thai') === 'canceled';
                            // Còn kỳ nào để thu không — MÁY CHỦ trả lời, giao
                            // diện không tự suy. Sổ thu chỉ nhận một lần thu cho
                            // một kỳ, nên hợp đồng đã trả tới đúng hạn hiện tại
                            // thì lượt thu tiếp theo chắc chắn ăn lỗi "không còn
                            // kỳ để thu" — nút phải biến mất, cùng lý do với hợp
                            // đồng đã huỷ ngay trên.
                            //
                            // Mặc định TRUE khi API chưa có ô này: máy chủ có thể
                            // cũ hơn giao diện, và giấu mất nút Thu tiền của mọi
                            // dòng thì hỏng nặng hơn là chìa ra một nút thỉnh
                            // thoảng báo lỗi.
                            $con_ky_thu = (bool) data_get($hd, 'con_ky_de_thu', true);
                            $thu_den = data_get($hd, 'da_thu_den');
                            $het_han = data_get($hd, 'het_han');
                            $bat_dau = data_get($hd, 'bat_dau');
                            // Ép chuỗi: cả hai ô đều rỗng được, và mb_substr(null)
                            // là cảnh báo deprecated từ PHP 8.1 — nó không làm
                            // trang hỏng, chỉ đổ đầy log rồi không ai đọc log nữa.
                            $ten = (string) (data_get($hd, 'ten_cua_hang') ?: data_get($hd, 'ma_cua_hang'));

                            // Phần đã đi qua của kỳ hạn, 0–100. Tính từ hai mốc
                            // của CHÍNH hợp đồng chứ không từ số ngày của bảng
                            // giá: khách được cho thêm ngày là chuyện có thật, và
                            // thanh phải nói đúng kỳ hạn họ đang có.
                            $phan_tram = null;
                            if ($bat_dau && $het_han) {
                                $t0 = \Illuminate\Support\Carbon::parse($bat_dau);
                                $t1 = \Illuminate\Support\Carbon::parse($het_han);
                                $tong = $t0->diffInSeconds($t1, false);
                                $phan_tram = $tong > 0
                                    ? max(0, min(100, (int) round($t0->diffInSeconds(now(), false) / $tong * 100)))
                                    : 100;
                            }

                            // Chuỗi để ô tìm kiếm soi vào. Dựng ở PHP chứ không
                            // để JS tự đọc từng ô: JS đọc textContent sẽ quét cả
                            // chữ trong nhãn trạng thái, và gõ "có" sẽ khớp mọi
                            // dòng có tên miền riêng.
                            $tim = mb_strtolower(implode(' ', array_filter([
                                data_get($hd, 'ten_cua_hang'),
                                data_get($hd, 'ma_cua_hang'),
                                data_get($hd, 'nguoi_lien_he'),
                                data_get($hd, 'dien_thoai'),
                                data_get($hd, 'email'),
                                data_get($hd, 'goi'),
                                data_get($hd, 'ten_goi'),
                            ])));
                        @endphp
                        <tr data-id="{{ data_get($hd, 'id') }}" data-han="{{ $con_lai }}"
                            data-tim="{{ $tim }}" data-goi="{{ data_get($hd, 'goi') }}"
                            data-tt="{{ data_get($hd, 'trang_thai') }}">
                            <td data-v="{{ mb_strtolower($ten) }}">
                                <div class="cell2">
                                    <span class="avatar" aria-hidden="true">{{ mb_substr($ten, 0, 1) }}</span>
                                    <div>
                                        <div class="b">{{ $ten }}</div>
                                        <div class="s mono">{{ data_get($hd, 'ma_cua_hang') }}</div>
                                    </div>
                                </div>
                            </td>
                            <td data-v="{{ mb_strtolower((string) data_get($hd, 'nguoi_lien_he')) }}">
                                @if (data_get($hd, 'nguoi_lien_he') || data_get($hd, 'dien_thoai'))
                                    <div class="b">{{ data_get($hd, 'nguoi_lien_he') ?: '—' }}</div>
                                    @if ($sdt = data_get($hd, 'dien_thoai'))
                                        {{-- tel: để bấm là gọi luôn từ máy tính có
                                             phần mềm điện thoại — đây là danh sách
                                             người ta mở ra để gọi. --}}
                                        <a class="s mono" href="tel:{{ $sdt }}">{{ $sdt }}</a>
                                    @endif
                                @else
                                    <span class="muted">Chưa có</span>
                                @endif
                            </td>
                            <td data-v="{{ mb_strtolower((string) data_get($hd, 'ten_goi')) }}">
                                {{-- TÊN gói ("Cửa hàng"), không phải mã (`cua_hang`).
                                     API tra tên từ bảng giá qua plan_id và tự rơi về
                                     mã khi không tra được, nên ở đây không cần chỗ
                                     dự phòng nào. --}}
                                <div class="b">{{ data_get($hd, 'ten_goi') ?: '—' }}</div>
                                {{-- Hạn mức viết BẰNG CHỮ ngay dưới tên gói: đây là
                                     "khách này mua được những gì", cùng một câu với
                                     tên gói chứ không phải một cột riêng gồm ba con
                                     số trần không nhãn.
                                     0 = KHÔNG GIỚI HẠN — in ∞, không in số 0; số 0
                                     đọc thành "không được cái nào". --}}
                                @php
                                    $so = fn ($v) => ((int) $v) ?: '∞';
                                    $han_muc = [
                                        $so(data_get($hd, 'chi_nhanh')) . ' chi nhánh',
                                        $so(data_get($hd, 'tai_khoan')) . ' tài khoản',
                                        $so(data_get($hd, 'san_pham')) . ' sản phẩm',
                                    ];
                                @endphp
                                <div class="s">
                                    {{ implode(' · ', $han_muc) }}
                                    @if (data_get($hd, 'ten_mien_rieng'))
                                        · <span class="tag tag-good" style="font-size:9.5px;padding:1px 5px">Tên miền riêng</span>
                                    @endif
                                </div>
                            </td>
                            <td class="num" data-v="{{ (float) data_get($hd, 'gia') }}">
                                {{-- Kèm đơn vị tiền và chu kỳ: "990.000" trần không
                                     nói được đó là mỗi tháng hay trọn hợp đồng. --}}
                                <div class="b">{{ number_format((float) data_get($hd, 'gia'), 0, ',', '.') }} ₫</div>
                                <div class="s">{{ data_get($hd, 'chu_ky') === 'nam' ? 'mỗi năm' : 'mỗi tháng' }}</div>
                            </td>
                            @if (! $la_dung_thu)
                                @php $thu = $daThu->get(data_get($hd, 'ma_cua_hang')); @endphp
                                {{-- TIỀN ĐÃ VÀO THẬT (gộp từ sổ thu), không phải
                                     tiền đáng lẽ phải thu theo hợp đồng. Hai con số
                                     đó chỉ bằng nhau vào tháng mà mọi khách trả đủ
                                     và đúng hạn — nên cột này mới là cột nói được
                                     ai đang nợ. --}}
                                <td class="num" data-v="{{ (float) data_get($thu, 'tong_tien') }}">
                                    @if ($thu)
                                        <div class="b">{{ number_format((float) data_get($thu, 'tong_tien'), 0, ',', '.') }} ₫</div>
                                        <div class="s">
                                            {{ (int) data_get($thu, 'so_lan_thu') }} lần
                                            @if ($lc = data_get($thu, 'lan_cuoi')) · {{ $lc }} @endif
                                        </div>
                                    @else
                                        {{-- Chưa thu đồng nào KHÁC hẳn thu 0 đồng.
                                             In số 0 ở đây thì một khách chưa trả
                                             tiền trông y hệt khách được miễn phí. --}}
                                        <span class="muted">Chưa thu</span>
                                    @endif

                                    {{-- Dòng này là LỜI GIẢI THÍCH cho cái nút vắng
                                         mặt ở cột cuối: khách đã trả tiền tới đúng
                                         hạn hợp đồng nên không còn kỳ nào để thu.
                                         Bỏ nút đi mà không nói gì thì người thu tiền
                                         đi tìm nó, rồi tưởng màn hình hỏng.

                                         Không in cho hợp đồng đã huỷ: ở đó nút vắng
                                         mặt vì một lý do khác, và cột "Còn lại" đã
                                         nói ra lý do ấy. --}}
                                    @if ($thu_den && ! $con_ky_thu && ! $da_huy)
                                        <div class="s">
                                            Đủ tới {{ \Illuminate\Support\Carbon::parse($thu_den)->format('d/m/Y') }}
                                        </div>
                                    @endif
                                </td>
                            @endif
                            {{-- Cột hạn sắp theo con_lai_ngay chứ không theo chuỗi
                                 ngày: "01/09" và "13/08" so bằng chữ thì tháng 9
                                 đứng trước tháng 8. --}}
                            <td class="tight" data-v="{{ $con_lai }}">
                                {{-- Ngày ở dòng trên, GIỜ ở dòng dưới. Hạn tính tới
                                     từng phút — cột "Còn lại" bên cạnh suy ra từ đúng
                                     mốc này — nên hai hợp đồng cùng ngày mà lệch nhau
                                     nửa buổi phải nhìn ra được. Tách hai dòng để cột
                                     không phình ngang. --}}
                                <div class="mono">
                                    {{ $het_han ? \Illuminate\Support\Carbon::parse($het_han)->format('d/m/Y') : '—' }}
                                </div>
                                @if ($het_han)
                                    <div class="s mono">{{ \Illuminate\Support\Carbon::parse($het_han)->format('H:i') }}</div>
                                @endif
                                {{-- Thanh đo chỉ vẽ khi CÒN hạn. Quá hạn thì nó luôn
                                     đầy 100%, tức là một vạch đặc màu nằm ngay dưới
                                     dòng ngày — đọc thành gạch chân chứ không ra
                                     thanh đo, mà cũng chẳng thêm thông tin gì: nhãn
                                     "QUÁ 3 NGÀY" ngay bên cạnh đã nói đủ. --}}
                                @if ($la_dung_thu && $phan_tram !== null && $con_lai >= 0)
                                    <div class="meter {{ $con_lai <= 7 ? 'is-warn' : '' }}" aria-hidden="true">
                                        <i style="width:{{ $phan_tram }}%"></i>
                                    </div>
                                @endif
                            </td>
                            <td class="tight" data-v="{{ $con_lai }}">
                                {{-- ĐÃ HUỶ thì số ngày không còn nghĩa gì: hợp đồng
                                     đóng từ tháng trước vẫn có một cái hạn nằm đâu
                                     đó, và in "Quá 40 ngày" ở đây đọc thành khách
                                     đang nợ tiền — trong khi họ đã dừng hẳn.

                                     Ba trạng thái còn lại KHÔNG cần nhãn riêng:
                                     "Quá 3 ngày" đỏ đã nói hết hạn, "12 ngày" đã
                                     nói đang chạy. Thêm một cột trạng thái nữa chỉ
                                     là in cùng một điều hai lần. --}}
                                @if ($da_huy)
                                    <span class="tag tag-mute">Đã huỷ</span>
                                @else
                                    <span class="tag {{ $mau_han }}">
                                        {{ $con_lai < 0 ? 'Quá ' . abs($con_lai) . ' ngày' : $con_lai . ' ngày' }}
                                    </span>
                                @endif
                            </td>
                            <td class="act">
                                {{-- "Chi tiết" hiện cho MỌI vai trò, kể cả `support`
                                     — đó là đường đọc, và trang bên kia tự xét lại
                                     quyền để quyết định hiện form sửa hay chỉ in ra. --}}
                                <a class="btn-mini"
                                   href="{{ route('platform.khach-hang-order.hop-dong.chi-tiet', data_get($hd, 'id')) }}">
                                    Chi tiết
                                </a>

                                @if ($ghiDuoc && ! $da_huy)
                                    @if (! $la_dung_thu && $con_ky_thu)
                                        {{-- Chỉ khách trả tiền mới có nút này. Ghi
                                             nhận TIỀN, không đẩy HẠN — hai việc
                                             tách rời, xem hộp thoại.

                                             Và chỉ khi CÒN KỲ ĐỂ THU: khách đã trả
                                             tới đúng hạn hợp đồng thì lượt thu tiếp
                                             theo bị API từ chối, nên nút biến mất
                                             chứ không hiện ra rồi báo lỗi lúc bấm.
                                             Muốn thu tiếp thì gia hạn trước — hạn
                                             dài ra là có kỳ mới. --}}
                                        <button type="button" class="btn-mini"
                                                data-mo="sheet-thutien"
                                                data-id="{{ data_get($hd, 'id') }}"
                                                data-ten="{{ $ten }}"
                                                data-gia="{{ (int) data_get($hd, 'gia') }}">
                                            Thu tiền
                                        </button>
                                    @endif

                                    {{-- Hai nút mở CHUNG một hộp thoại, chỉ nạp id
                                         và tên khách vào. Dựng một hộp cho mỗi dòng
                                         thì một bảng 50 khách có 100 hộp thoại nằm
                                         trong trang. --}}
                                    <button type="button" class="btn-mini is-primary"
                                            data-mo="sheet-giahan"
                                            data-id="{{ data_get($hd, 'id') }}"
                                            data-ten="{{ $ten }}"
                                            data-goi="{{ data_get($hd, 'ten_goi') ?: data_get($hd, 'goi') }}"
                                            data-chu-ky="{{ data_get($hd, 'chu_ky') }}">
                                        {{-- "Chính thức" chứ không "Chuyển sang
                                             chính thức": nhãn dài nhân với số dòng
                                             là chỗ đẩy cột cuối ra ngoài mép. Câu
                                             đầy đủ nằm ở tiêu đề hộp thoại, nơi có
                                             chỗ cho nó. --}}
                                        {{ $la_dung_thu ? 'Chính thức' : 'Gia hạn' }}
                                    </button>
                                    <button type="button" class="btn-mini is-bad"
                                            data-mo="sheet-huy"
                                            data-id="{{ data_get($hd, 'id') }}"
                                            data-ten="{{ $ten }}">
                                        Huỷ
                                    </button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    {{-- Dòng này chỉ hiện khi bộ lọc không khớp gì. Khác hẳn khối
                         .empty bên dưới: ở đây dữ liệu có sẵn, chỉ là đang bị lọc
                         đi — hai câu đó dẫn tới hai việc khác nhau. --}}
                    <tr class="no-hit" hidden>
                        {{-- Trang chính thức có thêm cột "Đã thu" nên rộng hơn một
                             cột. Số cứng ở đây mà lệch thì dòng này thụt vào giữa
                             bảng, và không lỗi nào nổi lên. --}}
                        <td colspan="{{ $la_dung_thu ? 7 : 8 }}">
                            Không hợp đồng nào khớp với ô tìm kiếm hoặc bộ lọc đang bật.
                        </td>
                    </tr>
                    </tbody>
                </table>
            </div>

            {{-- Tổng kết ở CHÂN bảng, đúng nơi mắt dừng lại sau khi đọc hết. Hai
                 con số vì chúng khác nhau khi bộ lọc đang bật — bảng còn 3 dòng mà
                 vẫn ghi "27 hợp đồng" thì đọc như dữ liệu vừa mất. --}}
            <div class="toolbar is-foot">
                <span>
                    Đang hiện <strong data-dem-hien>{{ count($hopDong) }}</strong>
                    / {{ count($hopDong) }} hợp đồng
                </span>
            </div>
        @else
            <div class="empty">
                @if ($loi)
                    <strong>Chưa lấy được dữ liệu</strong>
                    Bảng trống vì lần gọi API vừa rồi hỏng, không phải vì chưa có hợp đồng nào.
                @elseif ($la_dung_thu)
                    <strong>Chưa có ai đang dùng thử</strong>
                    @if ($ghiDuoc && count($plans))
                        {{-- <b> chứ không <strong>: `.empty strong` là display:block
                             (nó dành cho dòng tiêu đề của khối rỗng), nên một
                             <strong> nằm GIỮA CÂU sẽ cắt câu đó làm ba dòng. --}}
                        Bấm <b>Thêm tài khoản dùng thử</b> ở trên để mở tài khoản cho khách đầu tiên.
                    @else
                        Hợp đồng dùng thử cũng tạo được bằng công cụ <span class="mono">cmd/thue-bao</span> trên máy chủ.
                    @endif
                @else
                    <strong>Chưa có hợp đồng chính thức nào</strong>
                    Khách hết thời gian dùng thử và trả tiền thì hợp đồng chuyển sang đây.
                @endif
            </div>
        @endif
    </section>

    @if ($ghiDuoc)
        @include('khach-hang-order.partials.hop-dong-thao-tac', ['laDungThu' => $la_dung_thu])
    @endif

    @if (! $la_dung_thu && $ghiDuoc)
        @include('khach-hang-order.partials.thu-tien')

        @if (count($plans))
            @include('khach-hang-order.partials.them-chinh-thuc')
        @endif
    @endif

    @if ($la_dung_thu && $ghiDuoc && count($plans))
        @include('khach-hang-order.partials.them-dung-thu', [
            'plans' => $plans,
            'moLai' => $mo_lai_form,
            'oForm' => $o_form_them,
        ])
    @endif
@endsection

@push('scripts')
    <script>
        // ===== Công cụ của bảng: tìm · lọc · sắp xếp =====
        //
        // Chạy trên đúng bộ dòng đã tải, không gọi lại API. Danh sách này đếm bằng
        // chục; mỗi lượt lọc mà đi vòng qua máy chủ thì ô tìm kiếm gõ tới đâu giật
        // tới đó, và người dùng sẽ thôi dùng nó.
        (function () {
            var bang = document.querySelector('[data-bang]');
            if (!bang) return;

            var than = bang.tBodies[0];
            var oTim = document.querySelector('[data-tim-o]');
            var oGoi = document.querySelector('[data-loc-goi]');
            var oTT = document.querySelector('[data-loc-tt]');
            var demHien = document.querySelector('[data-dem-hien]');
            var khongKhop = than.querySelector('.no-hit');
            var dong = Array.prototype.slice.call(than.querySelectorAll('tr[data-tim]'));

            // Bỏ dấu tiếng Việt để "hue" tìm ra "Huệ". Người gõ nhanh trên bàn phím
            // không bộ gõ vẫn phải tìm được khách của mình — nếu không thì ô tìm
            // kiếm chỉ dùng được cho tên không dấu.
            //
            // Chữ đ phải xử riêng: NFD không tách nó ra thành d + dấu, nên chỉ
            // normalize thôi thì "dong" không khớp "Đông".
            function khongDau(s) {
                return (s || '')
                    .normalize('NFD').replace(/[̀-ͯ]/g, '')
                    .replace(/đ/g, 'd').replace(/Đ/g, 'D')
                    .toLowerCase();
            }

            dong.forEach(function (tr) { tr._tim = khongDau(tr.getAttribute('data-tim')); });

            // Mốc hạn đang chọn: '' | 'qua-han' | '7' | '30'. Giữ ở biến chứ không
            // đọc lại từ DOM mỗi lần lọc — một nguồn sự thật, và nút nào đang bật
            // chỉ là hình chiếu của nó.
            var mocHan = '';

            function hopHan(tr) {
                if (!mocHan) return true;
                var n = parseInt(tr.getAttribute('data-han'), 10);
                if (mocHan === 'qua-han') return n < 0;

                // "≤ 7 ngày" KHÔNG bao gồm dòng đã quá hạn: quá hạn là việc đã
                // hỏng, còn 7 ngày là việc sắp phải làm. Gộp lại thì bấm vào mốc 7
                // ngày lại thấy cả những dòng lẽ ra thuộc nhóm đỏ.
                return n >= 0 && n <= parseInt(mocHan, 10);
            }

            function loc() {
                var q = khongDau(oTim ? oTim.value.trim() : '');
                var goi = oGoi ? oGoi.value : '';
                var tt = oTT ? oTT.value : '';
                var hien = 0;

                dong.forEach(function (tr) {
                    var khop = (!q || tr._tim.indexOf(q) !== -1)
                        && (!goi || tr.getAttribute('data-goi') === goi)
                        && (!tt || tr.getAttribute('data-tt') === tt)
                        && hopHan(tr);
                    tr.hidden = !khop;
                    if (khop) hien++;
                });

                if (demHien) demHien.textContent = hien;
                if (khongKhop) khongKhop.hidden = hien !== 0;
            }

            if (oTim) oTim.addEventListener('input', loc);
            if (oGoi) oGoi.addEventListener('change', loc);
            if (oTT) oTT.addEventListener('change', loc);

            // Nút mốc hạn bật/tắt được: bấm lại chính nó là bỏ lọc. Không có nút
            // "Tất cả" riêng — thêm một nút chỉ để huỷ một nút khác là thêm một
            // thứ phải giải thích.
            document.querySelectorAll('[data-loc-han]').forEach(function (nut) {
                nut.addEventListener('click', function () {
                    var v = nut.getAttribute('data-loc-han');
                    mocHan = (mocHan === v) ? '' : v;

                    document.querySelectorAll('[data-loc-han]').forEach(function (x) {
                        x.classList.toggle('is-on', x.getAttribute('data-loc-han') === mocHan);
                    });
                    loc();
                });
            });

            // ----- Xuất Excel -----
            //
            // Gom id các dòng ĐANG HIỆN vào ô ẩn ngay lúc bấm gửi, chứ không cập
            // nhật sau mỗi lần gõ: bộ lọc chạy theo từng phím, còn xuất thì một
            // lần — làm ở đây là đọc đúng trạng thái tại thời điểm bấm.
            //
            // Không dòng nào khớp thì để ô TRỐNG, và máy chủ hiểu là xuất trọn
            // danh sách. Gửi một danh sách rỗng rồi nhận về tệp không có dòng nào
            // thì người dùng tưởng chức năng hỏng.
            var formXuat = document.querySelector('[data-xuat]');
            if (formXuat) {
                var oIds = formXuat.querySelector('[data-xuat-ids]');
                formXuat.addEventListener('submit', function () {
                    var hien = dong.filter(function (tr) { return !tr.hidden; });
                    oIds.value = hien.length === dong.length
                        ? ''
                        : hien.map(function (tr) { return tr.getAttribute('data-id'); }).join(',');

                    // Báo ngay lúc bấm, đừng chờ tệp về.
                    //
                    // Tải tệp KHÔNG rời trang: trình duyệt nhận Content-Disposition
                    // rồi lưu tệp, còn màn hình đứng nguyên — nên bấm xong không có
                    // gì nhúc nhích, và người dùng bấm lại lần nữa. Toast là thứ
                    // duy nhất nói "đã nhận, đang làm".
                    //
                    // Cũng vì trang không rời đi nên toast sống đủ 5 giây của nó;
                    // đây không phải trường hợp thông báo bị cắt giữa chừng vì
                    // chuyển trang.
                    if (window.saasToast) {
                        window.saasToast(
                            'Đang xuất ' + hien.length + ' hợp đồng ra tệp Excel — kiểm tra thư mục Tải về.',
                            'success'
                        );
                    }
                });
            }

            // ----- Sắp xếp -----
            //
            // Đọc data-v của ô chứ không đọc chữ hiện ra: cột hạn hiển thị
            // "13/08/2026" nhưng sắp theo số ngày còn lại, và cột giá hiển thị
            // "9.990.000" — so hai chuỗi đó bằng chữ thì thứ tự sai mà nhìn vẫn
            // như đúng.
            var tieuDe = Array.prototype.slice.call(bang.tHead.querySelectorAll('th.sortable'));

            tieuDe.forEach(function (th, i) {
                // Chỉ số cột tính trên hàng tiêu đề đầy đủ, không phải trên danh
                // sách th.sortable — hai cột không sắp được nằm xen giữa.
                var cot = Array.prototype.indexOf.call(th.parentNode.children, th);
                var kieu = th.getAttribute('data-sort');

                th.setAttribute('tabindex', '0');
                th.setAttribute('role', 'columnheader');

                function sap() {
                    var nguoc = th.getAttribute('aria-sort') === 'ascending';

                    // Xoá dấu ở mọi cột trước: hai cột cùng mang aria-sort thì
                    // trình đọc màn hình đọc ra hai thứ tự đang có hiệu lực.
                    tieuDe.forEach(function (x) { x.removeAttribute('aria-sort'); });
                    th.setAttribute('aria-sort', nguoc ? 'descending' : 'ascending');

                    var chieu = nguoc ? -1 : 1;
                    var xep = dong.slice().sort(function (a, b) {
                        var va = a.children[cot].getAttribute('data-v') || '';
                        var vb = b.children[cot].getAttribute('data-v') || '';
                        if (kieu === 'so') return (parseFloat(va) - parseFloat(vb)) * chieu;

                        return va.localeCompare(vb, 'vi') * chieu;
                    });

                    // appendChild dời dòng đang có chứ không dựng lại: giữ nguyên
                    // mọi listener và trạng thái hidden của bộ lọc.
                    xep.forEach(function (tr) { than.appendChild(tr); });
                    // Dòng "không khớp" phải ở lại cuối bảng sau mỗi lần sắp.
                    if (khongKhop) than.appendChild(khongKhop);
                }

                th.addEventListener('click', sap);
                th.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); sap(); }
                });
            });
        })();

        // ===== Hộp thoại =====
        //
        // <dialog> của trình duyệt: tự khoá tiêu điểm bàn phím trong hộp, tự đóng
        // bằng Esc. Nút mở khai data-mo="<id hộp>" và có thể kèm data-* để nạp vào
        // hộp — nhờ vậy một hộp phục vụ được mọi dòng của bảng.
        (function () {
            document.querySelectorAll('[data-mo]').forEach(function (nut) {
                nut.addEventListener('click', function () {
                    var hop = document.getElementById(nut.getAttribute('data-mo'));
                    if (!hop) return;

                    // Nạp dữ liệu của dòng vừa bấm vào hộp. Mỗi ô đích khai
                    // data-nap="<tên data-* trên nút>".
                    hop.querySelectorAll('[data-nap]').forEach(function (o) {
                        var v = nut.getAttribute('data-' + o.getAttribute('data-nap'));
                        if (v === null) return;
                        if (o.tagName === 'INPUT' || o.tagName === 'SELECT') o.value = v;
                        else o.textContent = v;
                    });

                    // Đường gửi của form dựng từ mẫu có {id}: route của Laravel cần
                    // id nằm trong đường dẫn, mà id thì chỉ biết lúc bấm.
                    var form = hop.querySelector('form[data-mau]');
                    var id = nut.getAttribute('data-id');
                    if (form && id) form.action = form.getAttribute('data-mau').replace('__ID__', id);

                    hop.showModal();
                });
            });

            document.querySelectorAll('dialog.sheet').forEach(function (hop) {
                hop.querySelectorAll('[data-dong]').forEach(function (nut) {
                    nut.addEventListener('click', function () { hop.close(); });
                });

                // Bấm ra ngoài hộp là đóng. ::backdrop không nhận sự kiện riêng,
                // nên nhận ở chính <dialog> rồi đối chiếu: mọi thứ bên trong đều
                // nằm trong .sheet-*, nên target là chính dialog nghĩa là bấm nền.
                hop.addEventListener('click', function (e) {
                    if (e.target === hop) hop.close();
                });
            });
        })();
    </script>
@endpush
