{{-- Màn Quản lý thu chi — chép khuôn từ bản v2 cũ
     (ordertable/v2/resources/views/cashbook/income-expense/index.blade.php + list + modal-detail).

     Giữ đúng dáng của v2: khung lọc bên trái, bốn ô quỹ trên đầu bảng, bảng chọn
     được cột, hộp lập phiếu hai cột, hộp thêm nhanh người nộp, hộp xem chi tiết
     và tấm trượt chi tiết cho điện thoại.

     Ba chỗ chữa lại so với v2:
     - Hộp thêm người nộp của v2 bày sáu ô nhưng chỉ GỬI bốn (loại KH, giới tính,
       ngày sinh rơi mất), mà một trong bốn — email — còn không có ô nào trong
       hộp. Bên này chỉ bày đúng ba ô thật sự gửi đi.
     - Ô "Tất cả" của bảng chọn cột bên v2 so chuỗi '0' với số 0 nên luôn tick
       sẵn dù đang tắt cột. Bên này đọc thẳng ?hide=.
     - Nhãn lọc nguồn phát sinh nói đúng cái nó lọc (xem ThuChiController::NGUON). --}}
@extends('v2::layouts.master')

@section('title', \App\Http\Controllers\ThuChiController::TITLE_PAGE)

@php
    $C = \App\Http\Controllers\ThuChiController::class;

    // Cột đang tắt nằm ở ?hide= chứ không phải trong CSDL như v2 — giữ được sau
    // khi đổi trang mà không cần bảng user_selected_columns.
    $cotTat = array_filter(explode(',', (string) request()->query('hide', '')));
    $columns = [];
    foreach (array_keys($C::COT_BANG) as $c) {
        $columns['show_'.$c] = in_array($c, $cotTat, true) ? 0 : 1;
    }

    $stt = ($meta['page'] - 1) * $meta['page_size'];
    $tien = fn ($n) => number_format((float) $n, 0, ',', '.');
    $ngayVN = fn ($v) => $v ? date('d-m-Y', strtotime($v)) : '';

    // Bốn ô quỹ: số từ một tỷ trở lên rút thành "~ 1,234 tỷ" — theo
    // formatVietnameseMoneySmart của v2, để ô không vỡ chữ khi tiệm chạy lớn.
    //
    // Khác v2 hai chỗ: dấu thập phân là dấu phẩy (v2 in "2.500 tỷ" cho 2,5 tỷ —
    // người Việt đọc dấu chấm là hàng nghìn, ra hai nghìn rưỡi tỷ), và cắt số 0
    // thừa ở đuôi nên 2,5 tỷ in đúng "2,5 tỷ" chứ không phải "2,500 tỷ".
    $tienGon = function ($n) {
        $n = (float) $n;
        $abs = abs($n);
        if ($abs < 1e9) {
            return number_format($n, 0, ',', '.');
        }
        $ty = round($abs / 1e9, 3);
        $so = number_format($ty, 3, ',', '.');
        $so = rtrim(rtrim($so, '0'), ',');

        return ($n < 0 ? '~ -' : '~ ').$so.' '.__('message.billion');
    };

    // Bộ lọc đang bật — để câu "bảng rỗng" nói đúng lý do.
    $coLoc = collect($filters)
        ->only(['keyword', 'type', 'category_id', 'source', 'created_by'])
        ->contains(fn ($v) => $v !== '' && $v !== null);

    // Ba danh sách đổ thẳng sang JS. Dựng ở đây rồi @json một biến trần: viết
    // của Blade và cả trang không biên dịch được.
    // Kèm `quyen` (cửa vào — quan_ly / thu_ngan) để ô "Người nộp" lọc thẳng theo
    // vai đã chọn ở ô trên, không phải gọi thêm một vòng mạng cho mỗi lần đổi.
    $jsNhanVien = collect($nhanVien)
        ->map(fn ($n) => [
            'id' => (string) ($n['id'] ?? ''),
            'name' => $n['full_name'] ?? ($n['name'] ?? ''),
            'quyen' => array_values(array_map('strval', (array) ($n['quyen'] ?? []))),
        ])
        ->values();
    $jsNhaCungCap = collect($nhaCungCap)
        ->map(fn ($n) => ['id' => (string) ($n['id'] ?? ''), 'name' => $n['name'] ?? ''])
        ->values();
    $jsPhanLoai = collect($phanLoai)
        ->map(fn ($p) => [
            'id' => (string) ($p['id'] ?? ''),
            'type' => (string) ($p['type'] ?? ''),
            'name' => $p['name'] ?? '',
        ])
        ->values();

    // Cùng nguồn với dropdown ba gạch trên thanh đầu trang, để hai chỗ không bao
    // giờ bày hai danh sách khác nhau. Trả về ['ds' => [...], 'dangChon' => int].
    $chiNhanh = \App\Services\ChiNhanhDangLam::danhSach();

    $chonNhieu = fn (string $khoa) => array_filter(explode(',', (string) $filters[$khoa]));
    $loaiChon = $chonNhieu('type');
    $phanLoaiChon = $chonNhieu('category_id');
    $nguonChon = $chonNhieu('source');
    $nguoiTaoChon = $chonNhieu('created_by');
@endphp

@push('styles')
    <style>
        /* ---------- BẢNG: KHÔNG XÉN CHỮ, KHÔNG BẺ ĐÔI TIÊU ĐỀ ----------

           Hai luật của bản v2 cũ, và cả hai đều đáng giữ:

           1. TIÊU ĐỀ LUÔN MỘT DÒNG. "Phương thức thanh toán" bẻ làm đôi thì hàng
              tiêu đề cao gấp rưỡi và mắt phải dừng lại đọc từng cột.
           2. Ô DỮ LIỆU dài thì XUỐNG DÒNG, không xén bằng "…". Tên chi nhánh hay
              một dòng mô tả bị cắt là người đọc phải rê chuột lên mới biết đủ.

           Hai luật ấy đòi bảng đủ rộng cho tiêu đề dài nhất, nên bảng có
           `min-width`: màn rộng thì vừa khít, màn hẹp thì `.table-responsive`
           cho cuộn ngang — đúng cách v2 làm, thay vì bóp chữ lại.

           Mọi cột vẫn đặt %, tổng đúng 100 — bỏ trống một cột là bảng hở khoảng
           chết. Con số chia theo BỀ RỘNG TIÊU ĐỀ: ô nào tiêu đề dài thì phần
           trăm lớn, không thì nó là cột đầu tiên bị bẻ dòng. */
        table.table-thu-chi.none_mobile {
            width: 100%;
            /* 1060 = tổng bề rộng nhỏ nhất để cả 13 tiêu đề nằm gọn một dòng, đo
               thật theo hàng tiêu đề chung của v2 (13px — xem v2::layouts.master)
               và ba nhãn đã rút gọn. Số cũ là 1360, tính theo cỡ chữ 14px và nhãn
               dài, nên ở màn 1536 bảng trượt ngang và cột Hành động rơi ra ngoài.
               Đừng nâng quá 1060 mà không đo lại: khung của màn này chỉ 1140px. */
            min-width: 1060px;
            table-layout: fixed;
        }
        table.table-thu-chi.none_mobile th { white-space: nowrap; }
        /* Đệm ngang 4px: 13 cột × 8px thừa ra là mất gần hai cột tiền. */
        table.table-thu-chi.none_mobile th,
        table.table-thu-chi.none_mobile td { padding-left: 4px; padding-right: 4px; }
        /* MỌI ô một dòng, dài quá thì cắt bằng "…". Trước đây ô được xuống dòng
           cho khỏi mất chữ, nhưng 13 cột trong khung 1177px thì cột nào cũng hẹp:
           "Quản trị viên" gãy làm hai, còn mã phiếu `nowrap` mà không cắt thì
           tràn đè sang cột bên cạnh. Chữ đầy đủ không mất — ô nào cắt được cũng
           có `title` để rê chuột, và hộp Xem chi tiết in trọn vẹn. */
        table.table-thu-chi.none_mobile td {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* Chia phần trăm theo BỀ RỘNG THẬT của thứ nằm trong cột, không chia đều:
           - Mã phiếu và Mô tả rộng ra, vì phiếu TỰ SINH mang mã dài
             (PC-PMH202609030003-3) và ghi chú dài ("Trả tiền phiếu mua PMH…") —
             để hẹp thì mỗi dòng bảng cao gấp ba.
           - Người tạo / Đính kèm thu lại, vì chúng chỉ vừa đủ chứa chính TIÊU ĐỀ
             của mình, không có nội dung nào dài hơn.
           - Hành động nay chứa BA nút (xem / sửa / xoá), mà vỏ v2 cho `i.fa` cỡ
             22px — ba cái là 66px chưa kể khoảng cách. 5% cũ chỉ được 68px nên
             nút thứ ba rớt ra ngoài ô. Lấy 1% của Mô tả và 1% của Chi nhánh:
             Mô tả đằng nào cũng cắt bằng "…", còn tên chi nhánh được xuống dòng.
           Tổng vẫn đúng 100. */
        table.table-thu-chi.none_mobile th:first-child { width: 3.23%; }
        table.table-thu-chi.none_mobile th.show_code { width: 10.45%; }
        table.table-thu-chi.none_mobile th.show_type { width: 7.22%; }
        table.table-thu-chi.none_mobile th.show_amount { width: 8.33%; }
        table.table-thu-chi.none_mobile th.show_branch { width: 8.5%; }
        table.table-thu-chi.none_mobile th.show_payer { width: 13.15%; }
        table.table-thu-chi.none_mobile th.show_creator { width: 8.16%; }
        table.table-thu-chi.none_mobile th.show_category { width: 6.29%; }
        table.table-thu-chi.none_mobile th.show_payment_method { width: 9.18%; }
        table.table-thu-chi.none_mobile th.show_attachment { width: 6.03%; }
        table.table-thu-chi.none_mobile th.show_created_at { width: 8.5%; }
        table.table-thu-chi.none_mobile th.show_note { width: 4.08%; }
        table.table-thu-chi.none_mobile th:last-child { width: 6.88%; }

        /* ---------- KHUNG LỌC PHẢI VỪA MỘT MÀN HÌNH ----------
           Bảy ô lọc xếp dọc, mỗi ô ăn ba thứ: nhãn, ô nhập, rồi khoảng cách tới
           ô dưới. Để mặc định 16px thì riêng khoảng cách đã là 112px — bằng gần
           hai ô lọc, chỉ để cách nhau — và cả cột tràn khỏi màn hình laptop.

           Nhưng nén sát quá thì các ô dính vào nhau, đọc mệt hơn hẳn. Con số
           dưới đây là mức Ở GIỮA: bớt được chỗ thừa mà mỗi ô vẫn có chỗ thở.

           Nén bằng CSS chứ không bỏ bớt nhãn: nhãn là thứ trình đọc màn hình dựa
           vào, mà `placeholder` không thay được nó.

           Chỉ từ 992px trở lên — dưới mức đó vỏ v2 giấu hẳn cột này
           (responsive-manager.css) và bưng từng ô sang tấm offcanvas, nơi rộng
           rãi nên không cần nén. */
        @media (min-width: 992px) {
            .index-income-expense-page .fillter-box .card-body { padding-top: 12px; padding-bottom: 12px; }
            .index-income-expense-page .fillter-box .card-body > div[id^="filter"] { margin-bottom: 13px !important; }
            .index-income-expense-page .fillter-box .title_search { display: block; margin-bottom: 5px; }
            .index-income-expense-page .fillter-box .mt-1 { margin-top: 0 !important; }
            /* Sáu mốc thời gian: bỏ chiều cao tối thiểu của Bootstrap (nó chừa
               chỗ cho cỡ chữ lớn hơn cỡ đang dùng), nhưng vẫn để mỗi hàng cách
               nhau một nhịp — ba hàng tick dính liền là đọc nhầm cột. */
            .index-income-expense-page .fillter-box .form-check { min-height: 0; margin-bottom: 4px; }
            .index-income-expense-page .fillter-box .form-check-label { line-height: 1.5; }

            /* LƯỚI AN TOÀN, không phải cách chữa chính.
               Với mức giãn trên, cột vừa màn hình laptop thường nên KHÔNG có
               thanh cuộn nào hiện ra. Màn 13" để mức phóng 125% thì vẫn hụt —
               lúc ấy ghim cột lại và cho cuộn bên trong còn hơn để nó tràn xuống
               dưới rồi phải cuộn cả trang mới với tới.

               MỐC không phải cửa sổ: <body> của vỏ v2 là
               `height: 100vh; overflow-y: hidden`, phần tử cuộn thật là
               `.inner-wrapper-container-fluid` với `height: calc(100vh - 172px)`
               (style.css). Lấy 100vh trần thì cột dài quá khung và đáy vẫn bị
               cắt — đúng thứ đang phải chữa. */
            .index-income-expense-page .fillter-box {
                position: sticky;
                top: 4px;
                max-height: calc(100vh - 184px);
                overflow-y: auto;
                overflow-x: hidden;
                scrollbar-width: thin;
            }
            .index-income-expense-page .fillter-box::-webkit-scrollbar { width: 6px; }
            .index-income-expense-page .fillter-box::-webkit-scrollbar-thumb {
                background: #cbd5e1;
                border-radius: 3px;
            }
        }

        /* ---------- KHỐI DANH SÁCH THỤT VÀO BẰNG TIÊU ĐỀ ----------
           `div.content_midd` là khung trắng có viền, và vỏ v2 chỉ cho THANH TIÊU
           ĐỀ `padding: 10px` — khối danh sách bên dưới thì không có gì. Kết quả:
           tiêu đề thụt vào 10px còn bốn ô quỹ với cái bảng lại dính sát viền
           khung, nhìn như hai khối rơi ra ngoài thẻ.

           Cho khối danh sách cùng mức thụt ấy để mọi thứ thẳng một đường với
           tiêu đề. Bảng cũng thụt theo — đó là chủ ý, một cái bảng có viền mà
           mép trùng đúng viền khung thì hai đường kẻ chồng lên nhau. */
        .index-income-expense-page .content_midd > .list { padding: 0 10px 10px; }

        /* ---------- BỐN Ô QUỸ ----------
           Thẻ trắng viền mảnh, nhãn nhỏ in hoa, số to đậm — đúng dáng v2.

           KHÔNG dùng lưới `.row` > `.col`: `.row` của Bootstrap có LỀ ÂM 12px mỗi
           bên để triệt tiêu máng của cột cha. Dải này lại nằm thẳng trong
           `.content_midd` (không phải trong một cột), nên lề âm ấy không triệt
           tiêu cái gì cả — nó kéo bốn ô rộng hơn bảng bên dưới đúng 24px và ép
           sát hai mép, trong khi bảng thì thụt vào.

           Flex + `gap` thì bề rộng khớp bảng, khoảng cách giữa các ô đều nhau, và
           không có lề âm nào để triệt tiêu nhầm.

           `flex: 1 1 160px`: bốn ô chia đều bề ngang trên máy tính, và tự xuống
           hàng thành 2×2 khi cột hẹp lại thay vì bóp chữ. */
        .thu-chi-quy {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 12px;
        }
        .thu-chi-quy .o-quy {
            /* `flex: 1 1 0` — BỐN PHẦN BẰNG NHAU, đúng như `col-md-3` của v2.
               Cơ sở 0 nghĩa là bề rộng chia hoàn toàn theo phần chứ không cộng
               thêm chỗ cho nội dung, nên ô có số dài và ô ghi "0" vẫn rộng y
               nhau. Để cơ sở 160px thì bốn ô lệch theo độ dài con số bên trong.

               KHÔNG đặt `max-width`: chặn trên thì bốn ô dừng lại giữa chừng và
               thừa một mảng trống ở mép phải — nhìn còn lệch hơn.

               `min-width` + `flex-wrap` lo phần màn hẹp: co tới 150px thì xuống
               hàng thành 2×2 thay vì bóp chữ. */
            flex: 1 1 0;
            min-width: 150px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            border: 1px solid #e9e9e9;
            border-radius: 4px;
            padding: 10px 14px;
            background-color: #fff;
        }
        .thu-chi-quy .nhan {
            font-size: 12px;
            /* Nhãn dài như "TỒN QUỸ ĐẦU KỲ" xuống dòng thì ô ấy cao hơn ba ô kia.
               Cho phép cắt bằng "…" ở đây là đúng chỗ: chữ đã in hoa, ngắn, và
               người đọc nhìn con số bên dưới là chính. */
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            text-transform: uppercase;
            letter-spacing: .2px;
            color: #6b7280;
            font-weight: 500;
            margin-bottom: 6px;
        }
        .thu-chi-quy .so {
            font-size: 18px;
            font-weight: 700;
            margin: 0;
            /* Chữ số đều bề ngang: bốn con số xếp thẳng cột thay vì so le. */
            font-variant-numeric: tabular-nums;
        }

        /* Vỏ v2 chỉ đặt `div.btn_top_content { display: flex }`, không có gap —
           thiếu dòng này là ba nút trên đầu bảng dính liền nhau thành một khối. */
        .btn_top_content { gap: 8px; align-items: center; flex-wrap: wrap; }
        .btn_top_content > * { margin: 0; }

        /* BA NÚT CAO BẰNG NHAU.
           Vỏ v2 cho `.bt` (Lập phiếu) đúng 32px, nhưng nút Xuất Excel ăn
           `.btn { padding: 5px 10px !important }` và nút chọn cột ăn thêm
           `.setting-col { border: 2px }` — mỗi nút ra một chiều cao khác nhau,
           đứng cạnh nhau là thấy so le ngay. Ghim cả ba về 32px. */
        .btn_top_content .btn-export,
        .btn_top_content .setting-col {
            height: 32px;
            padding: 0 12px !important;
            border-radius: 6px;
            cursor: pointer;
        }

        /* Nút chọn cột chỉ có mỗi cái icon — để ô vuông cho cân với hai nút chữ.
           `.dropup-content` neo vào `.dropup` (thẻ position:relative bọc ngoài)
           chứ không vào nút, nên bóp nút lại không kéo menu đi đâu cả. */
        .btn_top_content .setting-col {
            width: 32px;
            padding: 0 !important;
            justify-content: center;
        }

        /* ===================== HỘP LẬP PHIẾU =====================

           1. BỀ RỘNG CÓ CHẶN TRÊN. v2 chỉ đặt `min-width: 60%`, mà min-width thì
              THẮNG max-width của Bootstrap — màn hình càng rộng hộp càng phình,
              tới mức hai cột cách nhau cả gang tay còn ô nhập thì lọt thỏm.
           2. HOẠT ẢNH. style.css của vỏ v2 cho `.modal-content` chạy `animatetop`
              (top -300px→0, opacity 0→1) trong khi Bootstrap 5 đang tự trượt
              `.modal-dialog`. Hai hoạt ảnh chồng nhau nên lúc mở hộp nhảy giật
              một nhịp và mọi thứ nhoè đi — viền ô nhập 1px biến mất hẳn, nhìn
              như hộp rỗng không có ô nào. Tắt ở đây, để Bootstrap lo một mình. */
        #addIncomeExpense .modal-dialog,
        #addPayer .modal-dialog {
            min-width: 0;
            width: 94%;
            margin-left: auto;
            margin-right: auto;
        }
        #addIncomeExpense .modal-dialog { max-width: 900px; }
        #addPayer .modal-dialog { max-width: 460px; }

        #addIncomeExpense .modal-content,
        #addPayer .modal-content,
        #detailIncomeExpense .modal-content,
        #deleteItem .modal-content { animation: none !important; }

        /* Khoảng thở của v2 cho ruột hộp thoại. */
        #addIncomeExpense .modal-body { padding: 17px !important; }

        /* ---------- CON MẮT XEM CHI TIẾT ----------
           style.css của vỏ v2 phát màu và con trỏ theo TÊN GLYPH: fa-edit xanh,
           fa-times đỏ, fa-copy xanh nhạt… nhưng không có dòng nào cho fa-eye. Bỏ
           trắng thì icon ăn màu chữ thường và con trỏ vẫn là mũi tên — nhìn y hệt
           một ký tự chết, không ai đoán ra là bấm được.

           Xám trung tính, KHÔNG lấy màu xanh của Sửa: ba nút đứng cạnh nhau mà hai
           cái cùng màu là mắt phải đọc hình icon mới phân biệt được. Xem cũng là
           việc vô hại nhất trong ba, không cần đòi chú ý bằng màu. */
        td.action .detail-item i { color: #6c757d; cursor: pointer; }

        /* ---------- HỘP XEM CHI TIẾT ----------
           Dựng theo đúng dáng của v2 cũ: một KHUNG có viền, trên đầu là dải tiêu
           đề "Chi tiết", bên trong là các dòng nhãn — giá trị kẻ ngang, tô xen kẽ.

           Nhãn và giá trị nằm ở HAI CỘT chứ không phải nhãn trái / giá trị dạt
           phải như trước: mười một dòng mà giá trị mỗi dòng bắt đầu ở một chỗ
           khác nhau thì mắt phải quét ngang từng dòng một. Cùng một mốc dọc thì
           đọc lướt xuống là xong.

           Dùng lớp chứ không dùng #id: đúng khối HTML này còn được nhét vào tấm
           trượt của điện thoại, neo theo id là phải viết luật hai lần. */
        .hop-chi-tiet {
            border: 1px solid #dde0e6;
            border-radius: 3px;
            overflow: hidden;
            font-size: 14px;
        }
        .hop-chi-tiet .dai-tieu-de {
            padding: 11px 16px;
            background: #d9dfec;
            color: #1f2d47;
            font-weight: 700;
        }
        .hop-chi-tiet .dong {
            display: flex;
            gap: 12px;
            padding: 11px 16px;
            border-top: 1px solid #e9ecef;
        }
        /* Dải tiêu đề là con thứ nhất, nên dòng dữ liệu đầu tiên là con CHẴN —
           khớp với v2: dòng Mã phiếu có nền, dòng dưới nó trắng. */
        .hop-chi-tiet > .dong:nth-child(even) { background: #f8f9fa; }
        .hop-chi-tiet .nhan {
            width: 36%;
            flex-shrink: 0;
            color: #3c4257;
        }
        .hop-chi-tiet .gia {
            flex: 1;
            min-width: 0;
            color: #1f2d47;
            /* Ghi chú dài xuống dòng thoải mái — hộp này là nơi in ĐỦ chữ, khác
               hẳn bảng danh sách vốn cắt bằng "…". */
            word-break: break-word;
        }
        /* Dòng đầu bê nguyên lối của v2: mã phiếu bên trái, mã chứng từ gốc dạt
           hẳn sang phải. Hai mã là hai thứ ngang hàng nhau, không cái nào là
           "nhãn" của cái nào. */
        .hop-chi-tiet .dong-ma { justify-content: space-between; }
        .hop-chi-tiet .dong-ma .nhan { width: auto; }
        .hop-chi-tiet .ma-nguon { color: #1a73e8; font-weight: 700; }
        @media (max-width: 575.98px) {
            /* Trên tấm trượt của điện thoại, nhãn cần nhiều chỗ hơn giá trị:
               "Phương thức thanh toán" dài gấp ba lần "Tiền mặt". */
            .hop-chi-tiet .nhan { width: 46%; }
            .hop-chi-tiet .dong,
            .hop-chi-tiet .dai-tieu-de { padding-left: 12px; padding-right: 12px; }
        }

        /* ---------- DÒNG "VÌ SAO KHOÁ" TRONG HỘP CHI TIẾT ----------
           Vỏ v2 khai `i.fa { font-size: 22px }` cho MỌI icon, nên cái ổ khoá ở
           đây to bằng ba dòng chữ bên cạnh. Kéo về đúng cỡ chữ và cho nó nằm
           thẳng hàng với dòng chữ thay vì đội lên trên. */
        #detailIncomeExpense .ly-do-khoa,
        #offcanvasDetail .ly-do-khoa {
            display: flex;
            align-items: center;
            gap: 8px;
            margin: 12px 0 0;
            padding: 8px 10px;
            border-radius: 4px;
            background: #f6f7f9;
            color: #6b7280;
            font-size: 13px;
            line-height: 1.4;
        }
        #detailIncomeExpense .ly-do-khoa i.fa,
        #offcanvasDetail .ly-do-khoa i.fa {
            font-size: 13px;
            color: #9aa3af;
            flex-shrink: 0;
        }

        /* Nút thêm nhanh người nộp đứng sát ô chọn, vuông bằng chiều cao ô. */
        #addIncomeExpense .div-add-payer .bt { width: 34px; padding: 0; }
        #addIncomeExpense .box-textarea-cus { position: relative; }
        #addIncomeExpense .char-counter { position: absolute; right: 8px; bottom: 4px; font-size: 11px; color: #999; }
        /* Ô chọn tệp của v2 cao 30px, thấp hơn các ô còn lại — kéo cho bằng. */
        #addIncomeExpense .custom-file-wrapper { height: 34px; max-width: none; }

        /* ---------- Ô thả xuống trong hộp thoại ----------
           Vỏ v2 chỉ kéo select2 về đúng dáng `.form-control` cho ô TRONG KHUNG LỌC
           (`.fillter-box .select2-…` ở layouts/master). Ô trong hộp thoại không ăn
           luật ấy nên cao 28px, đứng cạnh ô nhập 34px là thấy hụt một khấc.
           Kê lại đúng bằng con số của khung lọc. */
        #addIncomeExpense .select2-container { width: 100% !important; }
        #addIncomeExpense .select2-container--default .select2-selection--single {
            height: 34px;
            border: 1px solid #ced4da;
            border-radius: 6px;
        }
        #addIncomeExpense .select2-container--default .select2-selection--single .select2-selection__rendered {
            line-height: 32px;
            font-size: 13px;
            color: #212529;
            padding-left: 10px;
        }
        #addIncomeExpense .select2-container--default .select2-selection--single .select2-selection__arrow { height: 32px; }
        #addIncomeExpense .select2-container--default.select2-container--focus .select2-selection--single {
            border-color: #86b7fe;
        }
        /* Bảng thả xuống phải nổi TRÊN lớp phủ của modal (Bootstrap dùng 1055). */
        #addIncomeExpense .select2-container--open,
        #addIncomeExpense .select2-dropdown { z-index: 1065 !important; }

        @media (max-width: 991px) { .dropup .dropbtn { display: none !important; } }
    </style>
@endpush

@section('content')
    {{-- Nút mở từng khối lọc trên điện thoại. Bảy khối, đúng bảy ô của khung trái. --}}
    <div class="call-to-action-container">
        <div class="wrapper-call-to-action">
            @include('v2::partials.filter-button-mobile', [
                'dataBsTarget' => 'offcanvasBottomInMobile',
                'dataOffcanvasTarget' => 'filterReceiptCode',
                'modalLabel' => __('message.receipt-code'),
            ])
            @if (count($chiNhanh['ds']) > 1)
                @include('v2::partials.filter-button-mobile', [
                    'dataBsTarget' => 'offcanvasBottomInMobile',
                    'dataOffcanvasTarget' => 'filterBranch',
                    'modalLabel' => __('message.branch'),
                ])
            @endif
            @include('v2::partials.filter-button-mobile', [
                'dataBsTarget' => 'offcanvasBottomInMobile',
                'dataOffcanvasTarget' => 'filterTime',
                'modalLabel' => __('message.time'),
            ])
            @include('v2::partials.filter-button-mobile', [
                'dataBsTarget' => 'offcanvasBottomInMobile',
                'dataOffcanvasTarget' => 'filterCreator',
                'modalLabel' => __('message.creator'),
            ])
            @include('v2::partials.filter-button-mobile', [
                'dataBsTarget' => 'offcanvasBottomInMobile',
                'dataOffcanvasTarget' => 'filterType',
                'modalLabel' => __('message.type_of_income_expense'),
            ])
            @include('v2::partials.filter-button-mobile', [
                'dataBsTarget' => 'offcanvasBottomInMobile',
                'dataOffcanvasTarget' => 'filterCategory',
                'modalLabel' => __('message.income_expense_category'),
            ])
            @include('v2::partials.filter-button-mobile', [
                'dataBsTarget' => 'offcanvasBottomInMobile',
                'dataOffcanvasTarget' => 'filterSource',
                'modalLabel' => __('message.type'),
            ])
        </div>
    </div>

    <div class="row index-income-expense-page">
        <div class="col-12 col-lg-2_5 col-xl-2 fillter-box-container pe-lg-0">
            <div class="fillter-box">
                <div class="card">
                    <div class="card-header card-header-primary header_search">
                        {{ __('message.filter') }}
                    </div>
                    <div class="card-body px-2">
                        {{-- Không bọc <form>: JS tự dựng URL rồi gọi V2.napLai. Trên điện
                             thoại vỏ v2 BƯNG từng khối lọc sang tấm offcanvas, mỗi lượt
                             một khối — submit lúc đó sẽ đánh rơi các ô còn lại. --}}
                        <div id="filterReceiptCode" class="mb-3">
                            <div class="inner-modal-in-mobile">
                                <span class="title_search d-none d-lg-block">{{ __('message.receipt-code') }}</span>
                                <input type="text" name="keyword" value="{{ $filters['keyword'] }}"
                                    class="form-control mt-1" id="tc-keyword" autocomplete="off"
                                    placeholder="{{ __('message.receipt-code') }}">
                            </div>
                        </div>

                        {{-- CHI NHÁNH.
                             Không phải một bộ lọc riêng: đây chính là chi nhánh đang làm
                             việc của TAB này, cùng thứ mà dropdown ba gạch trên thanh đầu
                             trang đổi. Dựng thêm tham số `branch_id` riêng là hai chỗ cùng
                             nói một chuyện rồi cãi nhau — mọi lượt gọi API đều lấy chi
                             nhánh từ `chi_nhanh` (xem middleware ChiNhanhTheoTab).

                             `V2.doiChiNhanhTab` lo cả sessionStorage của tab lẫn việc nạp
                             lại trang, y hệt lúc bấm trên thanh đầu trang.

                             CỬA HÀNG MỘT CHI NHÁNH THÌ KHÔNG BÀY: ô chỉ có đúng một
                             lựa chọn không lọc được gì, mà vẫn ăn nhãn + ô chọn +
                             khoảng cách của cả cột — chỗ ấy để dành cho sáu ô lọc thật
                             sự dùng tới. Mở thêm chi nhánh thứ hai là nó tự hiện. --}}
                        @if (count($chiNhanh['ds']) > 1)
                        <div id="filterBranch" class="mb-3">
                            <div class="inner-modal-in-mobile">
                                <span class="title_search d-none d-lg-block">{{ __('message.branch') }}</span>
                                <select class="form-control form-select mt-1" id="tc-branch">
                                    @foreach ($chiNhanh['ds'] as $cn)
                                        <option value="{{ $cn['id'] }}"
                                            {{ (int) $chiNhanh['dangChon'] === (int) $cn['id'] ? 'selected' : '' }}>
                                            {{ $cn['name'] }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        @endif

                        <div id="filterTime" class="mb-3">
                            <div class="inner-modal-in-mobile">
                                <span class="title_search d-none d-lg-block">{{ __('message.time') }}</span>
                                {{-- Sáu mốc nhanh của v2. Bấm mốc nào thì điền luôn hai ô ngày
                                     bên dưới rồi lọc — hai ô vẫn là nguồn sự thật. --}}
                                <div class="d-flex flex-wrap">
                                    @foreach ([
                                        'today' => __('message.today'),
                                        'yesterday' => __('message.yesterday'),
                                        'thisWeek' => __('message.this-week'),
                                        'lastWeek' => __('message.last-week'),
                                        'thisMonth' => __('message.this-month'),
                                        'lastMonth' => __('message.last-month'),
                                    ] as $ma => $ten)
                                        <div class="col-6">
                                            <div class="form-check gap-0">
                                                <input class="me-1 form-check-input tc-moc-thoi-gian" type="radio"
                                                    name="tc_moc" value="{{ $ma }}" id="tc_moc_{{ $ma }}">
                                                <label class="form-check-label" for="tc_moc_{{ $ma }}">{{ $ten }}</label>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                                <div class="d-flex flex-lg-column gap-2 gap-lg-1 mt-1">
                                    <input type="text" name="from_date" autocomplete="off"
                                        value="{{ $ngayVN($filters['from_date']) }}" class="form-control"
                                        id="tc-from-date" placeholder="{{ __('message.from_date') }}">
                                    <input type="text" name="to_date" autocomplete="off"
                                        value="{{ $ngayVN($filters['to_date']) }}" class="form-control"
                                        id="tc-to-date" placeholder="{{ __('message.to_date') }}">
                                </div>
                            </div>
                        </div>

                        <div id="filterCreator" class="mb-3">
                            <div class="inner-modal-in-mobile">
                                <span class="title_search d-none d-lg-block">{{ __('message.creator') }}</span>
                                <select class="form-control form-select mt-1" id="tc-created-by"
                                    name="created_by" multiple>
                                    @foreach ($nhanVien as $nv)
                                        <option value="{{ $nv['id'] }}"
                                            {{ in_array((string) $nv['id'], $nguoiTaoChon, true) ? 'selected' : '' }}>
                                            {{ $nv['full_name'] ?? ($nv['name'] ?? '') }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <div id="filterType" class="mb-3">
                            <div class="inner-modal-in-mobile">
                                <span class="title_search d-none d-lg-block">{{ __('message.type_of_income_expense') }}</span>
                                <select class="form-control form-select mt-1" id="tc-type-filter" name="type" multiple>
                                    @foreach ($C::LOAI as $ma => $ten)
                                        <option value="{{ $ma }}"
                                            {{ in_array((string) $ma, $loaiChon, true) ? 'selected' : '' }}>{{ $ten }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <div id="filterCategory" class="mb-3">
                            <div class="inner-modal-in-mobile">
                                <span class="title_search d-none d-lg-block">{{ __('message.income_expense_category') }}</span>
                                <select class="form-control form-select mt-1" id="tc-category"
                                    name="category_id" multiple>
                                    @foreach ($phanLoai as $pl)
                                        <option value="{{ $pl['id'] }}" data-type="{{ $pl['type'] ?? '' }}"
                                            {{ in_array((string) $pl['id'], $phanLoaiChon, true) ? 'selected' : '' }}>
                                            {{ $pl['name'] ?? '' }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <div id="filterSource" class="mb-3">
                            <div class="inner-modal-in-mobile">
                                <span class="title_search d-none d-lg-block">{{ __('message.type') }}</span>
                                <select class="form-control form-select mt-1" id="tc-source"
                                    name="source" multiple>
                                    @foreach ($C::NGUON_LOC as $ma => $ten)
                                        <option value="{{ $ma }}"
                                            {{ in_array($ma, $nguonChon, true) ? 'selected' : '' }}>{{ $ten }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        {{-- KHÔNG có ô "Phương thức thanh toán": khung lọc của v2 không có ô
                             ấy (controller v2 có đọc `payment_method` nhưng chẳng ô nào gửi
                             lên — một nhánh chết). --}}
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-9_5 col-xl-10 wrapper-content-dashboard-middle mt-md-2 mt-lg-0">
            <div class="content_midd">
                <div class="content_midd_title">
                    <h1 class="tieu-de-trang">{{ __('message.income_expense_management') }}</h1>

                    <div class="justify-content-end">
                        <div class="btn_top_content d-flex align-items-center">
                            <a type="button" class="bt btn_green add-item">Lập phiếu</a>

                            {{-- Xuất Excel đứng thẳng ra hàng nút như v2, không gói vào
                                 "Nâng cao": màn này chỉ có đúng một nút phụ, mà bọc một nút
                                 vào một menu thả xuống là bắt bấm hai lần cho một việc. --}}
                            <a class="btn btn-sm d-flex align-items-center btn-export"
                                href="{{ route('admin.thu-chi.export', request()->query()) }}">
                                <i class="fa-solid fa-file-export my-auto mx-1"></i> {{ __('message.export_report') }}
                            </a>

                            {{-- Chọn cột: bỏ tick là thêm cột vào ?hide=, tải lại giữ nguyên lựa chọn. --}}
                            <div class="dropup">
                                <button type="button" class="btn active dropbtn setting-col" href="#">
                                    <i class="fa fa-sliders" aria-hidden="true"></i>
                                    <div class="dropup-content">
                                        <div class="list_filter">
                                            <div class="form-check">
                                                <input class="form-check-input" data-col="show_all" type="checkbox"
                                                    id="show_all" {{ count($cotTat) ? '' : 'checked' }}>
                                                <label for="show_all">{{ __('message.all') }}</label>
                                            </div>
                                            @foreach ($C::COT_BANG as $cot => $chu)
                                                <div class="form-check">
                                                    <input class="form-check-input show_col" data-col="show_{{ $cot }}"
                                                        type="checkbox" id="show_{{ $cot }}"
                                                        {{ $columns['show_'.$cot] ? 'checked' : '' }}>
                                                    <label for="show_{{ $cot }}">{{ __('message.'.$chu) }}</label>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Bốn ô quỹ nằm TRONG .list: vỏ v2 nạp lại bằng cách thay ruột .list,
                     để ngoài là lọc xong số cũ vẫn nằm nguyên trên đầu bảng. --}}
                <div class="list scrollDiv">
                    <div class="thu-chi-quy">
                        @foreach ([
                            ['nhan' => __('message.starting_fund_balance'), 'so' => $summary['begin_balance'], 'mau' => 'text-primary'],
                            ['nhan' => __('message.total_income'), 'so' => $summary['total_income'], 'mau' => 'text-primary'],
                            ['nhan' => __('message.total_expense'), 'so' => $summary['total_expense'], 'mau' => 'text-danger'],
                            ['nhan' => __('message.ending_fund_balance'), 'so' => $summary['end_balance'], 'mau' => 'text-danger'],
                        ] as $o)
                            <div class="o-quy">
                                <span class="nhan">{{ $o['nhan'] }}</span>
                                <p class="so {{ $o['mau'] }}">{{ $tienGon($o['so']) }}</p>
                            </div>
                        @endforeach
                    </div>

                    <div class="table-responsive table-border-style">
                        <table class="table-thu-chi none_mobile">
                            <tr>
                                <th class="text-center">{{ __('message.stt') }}</th>
                                <th class="text-left show_code {{ $columns['show_code'] ? '' : 'hide' }}">{{ __('message.receipt-code') }}</th>
                                <th class="text-left show_type {{ $columns['show_type'] ? '' : 'hide' }}">{{ __('message.type_of_income_expense') }}</th>
                                <th class="text-right show_amount {{ $columns['show_amount'] ? '' : 'hide' }}">{{ __('message.amount') }}</th>
                                <th class="text-left show_branch {{ $columns['show_branch'] ? '' : 'hide' }}">{{ __('message.branch-name') }}</th>
                                <th class="text-left show_payer {{ $columns['show_payer'] ? '' : 'hide' }}">{{ __('message.payer') }}/{{ __('message.payee') }}</th>
                                <th class="text-left show_creator {{ $columns['show_creator'] ? '' : 'hide' }}">{{ __('message.creator') }}</th>
                                <th class="text-left show_category {{ $columns['show_category'] ? '' : 'hide' }}">{{ __('message.income_expense_category-short') }}</th>
                                <th class="text-left show_payment_method {{ $columns['show_payment_method'] ? '' : 'hide' }}">{{ __('message.payment-method-short') }}</th>
                                <th class="text-center show_attachment {{ $columns['show_attachment'] ? '' : 'hide' }}">{{ __('message.attachment') }}</th>
                                <th class="text-center show_created_at {{ $columns['show_created_at'] ? '' : 'hide' }}">{{ __('message.recorded-time-short') }}</th>
                                <th class="text-left show_note {{ $columns['show_note'] ? '' : 'hide' }}">{{ __('message.description') }}</th>
                                <th class="text-center not-export">{{ __('message.action') }}</th>
                            </tr>

                            @forelse ($list as $i => $item)
                                @php
                                    $id = (int) ($item['id'] ?? 0);
                                    $loai = (int) ($item['type'] ?? 0);
                                    $nguon = (string) ($item['source'] ?? 'manual');
                                    // Ba lớp khoá của v2 do API chốt: phiếu của người khác, phiếu
                                    // tự phát sinh từ đơn, phiếu thuộc ca đã đóng. Trang chỉ nghe
                                    // theo cờ `locked` — đoán lại ở đây là hai nơi nói hai kiểu.
                                    $khoa = (bool) ($item['locked'] ?? false) || $nguon !== 'manual';
                                    $lyDo = (string) ($item['locked_reason'] ?? ($nguon !== 'manual' ? 'Phiếu tự phát sinh từ chứng từ khác, không sửa/xoá được.' : ''));
                                @endphp
                                <tr class="item" data-id="{{ $id }}"
                                    data-code="{{ $item['code'] ?? '' }}"
                                    data-type="{{ $loai }}"
                                    data-type-name="{{ $C::LOAI[$loai] ?? '' }}"
                                    data-amount="{{ (float) ($item['amount'] ?? 0) }}"
                                    data-amount-text="{{ $tien($item['amount'] ?? 0) }}"
                                    data-branch="{{ $item['branch_name'] ?? '' }}"
                                    data-category-id="{{ $item['category_id'] ?? '' }}"
                                    data-category="{{ $item['category_name'] ?? '' }}"
                                    data-payer-type="{{ $item['payer_type'] ?? '' }}"
                                    data-payer-id="{{ $item['payer_id'] ?? '' }}"
                                    data-payer="{{ $item['payer_name'] ?? '' }}"
                                    data-creator="{{ $item['created_by_name'] ?? '' }}"
                                    data-payment-method="{{ $item['payment_method'] ?? '' }}"
                                    data-payment-method-name="{{ $C::PHUONG_THUC[$item['payment_method'] ?? ''] ?? '' }}"
                                    data-attachment="{{ $item['attachment_url'] ?? '' }}"
                                    data-note="{{ $item['note'] ?? '' }}"
                                    data-source="{{ $nguon }}"
                                    data-source-name="{{ $C::NGUON[$nguon] ?? '' }}"
                                    data-source-code="{{ $item['source_code'] ?? '' }}"
                                    data-created-at="{{ $ngayVN($item['created_at'] ?? '') }}"
                                    data-locked="{{ $khoa ? 1 : 0 }}"
                                    data-locked-reason="{{ $lyDo }}">
                                    <td class="text-center">{{ $stt + $i + 1 }}</td>
                                    {{-- Mã phiếu là CHỮ TRẦN, không phải liên kết: cửa xem chi tiết
                                         nay là con mắt ở cột Hành động. Để mã trần thì bôi đen chép lại
                                         được — thứ người dùng làm với một mã nhiều hơn hẳn là bấm vào nó. --}}
                                    <td class="text-left item-code show_code {{ $columns['show_code'] ? '' : 'hide' }}" title="{{ $item['code'] ?? '' }}">{{ $item['code'] ?? '' }}</td>
                                    <td class="text-left show_type {{ $columns['show_type'] ? '' : 'hide' }}">
                                        <b class="{{ $C::CHU_LOAI[$loai] ?? '' }}">{{ $C::LOAI[$loai] ?? '' }}</b>
                                    </td>
                                    <td class="text-right show_amount {{ $columns['show_amount'] ? '' : 'hide' }}">{{ $tien($item['amount'] ?? 0) }}</td>
                                    <td class="text-left show_branch {{ $columns['show_branch'] ? '' : 'hide' }}">{{ $item['branch_name'] ?? '' }}</td>
                                    <td class="text-left show_payer {{ $columns['show_payer'] ? '' : 'hide' }}" title="{{ $item['payer_name'] ?? '' }}">{{ $item['payer_name'] ?? '' }}</td>
                                    <td class="text-left show_creator {{ $columns['show_creator'] ? '' : 'hide' }}" title="{{ $item['created_by_name'] ?? '' }}">{{ $item['created_by_name'] ?? '' }}</td>
                                    <td class="text-left show_category {{ $columns['show_category'] ? '' : 'hide' }}" title="{{ $item['category_name'] ?? '' }}">{{ $item['category_name'] ?? '' }}</td>
                                    <td class="text-left show_payment_method {{ $columns['show_payment_method'] ? '' : 'hide' }}">{{ $C::PHUONG_THUC[$item['payment_method'] ?? ''] ?? '' }}</td>
                                    <td class="text-center action show_attachment {{ $columns['show_attachment'] ? '' : 'hide' }}">
                                        @if (! empty($item['attachment_url']))
                                            <a href="{{ $item['attachment_url'] }}" target="_blank" rel="noopener"
                                                title="{{ __('message.attachment') }}"><i class="fa fa-download"></i></a>
                                        @endif
                                    </td>
                                    <td class="text-center show_created_at {{ $columns['show_created_at'] ? '' : 'hide' }}">{{ $ngayVN($item['created_at'] ?? '') }}</td>
                                    <td class="text-left show_note {{ $columns['show_note'] ? '' : 'hide' }}" title="{{ $item['note'] ?? '' }}">{{ $item['note'] ?? '' }}</td>
                                    <td class="text-center action not-export">
                                        {{-- Con mắt có ở MỌI dòng, kể cả phiếu khoá: xem thì ai cũng
                                             xem được, và chính hộp ấy nói ra vì sao phiếu bị khoá.
                                             Sửa / Xoá thì ngược lại — bày nút rồi báo lỗi lúc bấm là
                                             bẫy người dùng. --}}
                                        <a class="detail-item" type="button" title="{{ __('message.view-detail') }}"><i class="fa fa-eye"></i></a>
                                        @unless ($khoa)
                                            <a class="edit_bt edit-item" type="button" title="{{ __('message.edit') }}"><i class="fa fa-edit"></i></a>
                                            <a class="dele_bt delete-item" type="button" title="{{ __('message.delete') }}"><i class="fa fa-times"></i></a>
                                        @endunless
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="13" class="text-center py-4">
                                        {{ $coLoc ? 'Không có phiếu thu chi nào khớp bộ lọc đang bật.' : $C::EMPTY_TEXT }}
                                    </td>
                                </tr>
                            @endforelse
                        </table>

                        {{-- BẢN THẺ CHO ĐIỆN THOẠI. Dưới 992px v2 giấu hẳn bảng, không có
                             khối này thì màn trống trơn. Cùng class .item và cùng bộ data-*
                             với dòng bảng nên xem/sửa/xoá dùng chung một đoạn JS. --}}
                        <div class="table-thu-chi list none_desktop">
                            <div class="d-flex align-items-center gap-1 p-2 border">
                                <div class="fw-bold" style="flex: 1">{{ __('message.receipt-code') }}</div>
                                <div class="fw-bold">{{ __('message.amount') }}</div>
                            </div>
                            @foreach ($list as $item)
                                @php
                                    $loai = (int) ($item['type'] ?? 0);
                                    $nguon = (string) ($item['source'] ?? 'manual');
                                    $khoa = (bool) ($item['locked'] ?? false) || $nguon !== 'manual';
                                @endphp
                                <div class="item" data-id="{{ (int) ($item['id'] ?? 0) }}"
                                    data-code="{{ $item['code'] ?? '' }}"
                                    data-type="{{ $loai }}"
                                    data-type-name="{{ $C::LOAI[$loai] ?? '' }}"
                                    data-amount="{{ (float) ($item['amount'] ?? 0) }}"
                                    data-amount-text="{{ $tien($item['amount'] ?? 0) }}"
                                    data-branch="{{ $item['branch_name'] ?? '' }}"
                                    data-category-id="{{ $item['category_id'] ?? '' }}"
                                    data-category="{{ $item['category_name'] ?? '' }}"
                                    data-payer-type="{{ $item['payer_type'] ?? '' }}"
                                    data-payer-id="{{ $item['payer_id'] ?? '' }}"
                                    data-payer="{{ $item['payer_name'] ?? '' }}"
                                    data-creator="{{ $item['created_by_name'] ?? '' }}"
                                    data-payment-method="{{ $item['payment_method'] ?? '' }}"
                                    data-payment-method-name="{{ $C::PHUONG_THUC[$item['payment_method'] ?? ''] ?? '' }}"
                                    data-attachment="{{ $item['attachment_url'] ?? '' }}"
                                    data-note="{{ $item['note'] ?? '' }}"
                                    data-source="{{ $nguon }}"
                                    data-source-name="{{ $C::NGUON[$nguon] ?? '' }}"
                                    data-source-code="{{ $item['source_code'] ?? '' }}"
                                    data-created-at="{{ $ngayVN($item['created_at'] ?? '') }}"
                                    data-locked="{{ $khoa ? 1 : 0 }}"
                                    data-locked-reason="{{ $item['locked_reason'] ?? '' }}">
                                    <div class="d-flex flex-column" style="flex: 1">
                                        <span class="fw-semibold">{{ $item['code'] ?? '' }}</span>
                                        <small class="{{ $C::CHU_LOAI[$loai] ?? '' }}">{{ $C::LOAI[$loai] ?? '' }}</small>
                                    </div>
                                    <div class="d-flex justify-content-end text-right gap-2" style="min-width: 110px">
                                        <b>{{ $tien($item['amount'] ?? 0) }}</b>
                                    </div>
                                </div>
                            @endforeach
                            @if (! count($list))
                                <div class="text-center py-4">
                                    {{ $coLoc ? 'Không có phiếu thu chi nào khớp bộ lọc đang bật.' : $C::EMPTY_TEXT }}
                                </div>
                            @endif
                        </div>
                    </div>

                    <div class="form_pagi">
                        @include('v2::partials.pagination', ['meta' => $meta])
                    </div>
                </div>

                <select class="form-control item-per-page select-width {{ count($list) ? '' : 'd-none' }}"
                    data-param="page_size">
                    @foreach ($C::MUC_SO_DONG as $muc)
                        <option value="{{ $muc }}" {{ $filters['page_size'] == $muc ? 'selected' : '' }}>
                            {{ __('message.display', ['name' => $muc]) }}
                        </option>
                    @endforeach
                </select>
            </div>
        </div>
    </div>

    {{-- ===================== Hộp Lập phiếu / Sửa phiếu =====================
         v2 đặt id #addIncomeExpense, hai cột — giữ nguyên bố cục đó. --}}
    <div class="modal" id="addIncomeExpense" data-mode="" style="padding-inline: 0 !important">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable mx-auto" style="min-width: 60%">
            <div class="modal-content">
                <div class="modal-header">
                    <h4 class="modal-title">{{ __('message.add_receipt') }}</h4>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" class="id">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">{{ __('message.receipt-code') }}</label>
                                <input type="text" class="form-control"
                                    placeholder="{{ __('message.auto-increment-code') }}" disabled>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="tc-type">
                                    {{ __('message.type_of_income_expense') }} <span class="required" style="color:red">*</span>
                                </label>
                                <select class="form-control" id="tc-type">
                                    <option value="">{{ __('message.select_income_expense_type') }}</option>
                                    @foreach ($C::LOAI as $ma => $ten)
                                        <option value="{{ $ma }}">{{ $ten }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="tc-category-id">{{ __('message.income_expense_category') }}</label>
                                <select class="form-control" id="tc-category-id">
                                    <option value="">{{ __('message.chose') }}</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="tc-amount">
                                    {{ __('message.amount') }} <span class="required" style="color:red">*</span>
                                </label>
                                <input type="text" class="form-control" id="tc-amount" inputmode="numeric"
                                    autocomplete="off" placeholder="{{ __('message.amount') }}">
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label" for="tc-payer-type">{{ __('message.object_type') }}</label>
                                <select class="form-control" id="tc-payer-type">
                                    <option value="">{{ __('message.chose') }}</option>
                                    @foreach ($C::DOI_TUONG as $ma => $ten)
                                        <option value="{{ $ma }}">{{ $ten }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="tc-payer-id">{{ __('message.payer') }}/{{ __('message.payee') }}</label>
                                <div class="d-flex gap-2">
                                    <select id="tc-payer-id" class="form-control">
                                        <option value="">{{ __('message.chose') }}</option>
                                    </select>
                                    {{-- Chỉ hiện khi loại đối tượng là "Khác": nhân viên và NCC đã có
                                         màn riêng, thêm nhanh ở đây là mở hai đường ghi cho cùng một bảng. --}}
                                    <div class="div-add-payer d-none">
                                        <a type="button" class="bt btn_green add-payer" title="{{ __('message.add-new') }}">
                                            <i class="fa fa-plus" aria-hidden="true"></i>
                                        </a>
                                    </div>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="tc-payment-method">
                                    {{ __('message.payment-method') }} <span class="required" style="color:red">*</span>
                                </label>
                                <select class="form-control" id="tc-payment-method">
                                    @foreach ($C::PHUONG_THUC as $ma => $ten)
                                        <option value="{{ $ma }}">{{ $ten }}</option>
                                    @endforeach
                                </select>
                            </div>
                            {{-- Đính kèm: chép nguyên khuôn ô chọn tệp của v2
                                 (.custom-file-wrapper + #custom-button + #file-name — CSS
                                 đã có sẵn trong custom.css của vỏ). --}}
                            <div class="mb-3">
                                <label class="form-label">{{ __('message.attachment') }}</label>
                                <div class="custom-file-wrapper">
                                    <input type="file" id="tc-attachment" hidden
                                        accept=".jpg,.jpeg,.png,.webp,.gif,.avif,.pdf">
                                    <button type="button" id="custom-button">{{ __('message.choose_file') }}</button>
                                    <span id="file-name">{{ __('message.no_file_chosen') }}</span>
                                    {{-- Đang sửa một phiếu đã có tệp: giữ đường dẫn cũ ở đây để
                                         không chọn tệp mới thì tệp cũ vẫn còn. --}}
                                    <input type="hidden" id="tc-attachment-url">
                                    <a class="me-2 d-none" id="tc-attachment-clear" type="button"
                                        title="{{ __('message.delete') }}"><i class="fa fa-times text-danger"></i></a>
                                </div>
                            </div>

                            {{-- CÒN THIẾU: ô "Tài khoản ngân hàng / thiết bị" của v2.
                                 v2 nạp danh sách từ đường getPaymentMethod, bên này chưa có
                                 API nào trả về tài khoản nhận tiền. Bày ra một ô bắt buộc mà
                                 không bao giờ có gì để chọn thì khoá luôn đường Chuyển khoản,
                                 nên tạm để trống chỗ này.

                                 Khi API có: dựng lại <select id="tc-payment-account">, mở lại
                                 doiPhuongThuc() ở cuối trang và chốt bắt buộc trong
                                 ThuChiController::validated(). --}}
                        </div>

                        <div class="col-md-12">
                            <div class="mb-3">
                                <label class="form-label" for="tc-note">{{ __('message.description') }}</label>
                                <div class="box-textarea-cus">
                                    <textarea class="form-control" rows="3" id="tc-note" style="height: 65px" maxlength="200"></textarea>
                                    <small class="char-counter">0/200</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer justify-content-center">
                    <button type="button" class="bt btn_red" data-bs-dismiss="modal">{{ __('message.close') }}</button>
                    <button type="button" class="bt btn_green save-item">{{ __('message.save') }}</button>
                </div>
            </div>
        </div>
    </div>

    {{-- ===================== Hộp thêm nhanh Người nộp =====================
         v2 bày sáu ô nhưng chỉ gửi bốn, trong đó email còn không có ô nào. Bên
         này bày đúng ba ô thật sự gửi lên. --}}
    <div class="modal" id="addPayer">
        <div class="modal-dialog modal-dialog-centered mx-auto" style="min-width: 30%">
            <div class="modal-content">
                <div class="modal-header">
                    <h4 class="modal-title">{{ __('message.add-new') }} {{ mb_strtolower(__('message.payer')) }}</h4>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="modal_center px-2">
                        <div class="mb-3">
                            <label class="form-label" for="payer-name">
                                {{ __('message.payer_name') }} <span class="required" style="color:red">*</span>
                            </label>
                            <input type="text" class="form-control" id="payer-name" maxlength="255"
                                placeholder="{{ __('message.payer_name') }}">
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="payer-phone">{{ __('message.phone-number') }}</label>
                            <input type="text" class="form-control" id="payer-phone" maxlength="20"
                                inputmode="numeric" placeholder="{{ __('message.phone-number') }}">
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="payer-address">{{ __('message.address') }}</label>
                            <textarea class="form-control" id="payer-address" rows="2" maxlength="255"
                                placeholder="{{ __('message.address') }}"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer justify-content-center">
                    <button type="button" class="bt btn_red" data-bs-dismiss="modal">{{ __('message.close') }}</button>
                    <button type="button" class="bt btn_green save-payer">{{ __('message.save') }}</button>
                </div>
            </div>
        </div>
    </div>

    {{-- ===================== Hộp Xem chi tiết ===================== --}}
    <div class="modal" id="detailIncomeExpense">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h4 class="modal-title">{{ __('message.view-detail') }}</h4>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body"></div>
                <div class="modal-footer justify-content-center">
                    <button type="button" class="bt btn_red" data-bs-dismiss="modal">{{ __('message.close') }}</button>
                </div>
            </div>
        </div>
    </div>

    {{-- ===================== Hộp Xoá ===================== --}}
    <div class="modal" id="deleteItem">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title">{{ __('message.delete') }} ?</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="deleteValue">
                    <div class="modal_center">
                        <div class="row">
                            <div class="col"><label class="form-label">{{ __('message.delete-confirm') }}</label></div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer justify-content-center">
                    <button type="button" class="bt btn_red" data-bs-dismiss="modal">{{ __('message.close') }}</button>
                    <button type="button" class="bt btn_green delete-value">{{ __('message.delete') }}</button>
                </div>
            </div>
        </div>
    </div>

    {{-- ===================== Tấm trượt chi tiết cho điện thoại ===================== --}}
    <div class="offcanvas offcanvas-end offcanvas-custom" tabindex="-1" id="offcanvasDetail">
        <div class="offcanvas-header">
            <a type="button" aria-label="{{ __('message.close') }}" class="btn-back" data-bs-dismiss="offcanvas">
                <i class="fa-solid fa-arrow-left" style="font-size: 20px;"></i>
            </a>
            <div class="d-flex" style="flex: 1;">
                <h5 class="offcanvas-title">{{ __('message.detail') }}</h5>
            </div>
            <div class="d-flex button-header d-none" style="gap: 12px;">
                <a class="edit_bt edit-item-canvas" type="button"><i class="fa fa-edit"></i></a>
                <a class="dele_bt delete-item-canvas" type="button"><i class="fa fa-trash" style="color: red;"></i></a>
            </div>
        </div>
        <div class="offcanvas-body p-0" style="height: calc(100vh - 58px);">
            <div class="modal-view-materials p-3"></div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        const URL_TC = @json(url('/admin/cashbook/entries'));
        const URL_TC_STORE = @json(route('admin.thu-chi.store'));
        const URL_TC_PHAN_LOAI = @json(route('admin.thu-chi.phanLoai'));
        const URL_TC_NGUOI_NOP = @json(route('admin.thu-chi.nguoiNop'));
        const URL_TC_TAO_NGUOI_NOP = @json(route('admin.thu-chi.taoNguoiNop'));
        const URL_TC_DINH_KEM = @json(route('admin.thu-chi.dinhKem'));

        const CHU_CHON = @json(__('message.chose'));
        const O_TRONG = '<option value="">' + CHU_CHON + '</option>';
        // Nhân viên và NCC đã nằm sẵn trong trang; chỉ "Khác" mới phải gọi ngầm.
        const DS_NHAN_VIEN = @json($jsNhanVien);
        const DS_NHA_CUNG_CAP = @json($jsNhaCungCap);
        const VAI_NHAN_VIEN = @json(\App\Http\Controllers\ThuChiController::DOI_TUONG_NHAN_VIEN);

        // ================= Bộ lọc =================
        // Tự dựng URL thay vì submit form, vì hai lẽ:
        //   - trên điện thoại vỏ v2 BƯNG khối lọc sang tấm offcanvas, mỗi lượt
        //     chỉ bưng MỘT khối, nên submit lúc đó đánh rơi các ô còn lại;
        //   - lựa chọn cột nằm ở ?hide=, không phải ô trong form.
        // Ô lọc nằm ở đâu cũng tìm ra: khung trái và tấm offcanvas cùng .fillter-box.
        const oLoc = (ten) => $('.fillter-box [name="' + ten + '"]');

        function locLai() {
            const q = new URLSearchParams();

            const v = String(oLoc('keyword').val() || '').trim();
            if (v) q.set('keyword', v);

            // Ô chọn nhiều: gộp thành chuỗi ngăn bởi dấu phẩy, đúng cái controller đọc.
            ['type', 'created_by', 'category_id', 'source'].forEach(function (ten) {
                const g = oLoc(ten).val() || [];
                if (g.length) q.set(ten, [].concat(g).join(','));
            });

            // Hai ô ngày LUÔN gửi, kể cả khi trống: controller chỉ tự điền tháng
            // này khi tham số VẮNG MẶT, nên không gửi là người dùng xoá ngày xong
            // lại thấy tháng này quay về.
            ['from_date', 'to_date'].forEach(function (ten) {
                q.set(ten, String(oLoc(ten).val() || '').trim());
            });

            // Tham số không có ô trong khung lọc thì chép lại từ URL cũ, không là
            // đổi bộ lọc một cái mất luôn cột đang ẩn và cỡ trang.
            const cu = new URLSearchParams(location.search);
            ['hide', 'page_size'].forEach(function (ten) {
                if (cu.get(ten)) q.set(ten, cu.get(ten));
            });

            // Cố ý không mang `page`: lọc lại thì trang 5 của bộ lọc cũ hết nghĩa.
            V2.napLai(location.pathname + '?' + q);
        }

        let timerLoc = null;
        $(document).on('input', '.fillter-box [name="keyword"]', function () {
            clearTimeout(timerLoc);
            timerLoc = setTimeout(locLai, 300);
        });
        $(document).on('change',
            '.fillter-box [name="type"], .fillter-box [name="created_by"], '
            + '.fillter-box [name="category_id"], .fillter-box [name="source"], '
            + '.fillter-box [name="from_date"], .fillter-box [name="to_date"]',
            locLai);

        // ================= Phân loại đi theo Loại thu chi =================
        // Chức năng của v2: chọn "Phiếu thu" ở ô trên thì ô Phân loại chỉ còn bày
        // phân loại THU. Danh sách đã nằm sẵn trong trang (mỗi option mang
        // data-type), nên lọc ngay tại chỗ, không gọi thêm vòng mạng nào.
        //
        // Giữ nguyên phần đang chọn nếu nó vẫn hợp lệ; cái nào rơi ra ngoài thì bỏ.
        const PHAN_LOAI_DAY_DU = @json($jsPhanLoai);

        function locOPhanLoai() {
            const $o = $('.fillter-box [name="category_id"]');
            if (!$o.length) return;

            const loai = [].concat($('.fillter-box [name="type"]').val() || []).map(String);
            const dangChon = [].concat($o.val() || []).map(String);
            const hopLe = PHAN_LOAI_DAY_DU.filter(function (p) {
                return !loai.length || loai.indexOf(p.type) !== -1;
            });

            $o.empty();
            hopLe.forEach(function (p) {
                $o.append($('<option></option>').val(p.id).text(p.name)
                    .prop('selected', dangChon.indexOf(p.id) !== -1));
            });

            // change.select2 vẽ lại ô mà KHÔNG bắn `change` thường — bắn change
            // thường ở đây là gọi locLai() thêm một lượt nữa cho cùng một thao tác.
            $o.trigger('change.select2');
        }

        $(document).on('change', '.fillter-box [name="type"]', locOPhanLoai);
        $(document).on('v2:da-nap', locOPhanLoai);
        $(locOPhanLoai);

        // Sáu mốc nhanh: điền hai ô ngày rồi lọc. Hai ô vẫn là nguồn sự thật nên
        // người dùng sửa tay sau đó cũng không chọi với mốc đang tick.
        $(document).on('change', '.tc-moc-thoi-gian', function () {
            const nay = moment();
            let tu, den;
            switch (this.value) {
                case 'today': tu = nay.clone().startOf('day'); den = nay.clone(); break;
                case 'yesterday': tu = nay.clone().subtract(1, 'days'); den = tu.clone(); break;
                case 'thisWeek': tu = nay.clone().startOf('isoWeek'); den = nay.clone(); break;
                case 'lastWeek': tu = nay.clone().subtract(1, 'weeks').startOf('isoWeek'); den = tu.clone().endOf('isoWeek'); break;
                case 'thisMonth': tu = nay.clone().startOf('month'); den = nay.clone(); break;
                case 'lastMonth': tu = nay.clone().subtract(1, 'months').startOf('month'); den = tu.clone().endOf('month'); break;
                default: return;
            }
            oLoc('from_date').val(tu.format('DD-MM-YYYY'));
            oLoc('to_date').val(den.format('DD-MM-YYYY'));
            locLai();
        });

        // Ô Chi nhánh: KHÔNG đi qua locLai(). Đây là chi nhánh đang làm việc của
        // tab, đổi nó là đổi cả phiên làm việc chứ không phải thêm một điều kiện
        // lọc — `V2.doiChiNhanhTab` lo sessionStorage rồi nạp lại trang.
        $(document).on('change', '#tc-branch', function () {
            V2.doiChiNhanhTab(this.value);
        });

        // ================= Chọn cột =================
        // Cột đang tắt ghi vào ?hide= rồi tải lại, để giữ sau khi đổi trang.
        function apDungCot() {
            const tat = $('.show_col').filter(function (i, el) { return !el.checked; })
                .map(function (i, el) { return $(el).data('col').replace('show_', ''); }).get();
            const q = new URLSearchParams(location.search);
            tat.length ? q.set('hide', tat.join(',')) : q.delete('hide');
            V2.napLai(location.pathname + '?' + q);
        }
        $(document).on('change', '.show_col', apDungCot);
        $(document).on('change', '#show_all', function () {
            $('.show_col').prop('checked', this.checked);
            apDungCot();
        });

        // ================= Lịch cho hai ô ngày =================
        // KHÔNG tự gọi select2 ở đây: vỏ master đã dựng cho mọi `.fillter-box select`
        // và cố ý KHÔNG khai placeholder — khai vào là select2 rút dòng rỗng "Tất cả"
        // khỏi danh sách, chọn một giá trị xong không còn đường quay lại.
        function gan() {
            ['#tc-from-date', '#tc-to-date'].forEach(function (sel) {
                const $o = $(sel);
                if (!$o.length || $o.data('daterangepicker')) return;
                $o.daterangepicker({
                    singleDatePicker: true,
                    showDropdowns: true,
                    locale: V2.lichVN(),
                    autoUpdateInput: false,
                    autoApply: true,
                }, function (start) {
                    $o.val(start.format('DD-MM-YYYY')).trigger('change');
                });
            });
        }
        $(gan);
        $(document).on('v2:da-nap', gan);

        // ================= Hộp lập phiếu =================
        const $hop = $('#addIncomeExpense');

        // ---------- Ô thả xuống trong hộp thoại ----------
        // Vỏ v2 chỉ tự gắn select2 cho `.fillter-box select`, hộp thoại thì để
        // màn tự lo (xem khối cuối layouts/master). Không gắn là ô trong hộp
        // hiện ô thả xuống mặc định của trình duyệt — mỗi hệ điều hành vẽ một
        // kiểu, cao thấp khác hẳn mấy ô còn lại ngay bên cạnh.
        //
        // `dropdownParent` là bắt buộc: không có thì bảng thả xuống dựng ở cuối
        // <body>, nằm DƯỚI lớp phủ của modal và bấm không trúng.
        //
        // KHÔNG khai `placeholder`, đúng lý do vỏ đã ghi: mỗi ô đã có sẵn dòng
        // "Chọn" mang nghĩa thật, select2 coi dòng rỗng là placeholder thì nó
        // rút dòng ấy khỏi danh sách và không còn đường bỏ chọn.
        function ganSelect2($o, $trong) {
            if (!$o.length) return;

            $o.select2({
                width: '100%',
                dropdownParent: $trong,
                // Danh sách ngắn thì ô tìm chỉ tổ vướng; dài mới cần.
                minimumResultsForSearch: $o.find('option').length > 8 ? 0 : Infinity,
                language: {
                    noResults: function () { return 'Không có mục nào khớp'; },
                    searching: function () { return 'Đang tìm…'; },
                },
            });
        }

        /**
         * Dựng lại select2 cho một ô sau khi đổ option mới.
         *
         * Phải destroy rồi gắn lại chứ không chỉ `trigger('change.select2')`:
         * ngưỡng hiện ô tìm tính theo SỐ OPTION lúc khởi tạo, mà hai ô Phân loại
         * và Người nộp thì đổi từ 1 dòng lên vài chục dòng. Giữ nguyên bản cũ là
         * danh sách dài mà không có ô tìm.
         */
        function veLaiSelect2($o) {
            if ($o.hasClass('select2-hidden-accessible')) $o.select2('destroy');
            ganSelect2($o, $hop);
        }

        // Đặt giá trị bằng JS thì select2 KHÔNG tự biết — thẻ <select> gốc đã đổi
        // nhưng ô người dùng nhìn thấy vẫn in giá trị cũ. `change.select2` vẽ lại
        // mà không bắn `change` thường, nên không kéo theo các handler khác.
        function veChon($o) {
            if ($o.hasClass('select2-hidden-accessible')) $o.trigger('change.select2');
        }

        const O_TINH = '#tc-type, #tc-payer-type, #tc-payment-method';

        $hop.on('shown.bs.modal', function () {
            ['#tc-type', '#tc-category-id', '#tc-payer-type', '#tc-payer-id', '#tc-payment-method']
                .forEach(function (sel) {
                    const $o = $(sel);
                    if (!$o.hasClass('select2-hidden-accessible')) ganSelect2($o, $hop);
                });
        });

        function soTu(o) { return String(o == null ? '' : o).replace(/[^0-9]/g, ''); }

        // Số tiền: gõ tới đâu chấm phân cách tới đó, cắt ở 12 chữ số.
        $(document).on('input', '#tc-amount', function () {
            const raw = soTu(this.value).slice(0, 12);
            this.value = raw ? Number(raw).toLocaleString('vi-VN') : '';
        });

        $(document).on('input', '#tc-note', function () {
            $hop.find('.char-counter').text(this.value.length + '/' + this.maxLength);
        });

        // Phân loại đi theo loại phiếu: chọn Phiếu thu thì chỉ bày phân loại thu.
        function napPhanLoai(loai, chon) {
            const $o = $('#tc-category-id');
            $o.html(O_TRONG);
            if (loai === '' || loai == null) { veLaiSelect2($o); return $.Deferred().resolve().promise(); }

            $o.prop('disabled', true);

            return $.getJSON(URL_TC_PHAN_LOAI, { type: loai })
                .done(function (r) {
                    (r.data || []).forEach(function (pl) {
                        $o.append($('<option></option>').val(pl.id).text(pl.name || ''));
                    });
                    if (chon) $o.val(String(chon));
                })
                .fail(function () { toastr.error('Không tải được phân loại thu chi.'); })
                .always(function () { $o.prop('disabled', false); veLaiSelect2($o); });
        }

        // Ô "Người nộp" lấy danh sách ở đâu là do loại đối tượng quyết định.
        function napNguoiNop(loaiDoiTuong, chon) {
            const $o = $('#tc-payer-id');
            $o.html(O_TRONG);
            $('.div-add-payer').toggleClass('d-none', loaiDoiTuong !== 'other');

            function dat(ds) {
                ds.forEach(function (n) { $o.append($('<option></option>').val(n.id).text(n.name || '')); });
                if (chon) $o.val(String(chon));
            }

            // Hai vai nhân viên: lọc ngay trên danh sách đã có sẵn trong trang.
            if (VAI_NHAN_VIEN.indexOf(loaiDoiTuong) !== -1) {
                dat(DS_NHAN_VIEN.filter(function (n) {
                    return (n.quyen || []).indexOf(loaiDoiTuong) !== -1;
                }));
                veLaiSelect2($o);

                return $.Deferred().resolve().promise();
            }

            if (loaiDoiTuong === 'supplier') { dat(DS_NHA_CUNG_CAP); veLaiSelect2($o); return $.Deferred().resolve().promise(); }
            if (loaiDoiTuong !== 'other') { veLaiSelect2($o); return $.Deferred().resolve().promise(); }

            $o.prop('disabled', true);

            return $.getJSON(URL_TC_NGUOI_NOP)
                .done(function (r) { dat(r.data || []); })
                // Lỗi phải TỚI được người dùng: hoá thành danh sách rỗng thì người
                // ta ngồi thêm mới mãi mà không hiểu vì sao lưu không được.
                .fail(function (x) {
                    toastr.error((x.responseJSON && x.responseJSON.message) || 'Không tải được danh sách người nộp.');
                })
                .always(function () { $o.prop('disabled', false); veLaiSelect2($o); });
        }

        $(document).on('change', '#tc-type', function () { napPhanLoai(this.value, null); });
        $(document).on('change', '#tc-payer-type', function () { napNguoiNop(this.value, null); });

        function donHop() {
            $hop.find('.id').val('');
            $('#tc-type').val('');
            $('#tc-category-id').html(O_TRONG);
            $('#tc-amount').val('');
            $('#tc-payer-type').val('');
            $('#tc-payer-id').html(O_TRONG);
            $('.div-add-payer').addClass('d-none');
            $('#tc-payment-method').val('cash');
            $('#tc-note').val('').trigger('input');
            datDinhKem('');
            $('#tc-attachment').val('');

            $(O_TINH).each(function () { veChon($(this)); });
            veLaiSelect2($('#tc-category-id'));
            veLaiSelect2($('#tc-payer-id'));
        }

        function moHop(mode, $tr) {
            donHop();
            $hop.attr('data-mode', mode);
            $hop.find('.modal-title').text(mode === 'edit'
                ? @json(__('message.edit_receipt'))
                : @json(__('message.add_receipt')));

            if (mode === 'edit' && $tr) {
                const d = function (k) { return $tr.attr('data-' + k) || ''; };
                $hop.find('.id').val(d('id'));
                $('#tc-type').val(d('type'));
                $('#tc-amount').val(Number(d('amount') || 0).toLocaleString('vi-VN'));
                $('#tc-payer-type').val(d('payer-type'));
                $('#tc-payment-method').val(d('payment-method') || 'cash');
                $('#tc-note').val(d('note')).trigger('input');
                $(O_TINH).each(function () { veChon($(this)); });
                napPhanLoai(d('type'), d('category-id'));
                napNguoiNop(d('payer-type'), d('payer-id'));
                datDinhKem(d('attachment'));
            }

            $hop.modal('show');
        }

        $(document).on('click', '.add-item', function () { moHop('add', null); });
        $(document).on('click', '.edit-item', function (e) {
            e.stopPropagation();
            moHop('edit', $(this).closest('.item'));
        });

        $(document).on('click', '#addIncomeExpense .save-item', function () {
            const id = $hop.find('.id').val();
            const loai = $('#tc-type').val();
            const tien = soTu($('#tc-amount').val());
            const pt = $('#tc-payment-method').val();

            // Chặn ngay mấy lỗi hiển nhiên: đi một vòng mạng rồi mới báo "chưa
            // nhập số tiền" thì người dùng đã kịp bấm Lưu lần thứ hai.
            if (loai === '') { toastr.error('Chọn phiếu thu hay phiếu chi.'); return; }
            if (!tien || Number(tien) <= 0) { toastr.error('Nhập số tiền lớn hơn 0.'); return; }

            V2.luuHop($hop, id ? URL_TC + '/' + id : URL_TC_STORE, id ? 'PUT' : 'POST', {
                type: loai,
                amount: tien,
                category_id: $('#tc-category-id').val() || '',
                payer_type: $('#tc-payer-type').val() || '',
                payer_id: $('#tc-payer-id').val() || '',
                payment_method: pt,
                attachment: $('#tc-attachment-url').val(),
                note: $('#tc-note').val(),
            }, $(this));
        });

        // ================= Ô đính kèm =================
        // Tệp đẩy lên TRƯỚC bằng một lượt riêng rồi mới lưu phiếu — giống mọi màn
        // khác của khu này (Điều chỉnh tồn kho, Hàng hoá). Nhờ vậy lượt lưu vẫn là
        // JSON phẳng, và V2.luuHop dùng lại được y nguyên.
        function datDinhKem(url) {
            $('#tc-attachment-url').val(url || '');
            $('#file-name').text(url ? url.split('/').pop() : @json(__('message.no_file_chosen')));
            $('#tc-attachment-clear').toggleClass('d-none', !url);
        }

        $(document).on('click', '#custom-button', function () { $('#tc-attachment').trigger('click'); });

        $(document).on('click', '#tc-attachment-clear', function () {
            $('#tc-attachment').val('');
            datDinhKem('');
        });

        // Đẩy lên NGAY lúc chọn, không đợi bấm Lưu: hỏng tệp thì người dùng biết
        // liền và đổi tệp khác, thay vì gõ xong cả phiếu rồi mới bị chặn.
        $(document).on('change', '#tc-attachment', function () {
            const tep = this.files && this.files[0];
            if (!tep) return;

            const fd = new FormData();
            fd.append('file', tep);
            fd.append('_token', $('meta[name="csrf-token"]').attr('content'));

            $('#file-name').text('Đang tải lên…');
            $('.save-item').prop('disabled', true);

            $.ajax({
                url: URL_TC_DINH_KEM,
                method: 'POST',
                data: fd,
                processData: false,
                contentType: false,
                headers: { 'Accept': 'application/json' },
            })
                .done(function (r) { datDinhKem(r.url || ''); })
                .fail(function (x) {
                    const b = x.responseJSON || {};
                    const theoO = Object.keys(b.errors || {})
                        .map(function (k) { return [].concat(b.errors[k]).join(' '); })
                        .join(' ');
                    toastr.error(theoO || b.message || 'Không tải được tệp lên.');
                    $('#tc-attachment').val('');
                    datDinhKem($('#tc-attachment-url').val());
                })
                .always(function () { $('.save-item').prop('disabled', false); });
        });

        // ================= Thêm nhanh người nộp =================
        $(document).on('click', '.add-payer', function () {
            $('#addPayer').find('input, textarea').val('');
            $('#addPayer').modal('show');
        });

        $(document).on('input', '#payer-phone', function () { this.value = soTu(this.value); });

        $(document).on('click', '#addPayer .save-payer', function () {
            const ten = $('#payer-name').val().trim();
            if (!ten) { toastr.error('Nhập tên người nộp / người nhận.'); return; }

            const $nut = $(this);
            if ($nut.prop('disabled')) return;
            $nut.prop('disabled', true);

            // Không đi V2.luuHop: hàm đó nạp lại trang sau khi lưu, mà hộp lập
            // phiếu đang mở phía sau — nạp lại là mất sạch thứ vừa gõ trong đó.
            $.ajax({
                url: URL_TC_TAO_NGUOI_NOP,
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content'),
                },
                data: { name: ten, phone: $('#payer-phone').val(), address: $('#payer-address').val() },
            })
                .done(function (r) {
                    toastr.success((r && r.message) || 'Đã thêm.');
                    $('#addPayer').modal('hide');
                    // Nạp lại danh sách rồi chọn luôn người vừa thêm.
                    napNguoiNop('other', null).always(function () {
                        $('#tc-payer-id option').filter(function () {
                            return $(this).text() === ten;
                        }).prop('selected', true);
                        veChon($('#tc-payer-id'));
                    });
                })
                .fail(function (x) {
                    const b = x.responseJSON || {};
                    const theoO = Object.keys(b.errors || {})
                        .map(function (k) { return [].concat(b.errors[k]).join(' '); })
                        .join(' ');
                    toastr.error(theoO || b.message || 'Lưu không thành công.');
                })
                .always(function () { $nut.prop('disabled', false); });
        });

        // ================= Xem chi tiết =================
        // Dựng từ data-* của chính dòng đang bấm: mọi thứ cần in đã nằm sẵn trong
        // bảng, gọi thêm một vòng mạng chỉ để lấy lại ngần ấy chữ là thừa.
        function dongChiTiet(nhan, giaTri) {
            return '<div class="dong"><span class="nhan">' + nhan + ':</span>'
                + '<span class="gia">' + (giaTri || '') + '</span></div>';
        }

        function htmlChiTiet($tr) {
            const d = function (k) { return $tr.attr('data-' + k) || ''; };
            const thoat = function (s) { return $('<div>').text(s).html(); };

            // Dòng đầu: mã phiếu, và mã chứng từ đã đẻ ra nó nếu là phiếu tự sinh.
            //
            // v2 gọi ô bên phải là "Mã hóa đơn" vì bên đó chỉ có một nguồn duy
            // nhất. Bên này phiếu tự sinh ra từ bốn loại chứng từ (đơn bán, phiếu
            // mua, trả hàng, trả NCC) nên gọi đúng tên chung của chúng — loại nào
            // thì đọc ở dòng "Loại" bên dưới.
            let h = '<div class="hop-chi-tiet">';
            h += '<div class="dai-tieu-de">' + @json(__('message.detail')) + '</div>';
            h += '<div class="dong dong-ma"><span class="nhan">'
                + @json(__('message.receipt-code')) + ': ' + thoat(d('code')) + '</span>';
            if (d('source-code')) {
                h += '<span>' + @json(__('message.document_code')) + ': '
                    + '<span class="ma-nguon">' + thoat(d('source-code')) + '</span></span>';
            }
            h += '</div>';

            h += dongChiTiet(@json(__('message.type_of_income_expense')), thoat(d('type-name')));
            h += dongChiTiet(@json(__('message.income_expense_category')), thoat(d('category')));
            h += dongChiTiet(@json(__('message.branch-name')), thoat(d('branch')));
            h += dongChiTiet(@json(__('message.amount')), thoat(d('amount-text')) + ' đ');
            h += dongChiTiet(@json(__('message.payer')) + '/ ' + @json(__('message.payee')), thoat(d('payer')));
            h += dongChiTiet(@json(__('message.payment-method')), thoat(d('payment-method-name')));
            h += dongChiTiet(@json(__('message.type')), thoat(d('source-name')));
            h += dongChiTiet(@json(__('message.description')), thoat(d('note')));
            h += dongChiTiet(@json(__('message.recorded-time')), thoat(d('created-at')));
            h += dongChiTiet(@json(__('message.creator')), thoat(d('creator')));

            // Ô đính kèm luôn có mặt, kể cả khi rỗng — v2 cũng vậy. Một hàng trống
            // nói "phiếu này không có chứng từ kèm theo", còn giấu hàng đi thì
            // người xem không biết là không có hay là chưa nạp xong.
            h += dongChiTiet(@json(__('message.attachment')), d('attachment')
                ? '<a href="' + d('attachment') + '" target="_blank" rel="noopener">'
                    + @json(__('message.view-detail')) + '</a>'
                : '');

            h += '</div>';

            // Vì sao không sửa/xoá được — nói ra ngay đây, đừng bắt người dùng đi
            // tìm cái nút không có.
            if (d('locked') === '1' && d('locked-reason')) {
                // Viết hoa chữ đầu: câu này là câu LỖI của máy chủ, vốn viết
                // thường vì nó còn được ghép vào chỗ khác. In nguyên ra màn hình
                // thì thành một dòng bắt đầu bằng chữ thường giữa toàn chữ hoa.
                const lyDo = thoat(d('locked-reason'));
                h += '<p class="ly-do-khoa"><i class="fa fa-lock"></i><span>'
                    + lyDo.charAt(0).toUpperCase() + lyDo.slice(1) + '</span></p>';
            }

            return h;
        }

        $(document).on('click', '.detail-item', function (e) {
            e.stopPropagation();
            $('#detailIncomeExpense .modal-body').html(htmlChiTiet($(this).closest('.item')));
            $('#detailIncomeExpense').modal('show');
        });

        // Trên điện thoại bấm cả thẻ để mở tấm trượt — thẻ hẹp, không đủ chỗ đặt nút.
        $(document).on('click', '.none_desktop .item', function () {
            const $tr = $(this);
            $('#offcanvasDetail').attr('data-id', $tr.attr('data-id')).data('tr', $tr);
            $('#offcanvasDetail .modal-view-materials').html(htmlChiTiet($tr));
            $('#offcanvasDetail .button-header').toggleClass('d-none', $tr.attr('data-locked') === '1');
            bootstrap.Offcanvas.getOrCreateInstance(document.getElementById('offcanvasDetail')).show();
        });

        $(document).on('click', '.edit-item-canvas', function () {
            const $tr = $('#offcanvasDetail').data('tr');
            if (!$tr) return;
            const oc = bootstrap.Offcanvas.getInstance(document.getElementById('offcanvasDetail'));
            if (oc) oc.hide();
            moHop('edit', $tr);
        });

        $(document).on('click', '.delete-item-canvas', function () {
            $('#deleteValue').val($('#offcanvasDetail').attr('data-id'));
            $('#deleteItem').modal('show');
        });

        // ================= Xoá =================
        $(document).on('click', '.delete-item', function (e) {
            e.stopPropagation();
            $('#deleteValue').val($(this).closest('.item').attr('data-id'));
            $('#deleteItem').modal('show');
        });

        $(document).on('click', '#deleteItem .delete-value', function () {
            const id = $('#deleteValue').val();
            if (!id) return;
            // Đi đường hộp thoại (JSON): xoá xong hộp tự đóng và bắn toast; hỏng
            // (phiếu thuộc ca đã đóng) thì hộp giữ nguyên và báo đúng lý do.
            V2.luuHop($('#deleteItem'), URL_TC + '/' + id, 'DELETE', {}, $(this));
        });

        $('#deleteItem').on('hidden.bs.modal', function () { $('#deleteValue').val(''); });
    </script>
@endpush
