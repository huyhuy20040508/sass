{{-- Màn Phiếu mua hàng dựng theo khuôn v2 (purchase/order: index + list + create).
     Dữ liệu do PhieuMuaHangController đẩy sang: $list, $filters, $meta, $thongKe,
     $nhaCungCap, $nhanVien, $nhomHang. --}}
@extends('v2::layouts.master')

@section('title', \App\Http\Controllers\PhieuMuaHangController::TITLE)

@php
    $C = \App\Http\Controllers\PhieuMuaHangController::class;
    // Đang lọc mà bảng rỗng thì nói "không khớp bộ lọc", đừng nói "chưa có":
    // chưa có là chưa khai gì, còn khớp là khai rồi nhưng lọc không ra — hai
    // việc phải làm khác hẳn nhau. Cùng khuôn với khu cũ (resources/views/chi-nhanh).
    $hasFilter = collect($filters)->only(['keyword', 'status', 'payment_status', 'warehouse_status', 'supplier_id', 'variant_id', 'from_date', 'to_date'])
        ->contains(fn ($v) => $v !== '' && $v !== null && $v !== 0 && $v !== [] && $v !== 'all');
    $stt = ($meta['page'] - 1) * $meta['page_size'];
    $anhMacDinh = asset('v2/images/image_defaul.png');

    $tien = fn ($n) => number_format((float) $n, 0, ',', '.') . '₫';
    $so = fn ($n) => number_format((float) $n, 0, ',', '.');
    $ngay = function ($v) {
        $t = $v ? strtotime($v) : false;

        return $t ? date('d-m-Y', $t) : '';
    };

    $trangThaiChon = array_filter(explode(',', $filters['status']));
    $traChon = array_filter(explode(',', $filters['payment_status']));
    $khoChon = array_filter(explode(',', $filters['warehouse_status']));

    // Nhóm hàng có ba trạng thái: đọc được và có nhóm · đọc được mà chưa nhóm nào
    // có hàng · không đọc được. Gộp hai cái sau là người dùng đi tìm lỗi nhầm chỗ.
    $nhomHong = $nhomHang === null;
    $nhomRong = is_array($nhomHang) && $nhomHang === [];
@endphp

@push('styles')
    <style>
        /* Lấy khối style của purchase/order bản v2, bỏ phần khung v2 đã lo sẵn. */
        body { overflow-x: hidden }
        li { list-style-type: none; }
        .select2-container { width: 100% !important; }
        /* Select2 trong hộp thoại phải nổi trên lớp phủ của modal. */
        #modalCreate .select2-container--open, #modalCreate .select2-dropdown { z-index: 1065 !important; }
        #content_create label.form-label { text-align: left !important }
        .table-product td, .table-product th { vertical-align: middle; }

        /* ============ HỘP LẬP / SỬA PHIẾU ============
           Cả khối đứng trên đúng hai con số: nhãn cao 20px, ô nhập cao 34px. Mọi
           ô đều theo, nên dòng thứ n của bốn cột nằm đúng một hàng — kể cả khi
           một cột bị giấu bớt ô vì thuế trực tiếp. Đó là toàn bộ mẹo. */
        #content_create { --pmh-nhan: 20px; --pmh-o: 34px; }

        /* Thanh có tên: dùng cho cả cụm nút lưu ở trên và tiêu đề khối hàng hoá. */
        .pmh-thanh {
            display: flex; align-items: center; justify-content: space-between;
            gap: 12px; flex-wrap: wrap;
            padding: 12px 0; border-bottom: 1px solid #e9ecef; margin-bottom: 16px;
        }
        /* Tiêu đề khối hàng hoá dùng khung của v2; chỉ dặn thêm cách xếp hàng. */
        .content_midd_title.pmh-thanh-hang {
            display: flex; align-items: center; justify-content: space-between;
            gap: 12px; flex-wrap: wrap;
        }
        .content_midd_title.pmh-thanh-hang > h4 { margin: 0; }
        /* custom.css của vỏ v2 có `@media (max-width:991px){ div.content_midd_title
           h4 { display:none } }` — luật dành cho tiêu đề TRANG, nhưng nó ăn luôn
           tiêu đề khối hàng hoá trong hộp thoại: dưới 992px là mất hẳn dòng
           "Thông tin hàng hoá". Giành lại bằng độ ưu tiên của #modalCreate. */
        #modalCreate .content_midd_title h4 { display: block; }
        .pmh-thanh-hang { margin-top: 8px; }
        /* margin-left:auto chứ không trông vào `space-between`: lập mới thì thẻ
           mã phiếu rỗng nên ẩn, còn mỗi cụm nút, và `space-between` với một
           phần tử sẽ đẩy nó về TRÁI. */
        .pmh-thanh-nut { display: flex; gap: 8px; margin-left: auto; }
        .pmh-thanh-nut .bt { margin: 0; }
        /* Mã phiếu bên trái thanh nút; lập mới thì rỗng nên không chiếm chỗ. */
        .pmh-ma-phieu { margin: 0; font-size: 16px; font-weight: 600; color: #1a2b58; }
        .pmh-ma-phieu:empty { display: none; }
        /* Giấu riêng cho chế độ xem — `.anh-go` đã mượn d-none cho việc khác. */
        .pmh-an { display: none !important; }
        /* Vỏ v2 chỉ đặt `div.btn_top_content { display: flex }`, không có gap, nên
           dãy nút dính liền thành một dải chữ. */
        .btn_top_content { gap: 8px; align-items: center; flex-wrap: wrap; }
        .btn_top_content > * { margin: 0; }
        /* style.css của vỏ v2 chỉ có luật :hover cho .btn-print, thiếu màu lúc
           thường nên nút In ra trắng trơn. Bù đúng tông xanh của v2. */
        .pmh-thanh-nut .btn-print {
            background: #3598dc; border: 1px solid #3598dc; color: #fff;
        }
        .pmh-thanh-nut .bt i { margin-right: 4px; }
        /* Icon Huỷ: cam để tách hẳn với Xoá đỏ ngay bên cạnh — hai việc khác
           nhau, cùng một màu là sớm muộn bấm nhầm. */
        td.action .huy_bt i { font-size: 16px; color: #f29220; cursor: pointer; }

        /* Bốn cột thông tin phiếu. */
        .pmh-form {
            display: grid; grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 0 20px; margin-bottom: 8px;
        }
        .pmh-cot { min-width: 0; }

        /* MỘT ô: nhãn một dòng cao cố định + ô nhập cao cố định. Nhãn dài thì cắt
           bằng "…" chứ KHÔNG xuống dòng — xuống dòng là cột đó cao hơn ba cột kia
           và cả hàng lệch theo. Câu đầy đủ nằm ở thuộc tính title của ô. */
        .pmh-o { margin-bottom: 14px; }
        .pmh-o > .form-label {
            display: block; height: var(--pmh-nhan); line-height: var(--pmh-nhan);
            margin-bottom: 6px; font-size: 13px; font-weight: 500; color: #1a2b58;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .pmh-o .form-control,
        .pmh-o .select2-container--default .select2-selection--single {
            height: var(--pmh-o); min-height: var(--pmh-o);
        }
        .pmh-o .select2-container--default .select2-selection--single .select2-selection__rendered {
            line-height: calc(var(--pmh-o) - 2px);
        }
        .pmh-o .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: calc(var(--pmh-o) - 2px);
        }
        /* Ô khoá: tô nền xám cho thấy ngay là chỉ để đọc. CSS của v2 ép màu chữ
           mọi input nên chỉ dựa vào thuộc tính disabled thì nhìn y hệt ô gõ được. */
        .pmh-o .form-control:disabled { background-color: #f4f6f8; color: #6c757d !important; }

        /* Ô nhà cung cấp: ô chọn giãn hết chỗ, nút "+" là một ô vuông đúng bằng
           chiều cao ô chọn. Trước đây nút cao hơn nên hàng đầu của cột 1 lệch. */
        .pmh-o-ncc { display: flex; gap: 6px; align-items: center; }
        .pmh-o-ncc > .form-control,
        .pmh-o-ncc > .select2-container { flex: 1 1 auto; min-width: 0; }
        .pmh-nut-vuong {
            flex: 0 0 auto; width: var(--pmh-o); height: var(--pmh-o);
            display: inline-flex; align-items: center; justify-content: center;
            border: 1px solid #dee2e6; border-radius: 4px; background: #fff;
            color: #0d6efd; cursor: pointer; font-size: 12px;
        }
        .pmh-nut-vuong:hover { border-color: #0d6efd; background: #0d6efd; color: #fff; }

        /* Đơn vị nằm TRONG khung ô, không đứng rời bên ngoài. */
        .pmh-o-donvi { position: relative; }
        .pmh-o-donvi .form-control { padding-right: 52px; }
        .pmh-o-donvi-chu {
            position: absolute; right: 1px; top: 1px; bottom: 1px;
            display: flex; align-items: center; padding: 0 10px;
            border-left: 1px solid #dee2e6; border-radius: 0 4px 4px 0;
            background: #f4f6f8; color: #6c757d; font-size: 13px; pointer-events: none;
        }

        /* Lưới hàng cuộn ngang trong khung của nó, không đẩy cả hộp thoại rộng ra. */
        .pmh-luoi { overflow-x: auto; }

        /* Bộ công cụ của khối hàng hoá — ba ô cùng chiều cao, không dùng % nữa.
           `min-width: 0` là chỗ mấu chốt: phần tử của flex mặc định có
           `min-width: auto`, tức không co xuống dưới bề rộng nội dung của nó. Ô
           chọn thì "nội dung" là dòng option dài nhất, nên hai ô này cứ giữ nguyên
           200px + 280px và đội thân hộp thoại rộng ra — trên màn 768 trở xuống là
           cả hộp bị cuộn ngang. */
        .pmh-hang-cong-cu { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; min-width: 0; }
        .pmh-hang-cong-cu > * { min-width: 0; }
        /* `:not(.select2-hidden-accessible)` là bắt buộc: select2 giấu thẻ <select>
           gốc bằng lớp ấy (`position:absolute; width:1px !important`), mà luật ở
           đây có độ ưu tiên cao hơn nên sẽ thổi cái ô VÔ HÌNH đó phồng trở lại.
           Nó nằm ngoài dòng chảy, không ai thấy, nhưng vẫn đẩy thân hộp thoại
           cuộn ngang trên màn hẹp. */
        .pmh-hang-cong-cu .select-categories:not(.select2-hidden-accessible) { width: 200px; flex: 0 1 200px; max-width: 100%; }
        .pmh-hang-cong-cu .select-menus:not(.select2-hidden-accessible) { width: 280px; flex: 0 1 280px; max-width: 100%; }
        .pmh-hang-cong-cu .form-control,
        .pmh-hang-cong-cu .bt,
        .pmh-hang-cong-cu .select2-container--default .select2-selection--single {
            height: var(--pmh-o); min-height: var(--pmh-o);
        }
        .pmh-hang-cong-cu .select2-container { width: 280px !important; flex: 0 1 280px; max-width: 100%; }

        /* `wrapper-money-into` là một `col-12` đứng ngoài mọi `.row`, nên hai hàng
           bên trong nó mang lề âm của Bootstrap mà không có lề dương nào bù lại —
           trên màn hẹp là thừa ra hơn chục điểm ảnh. */
        .wrapper-money-into > .row { margin-left: 0; margin-right: 0; }
        .pmh-hang-cong-cu .select2-container--default .select2-selection--single .select2-selection__rendered {
            line-height: calc(var(--pmh-o) - 2px);
        }
        .pmh-hang-cong-cu .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: calc(var(--pmh-o) - 2px);
        }
        .pmh-hang-cong-cu .bt { display: inline-flex; align-items: center; margin: 0; }

        /* Màn hẹp: bốn cột rút còn hai rồi còn một; bộ công cụ giãn hết bề ngang. */
        @media (max-width: 1199px) {
            .pmh-form { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        @media (max-width: 767px) {
            .pmh-form { grid-template-columns: minmax(0, 1fr); }
            .pmh-hang-cong-cu { width: 100%; }
            .pmh-hang-cong-cu .select-categories:not(.select2-hidden-accessible),
            .pmh-hang-cong-cu .select-menus:not(.select2-hidden-accessible),
            .pmh-hang-cong-cu .select2-container { width: 100% !important; }
        }

        {{-- Khoảng cách giữa các khối lọc nay là luật CHUNG, nằm ở v2::layouts.master. --}}

        /* Bảng danh sách: 14 cột CHIA THEO %, cộng đúng 100 — vừa khít khung, không
           cuộn ngang, cột Hành động luôn trong màn. `table-layout: fixed` để bề rộng
           do hàng tiêu đề quyết; ô dữ liệu dài cắt "…" (chữ đủ ở title), nhãn cột
           dài xuống dòng. Tắt cột (.col-x ẩn) thì % hụt được chia lại cho cột còn lại. */
        .list .table-responsive { overflow-x: auto; }
        table.table-purchase.none_mobile { width: 100%; table-layout: fixed; }
        table.table-purchase.none_mobile th { white-space: normal; line-height: 1.3; }
        table.table-purchase.none_mobile td { overflow: hidden; text-overflow: ellipsis; }
        /* Đệm ngang 4px thay vì 8px của v2: 14 cột × 8px là thêm gần một cột tiền. */
table.table-purchase.none_mobile th, table.table-purchase.none_mobile td { padding: 8px 4px; }
        /* Chia % — đo thật ở khung 1182px (màn 1536): mã phiếu (16 ký tự), ngày,
           tiền, ba cột trạng thái và nút Hành động không bị cắt; tên NCC, người
           tạo, ghi chú chịu cắt "…" (có title).
           ☐ 2.5 · STT 3.5 · Mã 13.5 · NCC 6.5 · Ngày CT 8.5 · Tiền hàng 9 · Tổng tiền 9 · Còn nợ 8.5
           · TT đơn 7 · TT kho 8 · TT thanh toán 8.5 · Người tạo 4.5 · Ghi chú 5 · Hành động 6 = 100 */
        table.table-purchase.none_mobile th:first-child { width: 2.5%; }
        table.table-purchase.none_mobile th:nth-child(2) { width: 3.5%; }
        table.table-purchase.none_mobile th.col-code { width: 13.5%; }
        table.table-purchase.none_mobile th.col-supplier { width: 6.5%; }
        table.table-purchase.none_mobile th.col-docdate { width: 8.5%; }
        table.table-purchase.none_mobile th.col-items { width: 9%; }
        table.table-purchase.none_mobile th.col-total { width: 9%; }
        table.table-purchase.none_mobile th.col-debt { width: 8.5%; }
        table.table-purchase.none_mobile th.col-status { width: 7%; }
        table.table-purchase.none_mobile th.col-warehouse { width: 8%; }
        table.table-purchase.none_mobile th.col-pay { width: 8.5%; }
        table.table-purchase.none_mobile th.col-creator { width: 4.5%; }
        table.table-purchase.none_mobile th.col-note { width: 5%; }
        table.table-purchase.none_mobile th:last-child { width: 6%; }

        /* ---- Canh lưới hàng ----
           style.css của v2 ép `th, td { text-align: center !important }` cho MỌI
           bảng. Với lưới nhập liệu thì hỏng: con số canh giữa không so được với
           nhau, mà ô nhập lại là mấy cái hộp hẹp trôi giữa ô rộng — nhìn cả hàng
           như răng cưa. Ba lớp dưới đây kê lại, và phải !important mới thắng nổi. */
        .table-product th.la-chu, .table-product td.la-chu { text-align: left !important; }
        .table-product th.la-so, .table-product td.la-so { text-align: right !important; }
        .table-product th.la-giua, .table-product td.la-giua { text-align: center !important; }

        /* ---- BỀ RỘNG CỘT: cố định, không để trình duyệt tự chia ----
           Bố cục tự động chia bề rộng theo NỘI DUNG. Một dòng hàng ngắn thì cột co
           quanh nó trong khi tiêu đề vẫn dài — nhãn và số dạt về hai phía, nhìn
           lệch hẳn; thêm dòng dài hơn hoặc thêm dòng thứ hai là cột nở ra và tự
           khớp lại. Đúng cái đang thấy.

           `table-layout: fixed` cắt đứt chuyện đó: cột rộng bao nhiêu do hàng TIÊU
           ĐỀ quy định, một dòng hay hai mươi dòng cũng vậy.

           CẶP BẮT BUỘC ĐI KÈM là `overflow: hidden` trên ô. Lần trước tôi bỏ quên
           nên `white-space: nowrap` của v2 làm mã hàng dài tràn đè lên tên hàng.
           Nay cắt bằng "…", chữ đầy đủ nằm ở thuộc tính title của ô. */
        .table-product { table-layout: fixed; width: 100%; min-width: 1400px; }
        .table-product td {
            padding: 8px 10px; overflow: hidden; text-overflow: ellipsis;
        }
        /* Nhãn cột dài thì XUỐNG DÒNG, không cắt: "Thành tiền (chưa VAT)" mà cụt
           thành "Thành tiền (ch…" là mất đúng phần nói rõ nó khác cột bên cạnh chỗ
           nào. Ô dữ liệu vẫn cắt bằng "…" (chữ đủ nằm ở title) — cắt một mã hàng
           thì còn đoán ra, cắt tên cột thì không. */
        .table-product th {
            padding: 8px 10px; white-space: normal; vertical-align: bottom; line-height: 1.35;
        }

        /* Bề rộng theo PHẦN TRĂM, cộng đúng 100%. Đặt bằng px thì cột Tên hàng hóa
           (cột auto duy nhất) nuốt sạch phần dư của màn rộng — đúng cái khoảng trống
           mênh mông giữa Tên hàng hóa và Đơn vị. Theo % thì mọi cột cùng giãn.

           Đặt trên hàng TIÊU ĐỀ, KHÔNG dùng <colgroup>: cột % VAT ẩn/hiện theo cách
           khai thuế, mà <col> thì không ẩn theo <td> được — lệch một cột là cả bảng
           so le. Ẩn <th> và <td> bằng cùng một lớp thì luôn khớp, và phần trăm hụt
           đi được trình duyệt chia lại cho các cột còn lại. */
        .table-product th.c-stt { width: 3%; }
        .table-product th.c-ma { width: 11%; }
        .table-product th.c-ten { width: 15%; }
        .table-product th.c-dv { width: 8%; }
        .table-product th.c-sl { width: 6%; }
        .table-product th.c-gia { width: 8%; }
        .table-product th.c-tien { width: 9%; }
        .table-product th.c-vat { width: 5%; }
        .table-product th.c-vat-tien { width: 6%; }
        .table-product th.c-tong { width: 10%; }
        .table-product th.c-lo { width: 9%; }
        .table-product th.c-han { width: 8%; }
        .table-product th.c-xoa { width: 2%; }

        /* Viền và nền khai THẲNG ở đây, không dựa vào `.form-control` của Bootstrap:
           v2 nạp năm tệp CSS sau Bootstrap và đã có tiền lệ đè lên nó. Ô nhập mất
           viền thì nhìn y như chữ thường, không ai biết chỗ nào gõ được.

           Bố cục đã cố định nên `width: 100%` giờ AN TOÀN — và đây mới là thứ làm
           mép phải ô nhập trùng mép phải cột bên cạnh. */
        .table-product .ip-line {
            width: 100%; height: 32px; padding: 2px 8px; font-size: 13px; text-align: right;
            border: 1px solid #dee2e6; border-radius: 4px; background-color: #fff;
            color: #212529;
        }
        .table-product .ip-line:focus { border-color: #86b7fe; outline: 0; }
        /* Ô chọn: chừa chỗ cho mũi tên, không thì chữ trườn lên trên nó. */
        .table-product select.ip-line { padding-right: 26px; }
        .table-product select.ip-line, .table-product .ip-line.is-text { text-align: left; }
        /* Ô ngày chỉ chọn bằng lịch — con trỏ bàn tay, không phải con trỏ gõ chữ. */
        .table-product .ip-ngay { text-align: center; cursor: pointer; background-color: #fff; }

        /* Khối gõ lô mới — hiện ngay dưới ô chọn đang bị giấu, đúng như v2. */
        /* Xuống dòng được: cột lô hẹp hơn tổng bề ngang ô gõ + hai nút, mà ô thì
           đang cắt tràn — không cho xuống dòng là hai nút bị cắt mất. */
        .lo-moi-box { display: flex; align-items: center; gap: 4px; flex-wrap: wrap; }
        .lo-moi-box .lo-moi-o { width: 100%; }
        .lo-moi-box .bt { margin: 0; padding: 2px 8px; font-size: 12px; height: 32px; }
        /* Ô bị khoá theo lô đang có: tô xám cho thấy ngay là chỉ để đọc. */
        .table-product .ip-line[readonly], .table-product .ip-ngay.khoa {
            background-color: #f4f6f8; color: #6c757d !important; cursor: default;
        }

        .line-conv { display: block; font-size: 11px; color: #8c8c8c; text-align: right; }
        .line-conv.is-le { color: #ff4d4f; }

        /* Cột % VAT chỉ có nghĩa khi khai thuế theo từng dòng hàng. */
        .table-product:not(.is-vat-goods) .col-vat { display: none; }
        /* Hộ kinh doanh nộp thuế trực tiếp: chiều mua không có đường VAT nào. */
        .table-product.is-thue-truc-tiep .col-vat,
        .table-product.is-thue-truc-tiep .col-vat-money,
        .table-product.is-thue-truc-tiep .col-sau-vat { display: none; }

        /* Khối tiền canh phải — đúng cụm cuối màn lập phiếu của v2. */
        /* ---- Khối tiền ----
           Là chỗ người ta soi kỹ nhất trước khi bấm Duyệt, nên nó phải đọc được
           trong một cái liếc: một khung riêng, nhãn nhạt, số đậm, và dòng tổng
           tách hẳn ra. Trước đây ba dòng chữ trôi giữa nền trắng, tổng cộng nhìn
           ngang hàng với hai dòng phụ. */
        .money-box {
            margin-left: auto; width: 340px; max-width: 100%;
            border: 1px solid #e9ecef; border-radius: 8px;
            background: #fbfcfd; padding: 12px 16px;
        }
        .money-row {
            display: flex; align-items: baseline; justify-content: space-between;
            gap: 16px; padding: 5px 0; font-size: 13px;
        }
        .money-row > span { color: #6c757d; }
        .money-row > b {
            color: #212529; white-space: nowrap;
            /* Chữ số đều bề ngang: các con số xếp thẳng cột theo chiều dọc, so
               nhanh được hàng nghìn với hàng triệu. */
            font-variant-numeric: tabular-nums; font-feature-settings: 'tnum' 1;
        }

        /* Dòng TỔNG: gạch ngang tách ra, chữ to hơn một nấc, màu chủ đạo. */
        .money-row.is-total {
            border-top: 1px solid #dee2e6; margin-top: 6px; padding-top: 10px;
        }
        .money-row.is-total > span { color: #212529; font-weight: 600; font-size: 14px; }
        .money-row.is-total > b { font-size: 18px; color: #0d6efd; }

        /* Dòng phụ đầu khối: đếm dòng hàng, chữ nhỏ, tách khỏi phần tiền. */
        .money-row.is-dem {
            padding: 0 0 8px; margin-bottom: 4px; border-bottom: 1px dashed #e9ecef;
            font-size: 12px;
        }
        .money-row.is-dem > b { color: #6c757d; font-weight: 600; }

        /* Cụm sau dòng tổng (đã trả / còn nợ ở hộp Chi tiết). */
        .money-row.is-sau { margin-top: 2px; }
        .money-row.is-sau:first-of-type { margin-top: 8px; }
        .money-no { color: #d4380d; }

        /* Hai đường tắt dưới hai ô ngày — chữ nhỏ, không tranh chỗ với ô nhập. */
        /* Dải thống kê dưới tiêu đề trang. */
        .pmh-sum { font-size: 13px; color: #595959; }
        .pmh-sum b { color: #262626; }
        .pmh-sum em { font-style: normal; color: #bfbfbf; font-size: 12px; }

        /* Hộp chi tiết */
        .view-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px 16px; }
        .view-grid .is-full { grid-column: 1 / -1; }
        .view-cell { display: flex; flex-direction: column; }
        .view-lb { font-size: 12px; color: #8c8c8c; }
        .view-vl { font-size: 14px; font-weight: 500; word-break: break-word; }
        .his-row { display: flex; gap: 12px; padding: 4px 0; border-bottom: 1px dashed #f0f0f0; font-size: 13px; }
        .his-time { color: #8c8c8c; white-space: nowrap; }
        @media (max-width: 767px) { .view-grid { grid-template-columns: 1fr; } }
    </style>
    {{-- Cột đang tắt ở ô "chọn cột". Là CSS nên nạp lại danh sách bằng AJAX vẫn giữ. --}}
    <style id="cotAnCss"></style>
@endpush

@section('content')
    {{-- Nút mở bộ lọc dạng offcanvas, chỉ hiện trên điện thoại — đúng năm nút của v2. --}}
    <div class="call-to-action-container">
        <div class="wrapper-call-to-action">
            <div class="btn-open-modal" type="button" data-bs-toggle="offcanvas" data-bs-target="#offcanvasBottomInMobile"
                aria-controls="offcanvasBottomInMobile" data-offcanvas-target="#filterSearchKey">
                <p class="open-modal-label">{{ __('message.search') }}</p>
                <div class="icon-for-cta"><i class="fa-solid fa-magnifying-glass"></i></div>
            </div>
            <div class="btn-open-modal" type="button" data-bs-toggle="offcanvas" data-bs-target="#offcanvasBottomInMobile"
                aria-controls="offcanvasBottomInMobile" data-offcanvas-target="#filterCreateAt">
                <p class="open-modal-label">{{ __('message.order-created-at') }}</p>
                <div class="icon-for-cta"><i class="fa-regular fa-calendar"></i></div>
            </div>
            <div class="btn-open-modal" type="button" data-bs-toggle="offcanvas" data-bs-target="#offcanvasBottomInMobile"
                aria-controls="offcanvasBottomInMobile" data-offcanvas-target="#filterOrderStatus">
                <p class="open-modal-label">{{ __('message.order_status') }}</p>
                <div class="icon-for-cta"><i class="fa-solid fa-filter"></i></div>
            </div>
            <div class="btn-open-modal" type="button" data-bs-toggle="offcanvas" data-bs-target="#offcanvasBottomInMobile"
                aria-controls="offcanvasBottomInMobile" data-offcanvas-target="#filterStockReceiptStatus">
                <p class="open-modal-label">{{ __('message.stock_receipt_status') }}</p>
                <div class="icon-for-cta"><i class="fa-solid fa-warehouse"></i></div>
            </div>
            <div class="btn-open-modal" type="button" data-bs-toggle="offcanvas" data-bs-target="#offcanvasBottomInMobile"
                aria-controls="offcanvasBottomInMobile" data-offcanvas-target="#filterPaymentStatus">
                <p class="open-modal-label">{{ __('message.payment-status') }}</p>
                <div class="icon-for-cta"><i class="fa-solid fa-money-bill"></i></div>
            </div>
        </div>
    </div>

    <div class="row index-purchase-order-page">
        {{-- Cột lọc bên trái — lưới nửa bậc 2_5 / 9_5 của riêng v2.
             d-none d-lg-block: trên điện thoại các khối này đã đi vào offcanvas, để
             hiện cả hai chỗ là bộ lọc nằm hai nơi trong cùng một trang. --}}
        <div class="col-12 col-lg-2_5 col-xl-2 d-none d-lg-block pe-lg-0 fillter-box-container">
            <div class="fillter-box">
                <div class="card">
                    <div class="card-header card-header-primary header_search">{{ __('message.filter') }}</div>
                    <div class="card-body px-2">
                        {{-- Không có nút "Lọc": gõ hay đổi ô nào là lọc lại ngay. --}}
                        <form action="{{ route('admin.phieu-mua-hang.index') }}" method="GET" id="search-form"
                            class="d-sm-flex justify-content-sm-between align-items-sm-start flex-lg-column">

                            <div id="filterSearchKey" class="w-100">
                                <div class="inner-modal-in-mobile">
                                    <span class="title_search">{{ __('message.search') }}</span>
                                    <input type="text" name="keyword" value="{{ $filters['keyword'] }}"
                                        class="form-control mt-1 keyword" autocomplete="off"
                                        placeholder="{{ __('message.search_by_name_or_code') }}">
                                </div>
                            </div>

                            <div id="filterCreateAt" class="w-100">
                                <div class="inner-modal-in-mobile">
                                    <span class="title_search">{{ __('message.order-created-at') }}</span>
                                    {{-- Hai ô ngày gõ theo DD-MM-YYYY và mở lịch daterangepicker, đúng như v2. --}}
                                    <div class="d-flex flex-lg-column gap-2 gap-lg-0">
                                        <input type="text" name="from_date" autocomplete="off"
                                            value="{{ $filters['from_date'] ? date('d-m-Y', strtotime($filters['from_date'])) : '' }}"
                                            class="form-control mb-lg-1" id="from_date"
                                            placeholder="{{ __('message.from_date') }}">
                                        <input type="text" name="to_date" autocomplete="off"
                                            value="{{ $filters['to_date'] ? date('d-m-Y', strtotime($filters['to_date'])) : '' }}"
                                            class="form-control" id="to_date" placeholder="{{ __('message.to_date') }}">
                                    </div>
                                </div>
                            </div>

                            <div id="filterOrderStatus" class="w-100">
                                <div class="inner-modal-in-mobile">
                                    <span class="title_search">{{ __('message.order_status') }}</span>
                                    @foreach ($C::TRANG_THAI as $ma => $ten)
                                        <div class="form-check gap-0">
                                            <input class="form-check-input status" type="checkbox" name="status[]"
                                                value="{{ $ma }}" id="order_status_{{ $ma }}"
                                                {{ in_array($ma, $trangThaiChon, true) ? 'checked' : '' }}>
                                            <label class="form-check-label ms-2 {{ $C::CHU_TRANG_THAI[$ma] ?? '' }}"
                                                style="font-weight: bold" for="order_status_{{ $ma }}">{{ $ten }}</label>
                                        </div>
                                    @endforeach
                                </div>
                            </div>

                            <div id="filterStockReceiptStatus" class="w-100">
                                <div class="inner-modal-in-mobile">
                                    <span class="title_search">{{ __('message.stock_receipt_status') }}</span>
                                    @foreach ($C::TRANG_THAI_KHO as $ma => $ten)
                                        <div class="form-check gap-0">
                                            <input class="form-check-input stock_receipt_status" type="checkbox"
                                                name="warehouse_status[]" value="{{ $ma }}" id="stock_status_{{ $ma }}"
                                                {{ in_array($ma, $khoChon, true) ? 'checked' : '' }}>
                                            <label class="form-check-label ms-2 {{ $C::CHU_TRANG_THAI_KHO[$ma] ?? '' }}"
                                                style="font-weight: bold" for="stock_status_{{ $ma }}">{{ $ten }}</label>
                                        </div>
                                    @endforeach
                                </div>
                            </div>

                            <div id="filterPaymentStatus" class="w-100">
                                <div class="inner-modal-in-mobile">
                                    <span class="title_search">{{ __('message.payment-status') }}</span>
                                    @foreach ($C::TRANG_THAI_TRA as $ma => $ten)
                                        <div class="form-check gap-0">
                                            <input class="form-check-input payment_status" type="checkbox"
                                                name="payment_status[]" value="{{ $ma }}" id="payment_status_{{ $ma }}"
                                                {{ in_array($ma, $traChon, true) ? 'checked' : '' }}>
                                            <label class="form-check-label ms-2 {{ $C::CHU_TRANG_THAI_TRA[$ma] ?? '' }}"
                                                style="font-weight: bold" for="payment_status_{{ $ma }}">{{ $ten }}</label>
                                        </div>
                                    @endforeach
                                </div>
                            </div>

                            {{-- Khối cuối: v2 có sẵn khối Nhà cung cấp (đang chú thích lại) ở đúng chỗ này. --}}
                            <div id="filterSupplier" class="w-100">
                                <div class="inner-modal-in-mobile">
                                    <span class="title_search">{{ __('message.supplier') }}</span>
                                    <select name="supplier_id" class="form-control form-select mt-1">
                                        <option value="">{{ __('message.all') }}</option>
                                        @foreach ($nhaCungCap as $ncc)
                                            <option value="{{ $ncc['id'] }}"
                                                {{ $filters['supplier_id'] === (int) $ncc['id'] ? 'selected' : '' }}>
                                                {{ ($ncc['code'] ?? '') ? $ncc['code'] . ' - ' : '' }}{{ $ncc['name'] ?? '' }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>

                            {{-- Đổi bộ lọc là về trang 1; số dòng mỗi trang thì giữ. --}}
                            <input type="hidden" name="page_size" value="{{ $meta['page_size'] }}">
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-9_5 col-xl-10 wrapper-content-dashboard-middle">
            <div class="content_midd">
                <div class="content_midd_title">
                    {{-- h1 chứ không phải h4: đây là tiêu đề cấp 1 của trang. --}}
                    <h1 class="tieu-de-trang">{{ __('message.purchase_order') }}</h1>

                    <div class="justify-content-end">
                        <div class="btn_top_content">
                            <a type="button" class="bt btn_green btn_create">{{ __('message.create_new') }}</a>
                            <a type="button" class="bt btn_red mass-delete">{{ __('message.delete') }}</a>

                            <div class="dropdown dropdown_advanced">
                                <button class="bt btn_advanced dropdown-toggle py-1" type="button" data-bs-toggle="dropdown"
                                    aria-expanded="false">{{ __('message.advanced') }}</button>
                                <ul class="dropdown-menu">
                                    <li>
                                        <a class="dropdown-item"
                                            href="{{ route('admin.phieu-mua-hang.export', request()->query()) }}">{{ __('message.export-excel') }}</a>
                                        <a class="dropdown-item btn_print_list" type="button">{{ __('message.print') }}</a>
                                    </li>
                                </ul>
                            </div>

                            {{-- Chọn cột — lựa chọn nằm ở localStorage nên nạp lại danh sách vẫn giữ. --}}
                            <div class="dropup">
                                <button type="button" class="btn active dropbtn setting-col" href="#">
                                    <i class="fa fa-sliders" aria-hidden="true"></i>
                                    <div class="dropup-content">
                                        <div class="list_filter">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" id="show_all" checked>
                                                <label for="show_all">{{ __('message.all') }}</label>
                                            </div>
                                            @foreach ($C::COT_BANG as $ma => $ten)
                                                <div class="form-check">
                                                    <input class="form-check-input show_col" data-col="{{ $ma }}"
                                                        type="checkbox" id="show_{{ $ma }}" checked>
                                                    <label for="show_{{ $ma }}">{{ $ten }}</label>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Dải thống kê của CẢ SỔ, không phải của trang đang xem. --}}
                <span class="pmh-sum d-block m-2">
                    {{ $C::TRANG_THAI['draft'] }}: <b>{{ $so($thongKe['draft']) }}</b> ·
                    {{ $C::TRANG_THAI['approved'] }}: <b>{{ $so($thongKe['approved']) }}</b> ·
                    Đã mua: <b>{{ $tien($thongKe['purchased_amount']) }}</b> ·
                    Còn nợ NCC: <b class="money-no">{{ $tien($thongKe['debt_amount']) }}</b>
                    <em>(chưa tính phiếu lưu tạm và phiếu đã huỷ)</em>
                </span>

                <div class="list scrollDiv">
                    <div class="table-responsive table-border-style">
                        <table class="table-purchase none_mobile">
                            <tr>
                                <th class="text-center not-export"><input class="form-check-input item-select-all" type="checkbox"></th>
                                <th class="text-center">{{ __('message.stt') }}</th>
                                <th class="text-left col-code">{{ __('message.order-code') }}</th>
                                <th class="text-left col-supplier">{{ __('message.supplier') }}</th>
                                <th class="text-center col-docdate">{{ __('message.document_date') }}</th>
                                <th class="text-right col-items">{{ __('message.goods-total-money') }}</th>
                                <th class="text-right col-total">{{ __('message.total_money') }}</th>
                                <th class="text-right col-debt">{{ __('message.still_in_debt') }}</th>
                                <th class="text-left col-status">{{ __('message.order-status') }}</th>
                                <th class="text-left col-warehouse">{{ __('message.warehouse_status') }}</th>
                                <th class="text-left col-pay">{{ __('message.payment-status') }}</th>
                                <th class="text-left col-creator">{{ __('message.creator') }}</th>
                                <th class="text-left col-note">{{ __('message.note') }}</th>
                                <th class="text-center not-export">{{ __('message.action') }}</th>
                            </tr>

                            @forelse ($list as $i => $p)
                                @php
                                    $id = (int) ($p['id'] ?? 0);
                                    $tt = $p['status'] ?? 'draft';
                                    $ttTra = $p['payment_status'] ?? 'unpaid';
                                    $tong = (float) ($p['total_amount'] ?? 0);
                                    $conNo = max(0, $tong - (float) ($p['paid_amount'] ?? 0));
                                    $nhap = $tt === 'draft';
                                @endphp
                                <tr class="item" data-id="{{ $id }}" data-status="{{ $tt }}">
                                    <td class="text-center not-export">
                                        <input class="form-check-input item-select" type="checkbox" value="{{ $id }}">
                                    </td>
                                    <td class="text-center">{{ $stt + $i + 1 }}</td>
                                    <td class="text-left col-code">
                                        {{-- Bấm mã phiếu là mở phiếu, đúng lối của v2 — lưu tạm thì sửa được, đã duyệt thì chỉ xem. --}}
                                        <a type="button" data-id="{{ $id }}"
                                            class="edit_bt detail-item text-decoration-none" title="{{ __('message.detail') }}">
                                            {{ ($p['po_code'] ?? '') ?: '—' }}
                                        </a>
                                    </td>
                                    <td class="text-left col-supplier" title="{{ $p['supplier_name'] ?? '' }}">
                                        {{ ($p['supplier_name'] ?? '') ?: 'Bên bán vãng lai' }}
                                    </td>
                                    <td class="text-center col-docdate">{{ $ngay($p['document_date'] ?? null) ?: 'N/A' }}</td>
                                    <td class="text-right col-items">{{ $tien($p['items_amount'] ?? 0) }}</td>
                                    <td class="text-right col-total"><b>{{ $tien($tong) }}</b></td>
                                    <td class="text-right col-debt">
                                        @if ($conNo > 0 && $tt !== 'cancelled')
                                            <span class="money-no">{{ $tien($conNo) }}</span>
                                        @endif
                                    </td>
                                    <td class="text-left col-status">
                                        <b class="{{ $C::CHU_TRANG_THAI[$tt] ?? '' }}">{{ $C::TRANG_THAI[$tt] ?? $tt }}</b>
                                    </td>
                                    <td class="text-left col-warehouse">
                                        @if ($tt === 'approved')
                                            <b class="text-primary">{{ __('message.status-imported') }}</b>
                                        @elseif ($tt === 'draft')
                                            <b class="text-secondary">{{ __('message.not_yet_stocked') }}</b>
                                        @endif
                                    </td>
                                    <td class="text-left col-pay">
                                        @if ($tt !== 'cancelled')
                                            <b class="{{ $C::CHU_TRANG_THAI_TRA[$ttTra] ?? '' }}">
                                                {{ $C::TRANG_THAI_TRA[$ttTra] ?? $ttTra }}
                                            </b>
                                        @endif
                                    </td>
                                    <td class="text-left col-creator" title="{{ $p['created_by_name'] ?? '' }}">{{ $p['created_by_name'] ?? '' }}</td>
                                    <td class="text-left col-note item-note" title="{{ $p['note'] ?? '' }}">{{ $p['note'] ?? '' }}</td>
                                    {{-- Con mắt mở phiếu. Huỷ và Xoá chỉ hiện ở phiếu LƯU TẠM —
                                         y như v2, bên đó cột này cũng chỉ bày nút xoá khi
                                         `$item->status == 0`. Phiếu đã duyệt thì hai việc ấy
                                         không còn hợp lệ, bày ra chỉ để bấm vào rồi bị từ chối. --}}
                                    <td class="action not-export">
                                        <a class="detail-item" type="button" title="{{ __('message.detail') }}"><i class="fa fa-eye"></i></a>
                                        @if ($nhap)
                                            <a class="huy_bt cancel-item" type="button" title="{{ __('message.cancel') }}"><i class="fa fa-ban"></i></a>
                                            <a class="dele_bt delete-item" type="button" title="{{ __('message.delete') }}"><i class="fa fa-times"></i></a>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="14" class="text-center py-4">{{ $hasFilter ? 'Không có phiếu mua hàng nào khớp bộ lọc đang bật.' : $C::EMPTY_TEXT }}</td>
                                </tr>
                            @endforelse
                        </table>

                        {{-- Bản thẻ cho điện thoại: cùng dữ liệu, dựng lại lần hai — đúng như v2. --}}
                        <div class="table-purchase none_desktop">
                            <div class="d-flex align-items-center justify-content-between gap-1 p-2 border">
                                <input class="form-check-input item-select-all" type="checkbox">
                                <div class="fw-bold" style="flex: 1">{{ __('message.supplier') }}</div>
                                <div class="fw-bold">{{ __('message.total_money') }}</div>
                            </div>
                            @foreach ($list as $p)
                                @php
                                    $id = (int) ($p['id'] ?? 0);
                                    $tt = $p['status'] ?? 'draft';
                                @endphp
                                <div class="item" data-id="{{ $id }}" data-status="{{ $tt }}">
                                    <input class="form-check-input item-select" type="checkbox" value="{{ $id }}">
                                    <div class="d-flex flex-column detail-item" role="button" style="flex: 1">
                                        <span class="fw-semibold">{{ ($p['supplier_name'] ?? '') ?: 'Bên bán vãng lai' }}</span>
                                        <span style="font-size: 14px">{{ $p['po_code'] ?? '' }}</span>
                                    </div>
                                    <div class="d-flex text-right show_quantity gap-2">{{ $tien($p['total_amount'] ?? 0) }}</div>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    {{-- Dữ liệu đầy đủ của từng dòng, ĐẶT TRONG khối được thay khi nạp lại.
                         Để ở ngoài thì lọc bằng AJAX xong bảng là bảng mới mà hộp thoại vẫn
                         đọc dữ liệu của lượt tải đầu tiên. --}}
                    <script type="application/json" id="v2-rows">@json(collect($list)->keyBy('id'))</script>

                    {{-- Phân trang dựng đúng khuôn bootstrap-4 mà bản v2 in ra. --}}
                    <div class="form_pagi">
                        @include('v2::partials.pagination', ['meta' => $meta])
                    </div>
                </div>

                <select class="form-control item-per-page select-width" data-param="page_size">
                    @foreach ($C::MUC_SO_DONG as $muc)
                        <option value="{{ $muc }}" {{ $filters['page_size'] == $muc ? 'selected' : '' }}>
                            {{ __('message.display', ['name' => $muc]) }}
                        </option>
                    @endforeach
                </select>
            </div>
        </div>
    </div>

    {{-- ===================== Hộp lập / sửa phiếu =====================
         Ba khối xếp dọc, đúng thứ tự của màn create bên v2:
           1. Hàng nút Lưu tạm / Duyệt nằm TRÊN CÙNG, canh phải.
           2. Thông tin phiếu: 4 cột × 4 ô. Cột 1–2 là hồ sơ bên bán (tự điền nên
              khoá), cột 4 là thông tin phiếu do hệ thống đặt (cũng khoá).
           3. Thông tin hàng hoá: nhóm hàng + ô tìm hàng + dropdown Nâng cao, rồi lưới.
           4. Khối tiền canh phải + ảnh chứng từ.

         Chiết khấu và số tiền đã trả KHÔNG nằm ở đây — bên v2 hai ô ấy cũng bị gỡ
         khỏi màn lập phiếu, tiền trả đi qua hộp "Thanh toán" riêng. --}}
    <div class="modal" id="modalCreate" data-bs-keyboard="false">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable mx-auto" style="min-width: 90%;">
            <div class="modal-content">
                <div class="modal-header">
                    <h4 class="modal-title">{{ __('message.create_purchase_order') }}</h4>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body pt-0" id="content_create">
                    {{-- 1. Thanh thao tác — đúng `card-header` của v2: mã phiếu và tên
                         bên bán nằm trái, cụm nút nằm phải. Lập mới thì bên trái để
                         trống vì chưa có mã nào để gọi tên.

                         Nút nào hiện là do moPhieu() quyết theo cờ API trả về, không
                         phải do đây: cùng một hộp thoại dùng cho ba cảnh — lập mới,
                         sửa phiếu lưu tạm, và xem phiếu đã duyệt. --}}
                    <div class="pmh-thanh">
                        <h4 class="pmh-ma-phieu"></h4>
                        <div class="pmh-thanh-nut">
                            {{-- HAI bộ nút, đúng như v2: bên đó `create.blade.php` và
                                 `edit.blade.php` là hai khuôn khác nhau nên thanh nút cũng
                                 khác. Lập mới thì Lưu tạm / Duyệt; mở phiếu đã lưu thì
                                 Duyệt vàng / Lưu / In / Xuất Excel. moPhieu() bật đúng một
                                 bộ. Xoá và Huỷ không nằm đây — v2 để ở cột Hành động. --}}
                            <button type="button" class="bt btn_gray save-order pmh-nut-moi" data-duyet="0">
                                {{ __('message.status-temporary') }}
                            </button>
                            <button type="button" class="bt btn_green save-order pmh-nut-moi" data-duyet="1">
                                {{ __('message.approve') }}
                            </button>

                            <button type="button" class="bt btn btn-warning save-order pmh-nut-sua d-none" data-duyet="1">
                                {{ __('message.approve') }}
                            </button>
                            <button type="button" class="bt btn_green save-order pmh-nut-sua d-none" data-duyet="0">
                                {{ __('message.save') }}
                            </button>
                            <button type="button" class="bt btn btn-primary pmh-tra d-none">
                                {{ __('message.payment') }}
                            </button>
                            <button type="button" class="bt btn btn-print pmh-in d-none">
                                <i class="fa-solid fa-print"></i> {{ __('message.print') }}
                            </button>
                            <button type="button" class="bt btn btn-success pmh-excel d-none">
                                <i class="fa-solid fa-file-excel"></i> {{ __('message.export-excel') }}
                            </button>
                        </div>
                    </div>

                    {{-- 2. Thông tin phiếu: 4 cột × 4 ô như v2.
                         Mọi nhãn cao bằng nhau và mọi ô cao bằng nhau (xem .pmh-o
                         trong khối style), nên dòng thứ n của bốn cột nằm đúng một
                         hàng — kể cả khi một cột bị giấu bớt ô vì thuế trực tiếp. --}}
                    <div class="pmh-form">
                        {{-- Cột 1 — hồ sơ bên bán, tự điền theo nhà cung cấp nên khoá lại. --}}
                        <div class="pmh-cot">
                            <div class="pmh-o">
                                <label class="form-label" for="pmhNCC">
                                    {{ __('message.supplier') }} <span class="required">*</span>
                                </label>
                                <div class="pmh-o-ncc">
                                    <select id="pmhNCC" name="supplier_id" class="form-control supplier_id">
                                        <option value="0">{{ __('message.select-supplier') }}</option>
                                        @foreach ($nhaCungCap as $ncc)
                                            <option value="{{ $ncc['id'] }}"
                                                data-name="{{ $ncc['name'] ?? '' }}"
                                                data-phone="{{ $ncc['phone'] ?? '' }}"
                                                data-address="{{ $ncc['address'] ?? '' }}"
                                                data-address-2="{{ $ncc['address_line2'] ?? '' }}"
                                                data-rep-phone="{{ $ncc['representative_phone'] ?? '' }}">
                                                {{ ($ncc['code'] ?? '') ? $ncc['code'] . ' - ' : '' }}{{ $ncc['name'] ?? '' }}
                                            </option>
                                        @endforeach
                                    </select>
                                    <button type="button" class="pmh-nut-vuong add_supplier"
                                        title="{{ __('message.add') }} {{ Str::lower(__('message.supplier')) }}">
                                        <i class="fa fa-plus"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="pmh-o">
                                <label class="form-label">{{ __('message.address') }}</label>
                                <input type="text" name="address" class="form-control" disabled>
                            </div>
                            <div class="pmh-o">
                                <label class="form-label">{{ __('message.address_2') }}</label>
                                <input type="text" name="address_line2" class="form-control" disabled>
                            </div>
                            <div class="pmh-o">
                                <label class="form-label">{{ __('message.mobile') }}</label>
                                <input type="text" name="mobile" class="form-control" disabled>
                            </div>
                        </div>

                        {{-- Cột 2 --}}
                        <div class="pmh-cot">
                            <div class="pmh-o">
                                <label class="form-label">{{ __('message.contact_phone') }}</label>
                                <input type="text" name="contact_person_phone" class="form-control" disabled>
                            </div>
                            <div class="pmh-o">
                                <label class="form-label">{{ __('message.document_date') }}</label>
                                <input type="text" readonly name="document_date"
                                    class="form-control ip-ngay document_date">
                            </div>
                            <div class="pmh-o">
                                <label class="form-label">{{ __('message.expiry_date') }}</label>
                                {{-- Để TRỐNG, người lập tự chọn: hẹn giao là thoả thuận với bên
                                     bán, đoán hộ một ngày là ghi vào chứng từ một lời hứa không
                                     ai đưa ra. --}}
                                <input type="text" readonly name="expected_date" class="form-control ip-ngay">
                            </div>
                            <div class="pmh-o">
                                <label class="form-label">{{ __('message.note') }}</label>
                                <input type="text" maxlength="500" name="note" class="form-control note">
                            </div>
                        </div>

                        {{-- Cột 3 --}}
                        <div class="pmh-cot">
                            <div class="pmh-o">
                                <label class="form-label">{{ __('message.purchase_staff') }}</label>
                                <select name="purchaser_id" class="form-control purchaser_id">
                                    <option value="0">— Chưa phân công —</option>
                                    @foreach ($nhanVien as $nv)
                                        <option value="{{ $nv['id'] }}">
                                            {{ ($nv['code'] ?? '') ? $nv['code'] . ' - ' : '' }}{{ $nv['full_name'] ?? ($nv['name'] ?? '') }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="pmh-o">
                                <label class="form-label">{{ __('message.supplier_delivery_note') }}</label>
                                <input type="text" maxlength="50" name="supplier_delivery_code" class="form-control">
                            </div>
                            {{-- Hai ô thuế: hộ kinh doanh nộp thuế trực tiếp thì không có
                                 đường VAT nào để khai, bày ra chỉ mời gõ nhầm. --}}
                            <div class="pmh-o o-vat-phieu {{ $thueTrucTiep ? 'd-none' : '' }}">
                                <label class="form-label">{{ __('message.vat') }}</label>
                                <div class="pmh-o-donvi">
                                    {{-- −1 = KCT, −2 = KKKNT: hai MÃ thuế, không phải phần trăm. --}}
                                    <input type="number" name="vat_percent" class="form-control vat_percent"
                                        min="-2" max="100" step="1" value="0"
                                        title="Thuế suất áp cho cả phiếu. -1 = KCT, -2 = KKKNT">
                                    <span class="pmh-o-donvi-chu vat-suffix">%</span>
                                </div>
                            </div>
                            <div class="pmh-o {{ $thueTrucTiep ? 'd-none' : '' }}">
                                <label class="form-label">Cách khai thuế</label>
                                <select name="vat_mode" class="form-control vat_mode">
                                    @foreach ($C::KIEU_VAT as $ma => $ten)
                                        <option value="{{ $ma }}">{{ $ten }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        {{-- Cột 4 — thông tin phiếu do hệ thống đặt, khoá lại. --}}
                        <div class="pmh-cot">
                            <div class="pmh-o">
                                <label class="form-label">{{ __('message.created_po_by') }}</label>
                                <input type="text" name="created_by_name" class="form-control" disabled
                                    value="{{ session('api.user.full_name') ?? '' }}">
                            </div>
                            <div class="pmh-o">
                                <label class="form-label">{{ __('message.created_po_at') }}</label>
                                <input type="text" name="created_date" class="form-control" disabled>
                            </div>
                            <div class="pmh-o">
                                <label class="form-label">{{ __('message.document_type') }}</label>
                                <input type="text" class="form-control" disabled value="{{ __('message.purchase_order') }}">
                            </div>
                            <div class="pmh-o">
                                <label class="form-label">{{ __('message.status') }}</label>
                                <input type="text" name="po_status" class="form-control" disabled
                                    value="{{ __('message.create_new') }}">
                            </div>
                        </div>
                    </div>

                    {{-- 3. Thông tin hàng hoá — tiêu đề bên trái, bộ công cụ bên phải,
                         cùng một hàng và cùng chiều cao với nhau. --}}
                    <div class="content_midd_title pmh-thanh-hang">
                        <h4>{{ __('message.goods-information') }}</h4>
                        <div class="pmh-hang-cong-cu">
                            {{-- Chỉ những nhóm ĐANG CÓ hàng mua được; nhóm rỗng bày ra
                                 chỉ để người dùng chọn vào rồi gặp bảng trắng. --}}
                            <select class="form-control select-categories" {{ $nhomHong || $nhomRong ? 'disabled' : '' }}>
                                <option value="">
                                    @if ($nhomHong)
                                        Không đọc được nhóm hàng
                                    @elseif ($nhomRong)
                                        Chưa nhóm nào có hàng
                                    @else
                                        {{ __('message.select-menu-group') }}
                                    @endif
                                </option>
                                @foreach ($nhomHang ?? [] as $nh)
                                    <option value="{{ $nh['id'] }}">
                                        {{ $nh['name'] ?? '' }} ({{ $nh['so_mat_hang'] ?? 0 }})
                                    </option>
                                @endforeach
                            </select>
                            {{-- Ô rỗng bắt buộc: select2 một lựa chọn chỉ hiện được
                                 placeholder khi thẻ gốc có sẵn một option trống. --}}
                            <select class="form-control select-menus"><option></option></select>
                            <div class="dropdown menu-advanced-dropdown">
                                <button class="bt btn_advanced dropdown-toggle" type="button"
                                    data-bs-toggle="dropdown" aria-expanded="false">{{ __('message.advanced') }}</button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    <li><a class="dropdown-item export-product" type="button">{{ __('message.export-excel') }}</a></li>
                                    <li><a class="dropdown-item download-sample" type="button">{{ __('message.download_sample_file') }}</a></li>
                                    <li>
                                        <a class="dropdown-item import-product" type="button">{{ __('message.import_file') }}</a>
                                        <input type="file" class="d-none line_file" accept=".csv,text/csv">
                                    </li>
                                </ul>
                            </div>
                        </div>
                    </div>

                    <p id="import_error" class="text-danger mb-2" style="display:none;"></p>

                    <div class="pmh-luoi">
                        {{-- Cờ thuế trực tiếp gắn ngay ở đây chứ không đợi JS: hàng tiêu đề
                             nằm sẵn trong trang, để JS gắn thì có một nhịp ba cột VAT hiện
                             ra rồi mới biến mất. --}}
                        <table class="table-product {{ $thueTrucTiep ? 'is-thue-truc-tiep' : '' }}">
                            <tbody>
                                <tr>
                                    {{-- Nhãn cột phải canh CÙNG BÊN với nội dung bên dưới nó:
                                         tiêu đề canh giữa mà con số canh phải thì mỗi cột lệch một
                                         kiểu, đọc cả hàng như răng cưa. --}}
                                    <th class="la-giua c-stt">{{ __('message.stt') }}</th>
                                    <th class="la-chu c-ma">{{ __('message.menu-code') }}</th>
                                    <th class="la-chu c-ten">{{ __('message.menu-name') }}</th>
                                    <th class="la-chu c-dv">{{ __('message.unit') }}</th>
                                    <th class="la-so c-sl">{{ __('message.quantity') }}</th>
                                    <th class="la-so c-gia">{{ __('message.import_price') }}</th>
                                    <th class="la-so c-tien">
                                        {{ $thueTrucTiep ? __('message.money_into') : __('message.subtotal_before_vat') }}
                                    </th>
                                    <th class="la-so col-vat c-vat">% {{ __('message.vat') }}</th>
                                    <th class="la-so col-vat-money c-vat-tien">{{ __('message.pch_ord_VAT') }}</th>
                                    <th class="la-so col-sau-vat c-tong">{{ __('message.total_amount_after_vat') }}</th>
                                    <th class="la-chu c-lo">
                                        {{ __('message.batch_number') }} <span class="required">*</span>
                                    </th>
                                    <th class="la-giua c-han">
                                        {{ __('message.expiry_date') }} <span class="required">*</span>
                                    </th>
                                    <th class="la-giua c-xoa not-export"></th>
                                </tr>
                            </tbody>
                            <tbody class="list-menu"></tbody>
                        </table>
                    </div>
                    <p class="text-center text-secondary py-3 mb-0 lines-empty">
                        Chưa có dòng hàng nào. Chọn ở ô tìm hàng hóa phía trên để thêm.
                    </p>

                    {{-- 4. Dải cuối: ảnh chứng từ bên trái, khối tiền bên phải — cùng
                         một hàng. Xếp dọc thì khối tiền canh phải rồi ô ảnh canh trái ở
                         dưới, đáy hộp thoại nhìn như hai mảnh rời. --}}
                    {{-- Khối tiền dựng ĐÚNG HÌNH của v2 (`wrapper-money-into`): hai hàng
                         canh phải, mỗi ô là một nhãn đậm bên trái và một ô CHỈ ĐỌC bên
                         phải. Hàng trên hai ô — chưa VAT và tiền thuế; hàng dưới một ô
                         Tổng tiền đứng riêng. --}}
                    <div class="col-12 p-3 wrapper-money-into">
                        <div class="row justify-content-end">
                            <div class="col-12 col-lg-5 {{ $thueTrucTiep ? 'd-none' : '' }}">
                                <div class="row">
                                    <div class="col-12 col-md-5">
                                        <label class="form-label mt-2"><b>{{ __('message.total_subtotal_before_vat') }}</b></label>
                                    </div>
                                    <div class="col-12 col-md-7">
                                        <input type="text" class="form-control tong-tien-hang" value="0₫" readonly>
                                    </div>
                                </div>
                            </div>
                            <div class="col-12 col-lg-4 {{ $thueTrucTiep ? 'd-none' : '' }}">
                                <div class="row">
                                    <div class="col-12 col-md-5">
                                        <label class="form-label mt-2"><b>{{ __('message.total_vat_amount') }}</b></label>
                                    </div>
                                    <div class="col-12 col-md-7">
                                        <input type="text" class="form-control tong-thue" value="0₫" readonly>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="row justify-content-end my-lg-3">
                            <div class="col-12 col-lg-4">
                                <div class="row">
                                    <div class="col-12 col-md-5">
                                        <label class="form-label mt-2"><b>{{ __('message.total_money') }}</b></label>
                                    </div>
                                    <div class="col-12 col-md-7">
                                        <input type="text" class="form-control tong-cong" value="0₫" readonly>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- Chỉ hiện khi xem phiếu ĐÃ DUYỆT: công nợ và lịch sử phiếu là
                         chuyện đã xảy ra, phiếu đang lập thì chưa có gì để kể. --}}
                    <div class="col-12 px-3 pmh-lichsu d-none"></div>

                    {{-- Ảnh chứng từ bên bán. v2 có ô này nhưng để trong khối đã chú thích
                         lại, nên bên đó không hiện; ở đây giữ vì đường tải ảnh đã chạy và
                         phiếu đang dùng nó. Đặt tách dưới cùng, không chen vào khối tiền. --}}
                    <div class="col-12 px-3 pb-3 d-flex align-items-center gap-2 flex-wrap">
                        <label class="form-label mb-0">{{ __('message.attachment') }}</label>
                        <label class="bt btn_gray mb-0">
                            <span class="anh-nut-chu">{{ __('message.upload') }}</span>
                            <input type="file" class="d-none anh_file" accept="image/*">
                        </label>
                        <a class="anh-xem d-none" href="#" target="_blank" rel="noopener">{{ __('message.image') }}</a>
                        <button type="button" class="bt btn_red anh-go d-none">{{ __('message.delete') }}</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- ===================== Hộp thêm nhanh nhà cung cấp =====================
         Cùng bộ ô với màn Nhà cung cấp. Khác đúng một chỗ: lưu xong KHÔNG quay về
         danh sách mà nhét bên vừa khai thẳng vào ô chọn của phiếu đang gõ dở —
         đá người dùng sang trang khác là mất trắng lưới hàng. --}}
    <div class="modal" id="modalCreateSupplier" data-bs-keyboard="false" data-bs-backdrop="static">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-xl mx-auto">
            <div class="modal-content">
                <div class="modal-header">
                    <h4 class="modal-title">{{ __('message.add') }} {{ Str::lower(__('message.supplier')) }}</h4>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="modal_center">
                        <input type="hidden" class="ip_image">
                        <div class="row">
                            <div class="col-sm-3 d-flex align-items-start justify-content-center">
                                <div class="img_st w-100">
                                    <label>{{ __('message.image') }}</label>
                                    <div class="d-flex justify-content-center">
                                        <div class="pic_add">
                                            <img id="img-preview-ncc" class="mx-auto" src="{{ $anhMacDinh }}">
                                        </div>
                                    </div>
                                    <div class="upload_pic">
                                        {{ __('message.upload') }}
                                        <input type="file" class="ip_img" accept="image/*">
                                    </div>
                                    <div class="mt-2 text-center">
                                        <label class="form-label d-block">{{ __('message.status') }}</label>
                                        <input type="checkbox" class="switch_customer ip_status" checked>
                                    </div>
                                </div>
                            </div>

                            <div class="col-sm-9 ps-sm-0 row mx-auto" style="color: #212529;">
                                <div class="col-12 col-md-6 col-xl-4 mt-3">
                                    <label class="form-label">{{ __('message.supplier-code') }}</label>
                                    <input type="text" class="form-control ip_code" maxlength="30"
                                        placeholder="{{ __('message.auto-increment-code') }}">
                                </div>
                                <div class="col-12 col-md-6 col-xl-4 mt-3">
                                    <label class="form-label">{{ __('message.supplier-name') }} <span style="color:red">*</span></label>
                                    <input type="text" class="form-control ip_name" maxlength="150">
                                </div>
                                <div class="col-12 col-md-6 col-xl-4 mt-3">
                                    <label class="form-label">{{ __('message.short_name') }}</label>
                                    <input type="text" class="form-control ip_short_name" maxlength="100">
                                </div>
                                <div class="col-12 col-md-6 col-xl-4 mt-3">
                                    <label class="form-label">{{ __('message.phone') }}</label>
                                    <input type="text" class="form-control ip_phone" maxlength="20">
                                </div>
                                <div class="col-12 col-md-6 col-xl-4 mt-3">
                                    <label class="form-label">{{ __('message.email') }}</label>
                                    <input type="email" class="form-control ip_email" maxlength="191">
                                </div>
                                <div class="col-12 col-md-6 col-xl-4 mt-3">
                                    <label class="form-label">{{ __('message.tax_code') }}</label>
                                    <input type="text" class="form-control ip_tax_code" maxlength="30">
                                </div>
                                <div class="col-12 col-md-6 col-xl-4 mt-3">
                                    <label class="form-label">{{ __('message.representative_info') }}</label>
                                    <input type="text" class="form-control ip_representative_name" maxlength="150">
                                </div>
                                <div class="col-12 col-md-6 col-xl-4 mt-3">
                                    <label class="form-label">{{ __('message.representative_phone') }}</label>
                                    <input type="text" class="form-control ip_representative_phone" maxlength="20">
                                </div>
                                <div class="col-12 mt-3">
                                    <label class="form-label d-block">{{ __('message.address') }} <span style="color:red">*</span></label>
                                    <div class="box-textarea-cus">
                                        <textarea class="form-control ip_address" maxlength="255" style="height: 70px"></textarea>
                                    </div>
                                </div>
                                <div class="col-12 mt-3">
                                    <label class="form-label d-block">{{ __('message.address_2') }}</label>
                                    <div class="box-textarea-cus">
                                        <textarea class="form-control ip_address_line2" maxlength="200" style="height: 70px"></textarea>
                                    </div>
                                </div>
                                <div class="col-12 mt-3">
                                    <label class="form-label d-block">{{ __('message.note') }}</label>
                                    <div class="box-textarea-cus">
                                        <textarea class="form-control ip_note" maxlength="500" style="height: 70px"></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer justify-content-center">
                    <button type="button" class="bt btn_red" data-bs-dismiss="modal">{{ __('message.close') }}</button>
                    <button type="button" class="bt btn_green save-supplier">{{ __('message.save') }}</button>
                </div>
            </div>
        </div>
    </div>

    {{-- ===================== Hộp ghi nhận thanh toán ===================== --}}
    <div class="modal" id="modalPayment">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable" style="min-width: 50%;">
            <div class="modal-content">
                <div class="modal-header">
                    <h4 class="modal-title text-uppercase">{{ __('message.payment') }}</h4>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="text-secondary pay_info"></p>

                    {{-- Ghi nợ đứng riêng một dòng trên cùng, đúng như v2: nó là công tắc
                         đổi cả hình dạng của hộp này chứ không phải một ô như mấy ô kia. --}}
                    <div class="d-flex align-items-center mb-3">
                        <input type="checkbox" id="payGhiNo" class="form-check-input mt-0 me-2 pay_debt">
                        <label for="payGhiNo" class="form-label mb-0 fw-semibold">
                            {{ __('message.debt_record') }}
                        </label>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold" for="payHinhThuc">
                                {{ __('message.payment-method') }} <span class="required">*</span>
                            </label>
                            <select id="payHinhThuc" class="form-control pay_method">
                                <option value="cash">{{ __('message.cash') }}</option>
                                <option value="transfer">{{ __('message.transfer') }}</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold" for="payTong">{{ __('message.total_money') }}</label>
                            <input type="text" id="payTong" class="form-control pay_total" disabled>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold" for="paySoTien">
                                {{ __('message.payment_amount') }} <span class="required">*</span>
                            </label>
                            <input type="text" id="paySoTien" class="form-control pay_amount" inputmode="numeric">
                        </div>

                        {{-- Hạn nợ = số ngày + ngày cụ thể, hai ô kẹp nhau như v2. Gõ số
                             ngày thì ngày tự nhảy theo, và ngược lại. --}}
                        <div class="col-md-6 mb-3 pay-khoi-no d-none">
                            <label class="form-label fw-semibold" for="payHanNgay">
                                {{ __('message.due_date') }} <span class="required">*</span>
                            </label>
                            <div class="d-flex align-items-center gap-2">
                                <div class="d-flex align-items-center">
                                    <input type="number" min="1" max="365" value="30"
                                        class="form-control text-center pay_debt_days" style="width: 72px;">
                                    <span class="ms-2">{{ __('message.date') }}</span>
                                </div>
                                <input type="text" id="payHanNgay" readonly
                                    class="form-control ip-ngay pay_debt_date flex-grow-1">
                            </div>
                        </div>

                        <div class="col-md-6 mb-3 pay-khoi-no d-none">
                            <label class="form-label fw-semibold" for="payNguoi">
                                {{ __('message.representative') }} <span class="required">*</span>
                            </label>
                            <input type="text" id="payNguoi" maxlength="150" class="form-control pay_contact_name">
                        </div>
                        <div class="col-md-6 mb-3 pay-khoi-no d-none">
                            <label class="form-label fw-semibold" for="payDienThoai">
                                {{ __('message.phone') }} <span class="required">*</span>
                            </label>
                            <input type="text" id="payDienThoai" maxlength="30" class="form-control pay_contact_phone"
                                placeholder="{{ __('message.enter_phone') }}">
                        </div>

                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold">{{ __('message.attachment') }}</label>
                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                <label class="bt btn_gray mb-0">
                                    <span class="pay-anh-chu">{{ __('message.upload') }}</span>
                                    <input type="file" class="d-none pay_file" accept="image/*">
                                </label>
                                <a class="pay-anh-xem d-none" href="#" target="_blank" rel="noopener">
                                    {{ __('message.image') }}
                                </a>
                                <button type="button" class="bt btn_red pay-anh-go d-none">
                                    {{ __('message.delete') }}
                                </button>
                            </div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold" for="payGhiChu">{{ __('message.note') }}</label>
                            <input type="text" id="payGhiChu" maxlength="500" class="form-control pay_note"
                                placeholder="{{ __('message.input_note') }}">
                        </div>
                    </div>

                    <small class="text-secondary">
                        Số tiền thanh toán là số LUỸ KẾ đã trả cho phiếu, không phải số vừa trả thêm.
                    </small>
                </div>
                <div class="modal-footer justify-content-center">
                    <button type="button" class="bt btn_red" data-bs-dismiss="modal">{{ __('message.close') }}</button>
                    <button type="button" class="bt btn_green save-payment">{{ __('message.payment') }}</button>
                </div>
            </div>
        </div>
    </div>

    {{-- ===================== Hộp huỷ phiếu ===================== --}}
    <div class="modal" id="modalCancel">
        <div class="modal-dialog modal-dialog-centered" style="min-width: 30%;">
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title">{{ __('message.cancel') }} {{ Str::lower(__('message.purchase_order')) }}</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="text-secondary cancel_info"></p>
                    <label class="form-label">{{ __('message.reason') }} <span style="color:red">*</span></label>
                    <textarea class="form-control cancel_note p-2" rows="3" maxlength="500"
                        placeholder="VD: nhà cung cấp báo hết hàng"></textarea>
                    <small class="text-secondary">Vài tuần sau không ai nhớ vì sao phiếu chết — lý do nằm lại trong lịch sử phiếu.</small>
                </div>
                <div class="modal-footer justify-content-center">
                    <button type="button" class="bt btn_red" data-bs-dismiss="modal">{{ __('message.close') }}</button>
                    <button type="button" class="bt btn_red save-cancel">{{ __('message.cancel') }}</button>
                </div>
            </div>
        </div>
    </div>


    {{-- ===================== Hộp xoá phiếu ===================== --}}
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
                            <div class="col text-center">
                                <label class="form-label">{{ __('message.delete-confirm') }}</label>
                                <p class="text-secondary mb-0">
                                    Chỉ phiếu lưu tạm mới xoá được — phiếu đã duyệt nằm lại trong sổ vì kho
                                    đã đổi theo nó.
                                </p>
                            </div>
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
@endsection

@push('scripts')
    <script>
        const CSRF = '{{ csrf_token() }}';
        const URL_BASE = @json(url('/admin/purchase-orders'));
        const URL_STORE = @json(route('admin.phieu-mua-hang.store'));
        const URL_MAT_HANG = @json(route('admin.phieu-mua-hang.matHang'));
        const URL_ANH = @json(route('admin.phieu-mua-hang.anh'));
        const URL_NCC_NHANH = @json(route('admin.phieu-mua-hang.themNhanhNCC'));
        const URL_NCC_ANH = @json(route('admin.nha-cung-cap.anh'));
        const URL_BULK_DEL = @json(route('admin.phieu-mua-hang.bulkDestroy'));
        const ANH_MAC_DINH = @json($anhMacDinh);

        // Hộ kinh doanh nộp thuế trực tiếp — bản v2 gọi là admin('tax_type')=='direct'.
        // Bật ở Cài đặt → Cấu hình cửa hàng → Chế độ thuế.
        const THUE_TRUC_TIEP = @json($thueTrucTiep);

        const NHAN_TRANG_THAI = @json(\App\Http\Controllers\PhieuMuaHangController::TRANG_THAI);
        const CHU_TRANG_THAI = @json(\App\Http\Controllers\PhieuMuaHangController::CHU_TRANG_THAI);
        const NHAN_TRA = @json(\App\Http\Controllers\PhieuMuaHangController::TRANG_THAI_TRA);
        const CHU_TRA = @json(\App\Http\Controllers\PhieuMuaHangController::CHU_TRANG_THAI_TRA);

        // Cả bản ghi của từng dòng — hộp Chi tiết / Duyệt / Xoá đọc thẳng ở đây, khỏi
        // rải hơn chục data-* lên mỗi <tr>. Đọc lại sau mỗi lượt nạp danh sách bằng
        // AJAX, không thì dữ liệu là của bảng đã bị thay đi.
        let ROWS = docDongHienCo();

        function docDongHienCo() {
            try {
                return JSON.parse(document.getElementById('v2-rows').textContent) || {};
            } catch (e) {
                return {};
            }
        }

        const cuaDong = (el) => ROWS[$(el).closest('.item').data('id')];

        // ---------- Tiện ích chung ----------
        const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) =>
            ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
        const tien = (v) => (Number(v) || 0).toLocaleString('vi-VN') + '₫';
        const nhomSo = (v) => (Number(v) || 0).toLocaleString('vi-VN');
        const soN = (v) => {
            const n = Number(String(v == null ? '' : v).replace(/[^\d-]/g, ''));
            return Number.isFinite(n) ? n : 0;
        };
        const ngay = (s) => {
            if (!s) return '';
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
        // VAT âm là MÃ thuế đặc biệt của mặt hàng, không phải phần trăm.
        const nhanVat = (v) => (v === -1 ? 'KCT' : v === -2 ? 'KKKNT' : (Number(v) || 0) + '%');

        /**
         * Con trỏ trong ô tiền đếm TỪ PHẢI SANG.
         *
         * Ô tiền được chấm lại dấu nghìn sau mỗi phím, nên vị trí tính từ trái nhảy
         * một bước mỗi lần chuỗi dài thêm một dấu chấm — gõ số hàng triệu là con trỏ
         * tự bò về đầu. Ô số không có vùng chọn nên bọc lại, null nghĩa là "để yên".
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

        /** Mọi thao tác ghi đi bằng form POST ẩn: trang tải lại và toast do session bắn ra. */
        function postForm(action, method, fields) {
            const $f = $('<form>', { method: 'POST', action, css: { display: 'none' } });
            const them = (n, v) => $f.append($('<input>', { type: 'hidden', name: n, value: v == null ? '' : v }));
            them('_token', CSRF);
            if (method && method !== 'POST') them('_method', method);
            them('return', location.pathname + location.search);
            $.each(fields || {}, (k, v) => {
                Array.isArray(v) ? v.forEach((x) => them(k + '[]', x)) : them(k, v);
            });
            $('body').append($f);
            $f.trigger('submit');
        }

        // =====================================================================
        //  Bộ lọc — đổi ô nào là lọc lại ngay, gõ thì chờ 400ms
        // =====================================================================
        //
        // Tự dựng URL thay vì submit form: trên điện thoại khung v2 BƯNG khối lọc
        // sang tấm offcanvas, và mỗi lượt chỉ bưng MỘT khối, nên submit lúc đó sẽ
        // đánh rơi mấy ô còn lại. Ô lọc nằm ở đâu cũng tìm ra vì khung bên trái và
        // tấm offcanvas cùng mang class .fillter-box.
        const oLoc = (ten) => $('.fillter-box [name="' + ten + '"]');

        function locLai() {
            const q = new URLSearchParams();

            ['keyword', 'supplier_id'].forEach((ten) => {
                const v = String(oLoc(ten).val() || '').trim();
                if (v) q.set(ten, v);
            });

            // Hai ô ngày LUÔN được gửi, kể cả khi rỗng: máy chủ mặc định lọc tháng
            // này khi KHÔNG thấy tham số, nên bỏ qua ô rỗng là bấm "Mọi thời gian"
            // xong bảng vẫn chỉ có tháng này — xem PhieuMuaHangController::filters.
            ['from_date', 'to_date'].forEach((ten) => {
                q.set(ten, String(oLoc(ten).val() || '').trim());
            });
            ['status[]', 'payment_status[]', 'warehouse_status[]'].forEach((ten) => {
                oLoc(ten).filter(':checked').each(function () { q.append(ten, this.value); });
            });

            // Cỡ trang và kiểu sắp xếp không có ô trong khung lọc nhưng phải giữ,
            // không thì đổi một ô tick là bảng nhảy về 20 dòng mới nhất. Cố ý KHÔNG
            // mang `page`: trang 5 của bộ lọc cũ không còn nghĩa gì.
            const cu = new URLSearchParams(location.search);
            ['page_size', 'sort'].forEach((ten) => {
                if (cu.get(ten)) q.set(ten, cu.get(ten));
            });

            V2.napLai(location.pathname + '?' + q);
        }

        let timerTim = null;
        $(document).on('change', '.fillter-box select, .fillter-box input[type="checkbox"]', locLai);
        $(document).on('input', '.fillter-box input[name="keyword"]', function () {
            clearTimeout(timerTim);
            timerTim = setTimeout(locLai, 400);
        });

        $(document).on('submit', '#search-form', function (e) {
            e.preventDefault();
            locLai();
        });

        // =====================================================================
        //  Hai ô ngày — lịch một ngày, khuôn DD-MM-YYYY, cùng bộ của v2
        // =====================================================================
        const KHUON_NGAY = 'DD-MM-YYYY';
        const homNay = moment().endOf('day');

        /** Đọc một ô ngày ra moment; rỗng hoặc gõ sai khuôn thì trả null. */
        const docNgay = ($o) => {
            const v = String($o.val() || '').trim();
            if (!v) return null;
            const m = moment(v, KHUON_NGAY, true);

            return m.isValid() ? m : null;
        };

        const lich = (sel) => $(sel).data('daterangepicker');

        /**
         * Kẹp hai ô vào nhau: "từ" không quá "đến", "đến" không dưới "từ", và cả
         * hai không quá hôm nay.
         *
         * Bản v2 cũng gán minDate/maxDate chéo như vậy nhưng chỉ gán MỘT LẦN ngay
         * trong lượt chọn, và gán nguyên moment còn cả giờ phút — nên ranh giới
         * lúc ăn lúc không. Ở đây gọi lại sau MỌI lượt đổi, và chuẩn hoá về đầu /
         * cuối ngày để đúng cái ngày biên vẫn bấm được.
         */
        function ketNgay() {
            const tu = docNgay($('#from_date'));
            const den = docNgay($('#to_date'));

            // Khoảng ngược (chỉ tới được bằng đường dẫn gõ tay): KHÔNG kẹp chéo.
            // Kẹp thì hai lịch có minDate lớn hơn maxDate và mọi ngày đều xám —
            // người dùng nhìn thấy khoảng sai mà không bấm được gì để chữa.
            const nguoc = tu && den && tu.isAfter(den, 'day');

            const pTu = lich('#from_date');
            const pDen = lich('#to_date');
            if (pTu) pTu.maxDate = (den && !nguoc) ? den.clone().endOf('day') : homNay.clone();
            if (pDen) {
                pDen.minDate = (tu && !nguoc) ? tu.clone().startOf('day') : null;
                pDen.maxDate = homNay.clone();
            }
        }

        // Hai ô này gõ tay được. Bản v2 nghe `change` để lọc lại; ở đây nghe thêm
        // để soát khuôn — giữ một chuỗi máy chủ không hiểu là bảng lọc theo một
        // khoảng khác hẳn cái đang hiện trên ô.
        $(document).on('change', '#from_date, #to_date', function () {
            const v = String(this.value || '').trim();
            if (v && !moment(v, KHUON_NGAY, true).isValid()) {
                this.value = '';
                toastr.error('Ngày phải gõ theo khuôn ' + KHUON_NGAY + '.');
            }
            ketNgay();
            locLai();
        });

        $('#from_date, #to_date').each(function () {
            $(this).daterangepicker({
                singleDatePicker: true,
                showDropdowns: true,
                autoUpdateInput: false,
                autoApply: true,
                // Không phiếu nào lập vào ngày mai. Cho chọn ngày tương lai chỉ đẻ
                // ra một khoảng lọc không bao giờ có dòng nào.
                maxDate: homNay,
                locale: V2.lichVN(),
            }, function (start) {
                const laTu = this.element.attr('id') === 'from_date';
                $(this.element).val(start.format(KHUON_NGAY));

                // Chọn ra một khoảng ngược thì KÉO ô kia theo thay vì để nguyên:
                // bảng rỗng mà hai ô vẫn nói một khoảng có vẻ hợp lệ là kiểu bí
                // nhất — người dùng không biết mình vừa làm gì sai.
                const tu = docNgay($('#from_date'));
                const den = docNgay($('#to_date'));
                if (tu && den && tu.isAfter(den, 'day')) {
                    $(laTu ? '#to_date' : '#from_date').val(start.format(KHUON_NGAY));
                }

                ketNgay();
                locLai();
            });
        });

        // Mở trang bằng một đường dẫn đã mang sẵn ngày: kẹp lại ngay từ đầu, không
        // đợi tới lượt chọn tiếp theo.
        ketNgay();

        // =====================================================================
        //  Chọn cột — giữ ở localStorage, tắt bằng CSS nên nạp lại AJAX vẫn còn
        // =====================================================================
        const COT_KEY = 'pmh-v2-cot-an';

        function apDungCot() {
            const $cbs = $('.show_col');
            const an = $cbs.filter((i, el) => !el.checked).map((i, el) => String($(el).data('col'))).get();

            document.getElementById('cotAnCss').textContent = an.length
                ? an.map((c) => '.table-purchase .col-' + c).join(',') + '{display:none}'
                : '';

            $('#show_all')
                .prop('checked', an.length === 0)
                .prop('indeterminate', an.length > 0 && an.length < $cbs.length);

            try { localStorage.setItem(COT_KEY, JSON.stringify(an)); } catch (e) { /* chế độ riêng tư */ }
        }

        (function napCotDaLuu() {
            let an = [];
            try { an = JSON.parse(localStorage.getItem(COT_KEY) || '[]'); } catch (e) { an = []; }
            $('.show_col').each(function () { this.checked = an.indexOf(String($(this).data('col'))) === -1; });
            apDungCot();
        })();

        $(document).on('change', '.show_col', function () {
            // Giữ lại ít nhất một cột: tắt hết thì bảng chỉ còn STT và nút thao tác.
            if ($('.show_col').filter(':checked').length === 0) { this.checked = true; return; }
            apDungCot();
        });
        $(document).on('change', '#show_all', function () {
            $('.show_col').prop('checked', this.checked);
            if (!this.checked) $('.show_col').first().prop('checked', true);
            apDungCot();
        });

        $(document).on('change', '.item-select-all', function () {
            $('.item-select').prop('checked', this.checked);
        });


        // Bảng vừa thay ruột: đọc lại dữ liệu dòng và áp lại cột đang tắt.
        $(document).on('v2:da-nap', function () {
            ROWS = docDongHienCo();
            apDungCot();
        });

        // =====================================================================
        //  Lưới hàng của phiếu
        // =====================================================================
        let DONG = [];    // dòng hàng đang gõ
        let dongSeq = 0;
        let SUA_ID = 0;   // 0 = đang lập mới
        // Mốc sửa của BẢN đang mở, gửi lại lúc lưu để API phát hiện có người khác
        // vừa lưu đè — xem chong_ghi_de.go bên API. Rỗng khi lập mới.
        let SUA_MOC = '';
        // Phiếu đã duyệt mở ra ở chế độ CHỈ XEM — cùng một hộp thoại, mọi ô khoá
        // lại. Đúng cách v2 làm: bên đó `$order->status < 2 ? '' : 'disabled'`
        // rải trên từng ô của form sửa, không có màn xem riêng.
        let CHI_XEM = false;

        // =====================================================================
        //  Ô NGÀY TRONG HỘP THOẠI — lịch tiếng Việt, không dùng input[type=date]
        // =====================================================================
        //
        // Ô ngày của trình duyệt bày sẵn mặt nạ "dd/mm/yyyy" kèm icon lịch riêng,
        // nên ô đang TRỐNG nhìn như đã có gì đó; khuôn của nó lại khác hẳn mọi ô
        // ngày khác của trang (bộ lọc dùng DD-MM-YYYY). Thay bằng ô chữ chỉ đọc +
        // daterangepicker: trống là trống hẳn, và cả trang cùng một khuôn.
        //
        // TRONG BỘ NHỚ ngày luôn là YYYY-MM-DD (khuôn API nhận); trên màn hình là
        // DD-MM-YYYY. Hai hàm dưới là chỗ DUY NHẤT đổi qua lại.

        /** YYYY-MM-DD → DD-MM-YYYY để bày lên ô. Rỗng/sai khuôn → rỗng. */
        function ngayVN(iso) {
            const m = moment(String(iso || '').slice(0, 10), 'YYYY-MM-DD', true);

            return m.isValid() ? m.format(KHUON_NGAY) : '';
        }

        /** DD-MM-YYYY → YYYY-MM-DD để gửi đi. Rỗng/sai khuôn → rỗng. */
        function ngayISO(vn) {
            const m = moment(String(vn || '').trim(), KHUON_NGAY, true);

            return m.isValid() ? m.format('YYYY-MM-DD') : '';
        }

        /**
         * Gắn lịch cho một ô ngày ngay lúc người dùng bấm vào, chứ không gắn sẵn
         * cho cả lưới sau mỗi lần vẽ lại: lưới được dựng lại sau MỖI phím gõ ở ô
         * số lượng, gắn sẵn thì mỗi lượt gõ đẻ thêm một tá thẻ lịch mồ côi dưới
         * <body>.
         */
        /**
         * Lịch của MỘT ô mở về phía nào, đo theo chỗ trống thật quanh ô.
         *
         * Mặc định của daterangepicker là mở sang phải và xổ xuống. Ô Ngày hết hạn
         * nằm ở cột cuối lưới, sát mép phải hộp thoại, nên dưới 2K tấm lịch tràn
         * hẳn ra ngoài; dòng cuối lưới lại gần đáy màn nên xổ xuống là chui khỏi
         * khung nhìn. Đo rồi lật, chứ không rẽ theo bề ngang màn: cái quyết định
         * là chỗ trống quanh ô, không phải màn to hay nhỏ.
         */
        function huongLichCuaO($o) {
            const o = $o[0].getBoundingClientRect();
            // Một tấm lịch đơn của daterangepicker rộng ~300px, cao ~300px.
            const ROI = 300;

            return {
                opens: o.right + ROI > window.innerWidth ? 'left' : 'right',
                drops: o.bottom + ROI > window.innerHeight && o.top > ROI ? 'up' : 'down',
            };
        }

        $(document).on('click', '#modalCreate .ip-ngay', function () {
            const $o = $(this);
            // Ô bị khoá = hạn dùng của một lô ĐANG CÓ trong kho. Đó là dữ liệu của
            // lô, không phải thứ đặt lại ở phiếu — v2 cũng gỡ hẳn lịch khỏi ô này.
            if ($o.hasClass('khoa') || $o.data('daterangepicker')) return;

            $o.daterangepicker({
                singleDatePicker: true,
                showDropdowns: true,
                autoUpdateInput: false,
                autoApply: true,
                ...huongLichCuaO($o),
                // Hạn dùng của LÔ MỚI không thể nằm trong quá khứ (v2 cũng chặn).
                // Ngày chứng từ thì được lùi, nên chỉ chặn ở ô của lưới hàng.
                minDate: $o.data('f') === 'expire_date' ? moment() : undefined,
                locale: V2.lichVN(),
            }, function (start) {
                // `input` chứ không phải `change`: trình xử lý của lưới hàng nghe
                // sự kiện `input` trên mọi ô [data-f] — bắn `change` thì ngày chọn
                // xong hiện lên màn nhưng KHÔNG đi vào dòng hàng, và lúc lưu mới
                // phát hiện ra là mất.
                $o.val(start.format(KHUON_NGAY)).trigger('input');
            });
            $o.data('daterangepicker').show();
        });

        /**
         * Hai ô ngày của bộ lọc gắn lịch NGAY LÚC TẢI TRANG, nên không thể tính
         * hướng mở lúc ấy: khung lọc còn đang nằm trong tấm offcanvas chưa mở, ô
         * chưa có toạ độ nào để đo. Tính lại ngay trước mỗi lượt mở.
         *
         * Phải là `mousedown` / `focusin`: daterangepicker tự buộc vào `click` và
         * `focus` của chính ô, mà nó tính vị trí XONG mới bắn `show.daterangepicker`
         * — sửa ở đó là muộn một nhịp.
         */
        $(document).on('mousedown focusin', '#from_date, #to_date', function () {
            const l = $(this).data('daterangepicker');
            if (l) Object.assign(l, huongLichCuaO($(this)));
        });

        // Lịch treo dưới <body> chứ không nằm trong hộp thoại, nên cuộn thân hộp là
        // nó đứng nguyên một chỗ và trỏ vào khoảng không. Đóng lại là xong.
        $(document).on('scroll', '#modalCreate .modal-body', function () {
            $('#modalCreate .ip-ngay').each(function () {
                const l = $(this).data('daterangepicker');
                if (l) l.hide();
            });
        });

        /** Gỡ lịch của các dòng sắp bị vẽ đè, để không bỏ lại thẻ mồ côi. */
        function donLichDong() {
            $('#modalCreate .list-menu .ip-ngay').each(function () {
                const p = $(this).data('daterangepicker');
                if (p) p.remove();
            });
        }

        const $mc = () => $('#modalCreate');
        const kieuVat = () => (THUE_TRUC_TIEP ? 'order' : String($mc().find('.vat_mode').val() || 'order'));
        const vatPhieu = () => (THUE_TRUC_TIEP ? 0 : soN($mc().find('.vat_percent').val()));

        /** Thuế của một dòng: khai theo phiếu thì mọi dòng chung một mức. */
        const vatCuaDong = (d) => (kieuVat() === 'goods' ? d.vat_percent : vatPhieu());

        /**
         * Thêm một dòng hàng.
         *
         * Chọn lại đúng món ĐANG CÓ trong phiếu với cùng đơn vị và cùng số lô thì
         * cộng số lượng vào dòng cũ. Khác đơn vị hoặc khác lô thì là dòng riêng: mua
         * 1 thùng và 5 cái, hay hai lô khác nhau, đều là dòng có thật trên hóa đơn.
         */
        /**
         * Sinh một số lô mới theo giờ: DDMMYYYYHHmmss, đúng khuôn bản v2 dùng.
         *
         * Cộng thêm giây cho tới khi không đụng lô nào của chính mặt hàng đó —
         * thêm hai dòng trong cùng một giây là chuyện thường khi bấm nhanh.
         */
        function loTuSinh(mh) {
            const daCo = (mh.lots || []).map((l) => l.lot_number)
                .concat(LO_MOI[mh.variant_id] || []);

            const t = moment();
            let so = t.format('DDMMYYYYHHmmss');
            while (daCo.indexOf(so) !== -1) {
                t.add(1, 'second');
                so = t.format('DDMMYYYYHHmmss');
            }

            LO_MOI[mh.variant_id] = (LO_MOI[mh.variant_id] || []).concat(so);

            return so;
        }

        function themDong(mh, ghiDe) {
            if (!mh) return;

            const donVi = (mh.units && mh.units.length) ? mh.units : [{
                unit_id: mh.base_unit_id || 0, name: mh.base_unit_name || '', ratio: 1,
            }];
            const unitID = ghiDe ? Number(ghiDe.unit_id || 0) : Number(donVi[0].unit_id || 0);

            // Chọn lại đúng món ĐANG CÓ trong phiếu (cùng đơn vị) thì cộng số lượng
            // vào dòng cũ. Xét theo (mặt hàng, đơn vị), KHÔNG xét số lô: mỗi dòng mới
            // được sinh sẵn một số lô riêng, nên so cả lô thì lần nào cũng ra dòng
            // mới — bấm một món ba lần là ba dòng, mỗi dòng một lô, và đó là ba lô
            // không có thật.
            if (!ghiDe) {
                const daCo = DONG.find((d) => d.variant_id === mh.variant_id && d.unit_id === unitID);
                if (daCo) {
                    daCo.quantity += 1;

                    return;
                }
            }

            // SỐ LÔ SINH SẴN — đúng cách v2 làm (loadMenu có autoNewLot = true).
            //
            // Bắt buộc có lô mà không sinh sẵn thì mỗi dòng hàng người dùng phải bấm
            // thêm ba nhát: mở ô chọn, chọn "Lô mới", gõ, Áp dụng. Sinh sẵn một số
            // theo giờ phút giây thì phiếu lập xong ngay, ai cần đặt tên lô riêng
            // vẫn chọn "+ Lô mới…" như thường.
            const lot = ghiDe ? (ghiDe.lot_number || '') : loTuSinh(mh);

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
                // Dòng mới bắt đầu từ 0 — người lập phiếu tự gõ số thật. Điền sẵn 1
                // thì lúc nào cũng phải xoá đi rồi mới gõ, và dòng nào quên gõ lại
                // lặng lẽ trôi vào phiếu với số lượng 1. Lượt lưu chặn dòng số 0.
                quantity: ghiDe ? Number(ghiDe.quantity || 0) : 0,
                unit_cost: ghiDe ? Number(ghiDe.unit_cost || 0) : Number(mh.cost_price || 0),
                vat_percent: ghiDe ? Number(ghiDe.vat_percent || 0) : Number(mh.vat_percent || 0),
                // Các lô ĐANG CÓ của mặt hàng, để ô số lô bày ra thay vì bắt gõ tay.
                lots: mh.lots || [],
                lot_number: lot,
                // lo_moi = đang gõ một lô chưa có trong kho (ô chọn đổi thành ô gõ).
                lo_moi: false,
                expire_date: ghiDe ? (ghiDe.expire_date || '') : '',
            });
        }

        /**
         * Ô số lô của một dòng hàng — ô CHỌN các lô đang có, kèm mục "Lô mới…".
         *
         * Bản v2 cũng làm đúng vậy: bắt gõ tay thì sai một ký tự là đẻ ra một lô
         * mới trông y hệt lô cũ, và từ đó sổ kho có hai lô mà thực tế chỉ có một.
         * Chọn lô cũ thì hạn dùng và giá nhập lần trước tự điền theo.
         */
        /**
         * Lô vừa khai trong lượt lập phiếu này, gom theo MẶT HÀNG chứ không theo dòng.
         *
         * Một phiếu hay có hai dòng cùng một mặt hàng (hai đơn vị, hai mức giá). Nếu
         * lô mới chỉ nằm ở dòng vừa gõ thì dòng thứ hai không chọn lại được — phải gõ
         * tay, mà lúc đó phép soát trùng của dòng ấy lại không thấy dòng kia, nên
         * phiếu ra hai dòng cùng lô mà không có lời cảnh báo nào. Bản v2 cũng soi
         * theo mặt hàng (`sameMenuOptions`), không soi theo dòng.
         */
        let LO_MOI = {};

        /** Mọi số lô bày ra cho một dòng: lô trong kho + lô vừa khai cho mặt hàng đó. */
        function danhSachLo(d) {
            const ds = (d.lots || []).map((l) => l.lot_number)
                .concat(LO_MOI[d.variant_id] || []);

            // Lô ĐANG CHỌN luôn phải có mặt trong danh sách. Thiếu nó thì ô chọn rơi
            // về dòng đầu tiên và số lô của phiếu biến mất khỏi màn hình mà không ai
            // hay — đúng ca sửa một phiếu cũ có lô đã bán hết.
            if (d.lot_number && ds.indexOf(d.lot_number) === -1) ds.push(d.lot_number);

            return [...new Set(ds)];
        }

        /** Lô đang chọn là lô ĐÃ CÓ trong kho (khác với lô vừa khai ở dòng này). */
        function laLoCu(d) {
            return (d.lots || []).some((l) => l.lot_number === d.lot_number);
        }

        /**
         * Ô số lô — dựng theo đúng màn lập phiếu của bản v2.
         *
         * Ô CHỌN các lô đang có, cuối danh sách là "+ Lô mới…". Bấm vào đó thì v2
         * KHÔNG đổi hẳn ô: nó giấu ô chọn đi rồi hiện ngay bên dưới một ô gõ kèm
         * hai nút Áp dụng / Đóng. Khác biệt quan trọng — gõ dở mà đổi ý thì bấm
         * Đóng là về đúng danh sách cũ, không mất lô đang chọn.
         */
        function oChonLo(d) {
            const ds = danhSachLo(d);
            const coSan = (l) => (d.lots || []).some((x) => x.lot_number === l);

            const opt = ds.map((l) => '<option value="' + esc(l) + '"'
                + (l === d.lot_number ? ' selected' : '') + '>'
                + esc(l) + (coSan(l) ? '' : ' (mới)') + '</option>').join('');

            const chon = '<select class="form-control form-select ip-line" data-f="lot_chon"'
                + (d.lo_moi && !CHI_XEM ? ' hidden' : '') + (CHI_XEM ? ' disabled' : '') + '>'
                + '<option value=""' + (d.lot_number ? '' : ' selected') + '>— Chọn lô —</option>'
                + opt
                + '<option value="__moi__">+ Lô mới…</option>'
                + '</select>';

            if (!d.lo_moi || CHI_XEM) return chon;

            // Ô gõ tạm KHÔNG mang data-f: chừng nào chưa bấm Áp dụng thì nó chưa
            // phải số lô của dòng — y như v2, và nhờ vậy bấm Đóng là bỏ sạch.
            return chon
                + '<div class="lo-moi-box">'
                + '<input type="text" class="form-control ip-line is-text lo-moi-o" maxlength="50"'
                + ' placeholder="Số lô mới">'
                + '<button type="button" class="bt btn_green ap-lo">{{ __('message.apply') }}</button>'
                + '<button type="button" class="bt btn_red huy-lo">{{ __('message.close') }}</button>'
                + '</div>';
        }

        function veLuoi() {
            const $m = $mc();
            const khoa = CHI_XEM ? ' disabled' : '';
            donLichDong();
            $m.find('.lines-empty').toggle(DONG.length === 0);
            $m.find('table.table-product').toggleClass('is-vat-goods', kieuVat() === 'goods');

            $m.find('.list-menu').html(DONG.map((d, i) => {
                const vat = vatCuaDong(d);
                const tienHang = Math.round(d.unit_cost * d.quantity);
                const tienThue = Math.round(tienHang * Math.max(0, vat) / 100);
                const base = d.quantity * d.ratio;
                const nguyen = Math.abs(base - Math.round(base)) < 0.0001;
                const ten = [d.product_name, d.variant_name].filter(Boolean).join(' · ');
                const loCu = laLoCu(d);

                const donVi = d.units.map((u) => {
                    const id = Number(u.unit_id || 0);
                    // API trả tên rỗng thì lấy tên đơn vị chính của chính mặt hàng;
                    // chữ "Đơn vị chính" chỉ là chốt cuối, vì nó không nói được
                    // người dùng đang mua theo cái gì.
                    const nhan = u.name || d.base_unit_name || 'Đơn vị chính';
                    const hs = Number(u.ratio || 1) === 1 ? '' : ' (×' + nhomSo(u.ratio) + ')';
                    return '<option value="' + id + '"' + (id === d.unit_id ? ' selected' : '') + '>'
                        + esc(nhan) + hs + '</option>';
                }).join('');

                // Dòng quy đổi chỉ nói khi có gì để nói: đơn vị mua = đơn vị kho thì
                // câu "= 3 Cái vào kho" chỉ là tiếng ồn. Viết tắt cho vừa ô, câu đầy
                // đủ để trong title.
                let quyDoi = '';
                if (d.ratio !== 1) {
                    const dv = esc(d.base_unit_name || 'đơn vị');
                    quyDoi = nguyen
                        ? '<span class="line-conv" title="' + nhomSo(Math.round(base)) + ' ' + dv + ' sẽ vào kho">= '
                            + nhomSo(Math.round(base)) + ' ' + dv + '</span>'
                        : '<span class="line-conv is-le" title="Quy đổi ra ' + nhomSo(base) + ' ' + dv
                            + ' — sổ kho chỉ nhận số nguyên">Quy đổi lẻ</span>';
                }

                return '<tr data-key="' + d.key + '">'
                    + '<td class="la-giua">' + (i + 1) + '</td>'
                    + '<td class="la-chu" title="' + esc(d.sku) + '">' + esc(d.sku || '—') + '</td>'
                    + '<td class="la-chu" title="' + esc(ten) + '">' + esc(ten) + '</td>'
                    + '<td class="la-chu"><select class="form-control form-select ip-line" data-f="unit_id"'
                        + khoa + '>' + donVi + '</select></td>'
                    + '<td class="la-so"><input type="text" class="form-control ip-line" data-f="quantity"'
                        + ' inputmode="numeric"' + khoa + ' value="' + nhomSo(d.quantity) + '">' + quyDoi + '</td>'
                    // Lô CŨ thì giá nhập và hạn dùng là DỮ LIỆU của lô đó, không phải
                    // thứ gõ lại ở đây — v2 khoá cả hai, và khoá là đúng: sửa ở đây
                    // thì phiếu nói một giá còn lô trong kho mang một giá khác.
                    + '<td class="la-so"><input type="text" class="form-control ip-line" data-f="unit_cost"'
                        + ' inputmode="numeric"' + (loCu ? ' readonly' : '') + khoa
                        + ' value="' + nhomSo(d.unit_cost) + '"></td>'
                    + '<td class="la-so">' + tien(tienHang) + '</td>'
                    + '<td class="la-so col-vat"><input type="number" class="form-control ip-line"'
                        + ' data-f="vat_percent" min="-2" max="100" step="1"' + khoa
                        + ' value="' + vat + '"></td>'
                    + '<td class="la-so col-vat-money">' + tien(tienThue) + '</td>'
                    + '<td class="la-so col-sau-vat"><b>' + tien(tienHang + tienThue) + '</b></td>'
                    + '<td class="la-chu">' + oChonLo(d) + '</td>'
                    // Ô ngày là ô CHỮ + lịch tiếng Việt, không phải input[type=date]:
                    // ô ngày của trình duyệt luôn bày sẵn mặt nạ "dd/mm/yyyy" kèm
                    // icon lịch riêng, nên một ô đang để trống nhìn như đã có gì đó,
                    // và khuôn ngày lại khác hẳn mọi ô ngày khác của trang.
                    + '<td class="la-giua"><input type="text" readonly class="form-control ip-line ip-ngay'
                        + (loCu ? ' khoa' : '') + '"'
                        + khoa + ' data-f="expire_date" value="' + esc(ngayVN(d.expire_date)) + '"></td>'
                    + '<td class="text-center not-export">'
                        + (CHI_XEM ? ''
                            : '<i class="fa-solid fa-trash text-danger remove-menu" role="button" data-xoa="' + d.key + '"></i>')
                    + '</td>'
                    + '</tr>';
            }).join(''));

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

            const $m = $mc();
            // Ba ô là <input readonly> đúng như v2, nên ghi bằng .val() chứ không .text().
            $m.find('.tong-tien-hang').val(tien(tienHang));
            $m.find('.tong-thue').val(tien(thue));
            $m.find('.tong-cong').val(tien(tienHang + thue));
            $m.find('.save-order[data-duyet="1"]').prop('disabled', DONG.length === 0);
        }

        $(document).on('input', '#modalCreate .list-menu [data-f]', function () {
            const $tr = $(this).closest('tr');
            const key = Number($tr.data('key'));
            const d = DONG.find((x) => x.key === key);
            if (!d) return;

            const f = $(this).data('f');

            // HAI Ô CHỌN CÓ TRÌNH XỬ LÝ `change` RIÊNG — bỏ qua ở đây.
            //
            // <select> bắn CẢ `input` LẪN `change`, và `input` tới trước. Nhánh
            // dưới không nhận ra hai tên này nên rơi thẳng xuống veLuoi() ở cuối
            // hàm: lưới vẽ đè lại từ dữ liệu CHƯA đổi, ô chọn bật về giá trị cũ,
            // rồi `change` mới tới thì phần tử đã bị thay mất. Nhìn từ ngoài đúng
            // là "bấm chọn mà không ăn gì".
            if (f === 'unit_id' || f === 'lot_chon') return;

            if (f === 'quantity') {
                d.quantity = Math.max(0, soN(this.value));
            } else if (f === 'unit_cost') {
                d.unit_cost = Math.max(0, soN(this.value));
            } else if (f === 'vat_percent') {
                d.vat_percent = Math.max(-2, Math.min(100, Number(this.value) || 0));
            } else if (f === 'expire_date') {
                // Hạn dùng không đổi con số nào khác trên lưới: ghi thẳng vào dữ
                // liệu, khỏi vẽ lại cả bảng dưới tay người đang chọn.
                d.expire_date = ngayISO(this.value);
                return;
            }

            // Vẽ lại cả lưới để dòng quy đổi và tiền của dòng đó cùng đổi theo —
            // nhưng giữ con trỏ ở đúng ô người dùng đang gõ.
            const phai = viTriTuPhai(this);
            veLuoi();
            const lai = $mc().find('tr[data-key="' + key + '"] [data-f="' + f + '"]')[0];
            if (lai) { lai.focus(); datConTro(lai, phai); }
        });

        // Ô đang là 0 thì bấm vào là BÔI ĐEN sẵn, để con số gõ vào thay hẳn chỗ số 0.
        //
        // Không có nó thì con trỏ rơi đâu là dính vào đó: bấm bên trái số 0 rồi gõ
        // "2" ra "20", chứ không phải 2. Chỉ bôi khi ô đang là 0 — ô đang có 12 mà
        // bôi đen cả thì sửa một chữ số cũng mất luôn cả số.
        //
        // Áp cho cả Giá nhập: cùng một ô số, cùng một cái bẫy khi giá đang là 0.
        const O_SO = '#modalCreate .list-menu [data-f="quantity"], #modalCreate .list-menu [data-f="unit_cost"]';
        let vuaBoiDen = false;

        // Lượt bấm chuột đặt lại con trỏ SAU khi focus chạy xong, xoá mất vùng vừa
        // bôi. Chặn đúng lượt mouseup ấy thì vùng bôi còn nguyên. Đặt lại cờ ở
        // mousedown để lượt bấm sang ô KHÁC không bị chặn lây.
        $(document).on('mousedown', O_SO, () => { vuaBoiDen = false; });

        $(document).on('focusin', O_SO, function () {
            if (soN(this.value) !== 0) return;
            this.select();
            vuaBoiDen = true;
        });

        $(document).on('mouseup', O_SO, function (e) {
            if (!vuaBoiDen) return;
            vuaBoiDen = false;
            e.preventDefault();
        });

        // Chọn một lô đang có: hạn dùng và giá nhập lần trước tự điền theo, đúng
        // như v2 (mỗi option bên đó mang data-expire-date và data-cost-price).
        const dongCuaO = (el) => DONG.find((x) => x.key === Number($(el).closest('tr').data('key')));

        $(document).on('change', '#modalCreate .list-menu [data-f="lot_chon"]', function () {
            const d = dongCuaO(this);
            if (!d) return;

            if (this.value === '__moi__') {
                d.lo_moi = true;
                veLuoi();
                $mc().find('tr[data-key="' + d.key + '"] .lo-moi-o').trigger('focus');

                return;
            }

            d.lot_number = this.value;

            // Chọn lô ĐANG CÓ: hạn dùng và giá nhập của lô tự điền theo rồi khoá
            // lại — đúng như v2 (mỗi option bên đó mang data-expire-date và
            // data-cost-price). Lô đã nằm trong kho thì hai con số ấy là dữ liệu,
            // không phải thứ người lập phiếu đặt lại.
            const lo = (d.lots || []).find((l) => l.lot_number === this.value);
            if (lo) {
                d.expire_date = lo.expire_date || '';
                if (lo.unit_cost > 0) d.unit_cost = lo.unit_cost;
            }
            veLuoi();
        });

        // Áp dụng lô mới. Soát rỗng và soát TRÙNG trước khi nhận, y như v2: hai lô
        // trùng tên trên cùng một mặt hàng là hai dòng cùng đổ vào một chỗ trong kho.
        $(document).on('click', '#modalCreate .ap-lo', function () {
            const $tr = $(this).closest('tr');
            const d = dongCuaO(this);
            if (!d) return;

            const so = String($tr.find('.lo-moi-o').val() || '').trim();
            if (!so) {
                toastr.error('Chưa nhập số lô mới.');

                return;
            }
            if (danhSachLo(d).indexOf(so) !== -1) {
                toastr.error('Số lô "' + so + '" đã có trong danh sách của mặt hàng này.');

                return;
            }

            LO_MOI[d.variant_id] = (LO_MOI[d.variant_id] || []).concat(so);
            d.lot_number = so;
            d.lo_moi = false;
            veLuoi();
        });

        // Đóng: bỏ ô gõ tạm, trả lại ô chọn kèm đúng lô đang chọn trước đó.
        $(document).on('click', '#modalCreate .huy-lo', function () {
            const d = dongCuaO(this);
            if (!d) return;
            d.lo_moi = false;
            veLuoi();
        });

        // Enter trong ô lô mới = bấm Áp dụng.
        $(document).on('keydown', '#modalCreate .lo-moi-o', function (e) {
            if (e.key !== 'Enter') return;
            e.preventDefault();
            $(this).closest('tr').find('.ap-lo').trigger('click');
        });

        $(document).on('change', '#modalCreate .list-menu [data-f="unit_id"]', function () {
            const d = DONG.find((x) => x.key === Number($(this).closest('tr').data('key')));
            if (!d) return;
            d.unit_id = Number(this.value);
            const dv = d.units.find((u) => Number(u.unit_id || 0) === d.unit_id);
            d.ratio = Number((dv && dv.ratio) || 1);
            veLuoi();
        });

        $(document).on('click', '#modalCreate .remove-menu', function () {
            DONG = DONG.filter((d) => d.key !== Number($(this).data('xoa')));
            veLuoi();
        });

        /**
         * Nhãn trong khung ô thuế: "%" với số dương, "KCT"/"KKKNT" với hai mã âm.
         * Khai theo dòng thì ô thuế của cả phiếu biến mất, đúng như v2.
         */
        function veKieuVat() {
            // Hộ kinh doanh nộp thuế trực tiếp: hai ô thuế đã bị Blade gắn `d-none`,
            // đừng gọi .toggle() lên chúng nữa — bật lại là bày ra một ô mà mọi giá
            // trị gõ vào đó đều bị controller ghi về 0.
            if (THUE_TRUC_TIEP) {
                veLuoi();

                return;
            }

            const v = vatPhieu();
            $mc().find('.vat-suffix').text(v < 0 ? nhanVat(v) : '%');
            $mc().find('.o-vat-phieu').toggle(kieuVat() !== 'goods');
            veLuoi();
        }

        $(document).on('input', '#modalCreate .vat_percent', veKieuVat);
        $(document).on('change', '#modalCreate .vat_mode', veKieuVat);

        /**
         * Chọn nhà cung cấp thì bốn ô hồ sơ bên dưới tự điền.
         *
         * Tên bên bán đi vào biến chứ không phải ô gõ được: đó là bản CHỤP ghi xuống
         * chứng từ, để người ta sửa tay thì phiếu in ra một đằng, danh mục một nẻo.
         */
        let TEN_NCC = '';

        function veHoSoNCC() {
            const $m = $mc();
            const o = $m.find('.supplier_id option:selected');
            const d = o.length ? o[0].dataset : {};
            TEN_NCC = d.name || '';
            $m.find('[name="address"]').val(d.address || '');
            $m.find('[name="address_line2"]').val(d.address2 || '');
            $m.find('[name="mobile"]').val(d.phone || '');
            $m.find('[name="contact_person_phone"]').val(d.repPhone || '');
        }

        $(document).on('change', '#modalCreate .supplier_id', veHoSoNCC);

        // ---------- Ô tìm hàng hóa (select2 gọi API) ----------
        function napSelectMenus() {
            const $m = $mc();
            $m.find('.supplier_id, .purchaser_id, .select-categories').each(function () {
                if ($(this).hasClass('select2-hidden-accessible')) return;
                $(this).select2({ dropdownParent: $m, width: '100%' });
            });

            if ($m.find('.select-menus').hasClass('select2-hidden-accessible')) return;
            $m.find('.select-menus').select2({
                dropdownParent: $m,
                placeholder: '{{ __('message.goods') }}',
                width: '100%',
                minimumInputLength: 0,
                ajax: {
                    url: URL_MAT_HANG,
                    dataType: 'json',
                    delay: 300,
                    data: (params) => ({
                        keyword: params.term || '',
                        category_id: $mc().find('.select-categories').val() || 0,
                    }),
                    processResults: (res) => ({
                        results: (res.data || []).map((r) => ({
                            id: r.variant_id,
                            text: [r.product_name, r.variant_name].filter(Boolean).join(' · ')
                                + ' (' + (r.sku || '') + ')'
                                + (r.cost_price == null ? '' : ' · ' + tien(r.cost_price)),
                            mh: r,
                        })),
                    }),
                },
            });
        }

        // Dựng select2 ngay từ đầu chứ không đợi lượt mở hộp: hộp nằm sẵn trong trang,
        // mà dựng lúc hộp còn ẩn thì ô chọn hay ra sai bề ngang ở lượt mở đầu tiên.
        $(napSelectMenus);

        $(document).on('select2:select', '#modalCreate .select-menus', function (e) {
            themDong(e.params.data.mh);
            veLuoi();
            $(this).val(null).trigger('change');
        });

        // Đổi nhóm hàng là lọc lại ngay lượt tìm sau, không phải gõ thêm chữ nào.
        $(document).on('change', '#modalCreate .select-categories', function () {
            $mc().find('.select-menus').val(null).trigger('change');
        });

        // ---------- Ảnh chứng từ ----------
        let ANH_PHIEU = '';

        function veAnh(url) {
            ANH_PHIEU = url || '';
            const $m = $mc();
            $m.find('.anh-nut-chu').text(url ? '{{ __('message.edit') }}' : '{{ __('message.upload') }}');
            $m.find('.anh-xem').toggleClass('d-none', !url).attr('href', url || '#');
            $m.find('.anh-go').toggleClass('d-none', !url);
        }

        $(document).on('change', '#modalCreate .anh_file', function () {
            const f = this.files[0];
            if (!f) return;
            const fd = new FormData();
            fd.append('anh', f);
            fd.append('_token', CSRF);
            $.ajax({ url: URL_ANH, method: 'POST', data: fd, contentType: false, processData: false })
                .done((r) => veAnh(r.url || ''))
                .fail((x) => toastr.error(((x.responseJSON || {}).message) || 'Không tải được ảnh lên.'));
            this.value = '';
        });

        $(document).on('click', '#modalCreate .anh-go', () => veAnh(''));

        // ---------- Nâng cao: xuất / tải mẫu / nhập lưới hàng ----------
        const COT_DONG = ['Mã hàng hóa', 'Tên hàng hóa', 'Số lượng', 'Giá nhập', '% VAT', 'Số lô', 'Hạn dùng'];

        /** Tải một bảng xuống máy dưới dạng CSV có BOM để Excel đọc đúng dấu. */
        function taiCSV(ten, dong) {
            const noi = '﻿' + dong.map((h) => h.map((o) => {
                const v = String(o == null ? '' : o);
                return /[",\n]/.test(v) ? '"' + v.replace(/"/g, '""') + '"' : v;
            }).join(',')).join('\r\n');

            const a = document.createElement('a');
            a.href = URL.createObjectURL(new Blob([noi], { type: 'text/csv;charset=utf-8' }));
            a.download = ten;
            a.click();
            setTimeout(() => URL.revokeObjectURL(a.href), 1000);
        }

        $(document).on('click', '#modalCreate .export-product', function () {
            if (!DONG.length) { toastr.error('Phiếu chưa có dòng hàng nào để xuất.'); return; }
            taiCSV('dong-hang-phieu-mua.csv', [COT_DONG].concat(DONG.map((d) => [
                d.sku, [d.product_name, d.variant_name].filter(Boolean).join(' · '),
                d.quantity, d.unit_cost, vatCuaDong(d), d.lot_number, d.expire_date,
            ])));
        });

        $(document).on('click', '#modalCreate .download-sample', function () {
            taiCSV('mau-dong-hang-phieu-mua.csv', [
                COT_DONG,
                ['SP001', 'Ghi tên cho dễ đọc, hệ thống tra theo mã', 2, 240000, 8, 'L2026-08', '2027-08-22'],
                ['SP002', '', 10, 15000, 0, '', ''],
            ]);
        });

        $(document).on('click', '#modalCreate .import-product', function () {
            $mc().find('.line_file').trigger('click');
        });

        function baoLoiNhap(cau) {
            $('#import_error').text(cau).show();
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

        /**
         * Nhập lưới hàng từ CSV.
         *
         * Tra hàng theo MÃ chứ không theo tên: tên gõ trong file là để người đọc, còn
         * thứ đi vào phiếu phải là món có thật trong danh mục. Mã không tra ra thì
         * báo đúng dòng đó, không im lặng bỏ qua.
         */
        $(document).on('change', '#modalCreate .line_file', async function () {
            const file = this.files[0];
            this.value = '';
            if (!file) return;

            $('#import_error').hide();
            const van = await file.text();
            const dong = van.replace(/^﻿/, '').split(/\r?\n/).filter((d) => d.trim() !== '');
            if (dong.length < 2) { baoLoiNhap('File không có dòng dữ liệu nào.'); return; }

            const loi = [];
            const them = [];
            for (let i = 1; i < dong.length; i++) {
                const o = tachCSV(dong[i]);
                const ma = (o[0] || '').trim();
                const sl = soN(o[2]);
                if (ma === '') { loi.push('Dòng ' + (i + 1) + ': chưa có mã hàng hóa.'); continue; }
                if (sl <= 0) { loi.push('Dòng ' + (i + 1) + ': số lượng phải lớn hơn 0.'); continue; }
                them.push({
                    ma, sl, gia: soN(o[3]), vat: Number(o[4]) || 0,
                    lo: (o[5] || '').trim(), han: (o[6] || '').trim(),
                });
            }
            if (loi.length) { baoLoiNhap(loi.slice(0, 8).join(' ')); return; }

            const timThay = await Promise.all(them.map((t) =>
                fetch(URL_MAT_HANG + '?keyword=' + encodeURIComponent(t.ma), { headers: { Accept: 'application/json' } })
                    .then((r) => r.json())
                    .then((j) => (j.data || []).find((m) => String(m.sku).toLowerCase() === t.ma.toLowerCase()))
                    .catch(() => null)));

            const khong = [];
            them.forEach((t, i) => {
                const mh = timThay[i];
                if (!mh) { khong.push(t.ma); return; }
                themDong(mh, {
                    unit_id: mh.base_unit_id || 0, quantity: t.sl, unit_cost: t.gia,
                    vat_percent: t.vat, lot_number: t.lo, expire_date: t.han,
                });
            });
            veLuoi();

            if (khong.length) {
                baoLoiNhap('Không tìm thấy mã hàng: ' + khong.join(', ') + '. Các dòng còn lại đã thêm vào phiếu.');
            }
        });

        // =====================================================================
        //  Mở hộp lập / sửa phiếu
        // =====================================================================
        /**
         * Khoá / mở toàn bộ ô nhập của hộp thoại.
         *
         * Ô nào VỐN đã khoá sẵn (hồ sơ bên bán, ngày tạo, người tạo…) thì đánh dấu
         * lại trước khi khoá, để lúc mở ra cho phiếu khác không lỡ tay mở luôn cả
         * mấy ô ấy.
         */
        // Ô nào khoá sẵn từ trong khuôn — hồ sơ bên bán, ngày tạo, người tạo — thì
        // đánh dấu MỘT LẦN, trước khi có lượt khoá / mở nào. Đánh dấu lúc khoá là
        // muộn: phiếu đầu tiên mở ra ở chế độ sửa sẽ chạy nhánh mở và gỡ khoá luôn
        // cả những ô ấy.
        let daDanhDauKhoaSan = false;
        function danhDauKhoaSan() {
            if (daDanhDauKhoaSan) return;
            daDanhDauKhoaSan = true;
            $mc().find('.pmh-form').find('input, select, textarea, button')
                .filter((i, el) => el.disabled).attr('data-khoa-san', '1');
        }

        function khoaPhieu(khoa) {
            const $m = $mc();
            danhDauKhoaSan();
            // Gắn dấu lên chính hộp thoại: CSS và bài kiểm đều cần một chỗ để hỏi
            // "hộp này đang mở ở chế độ nào".
            $m.toggleClass('pmh-chi-xem', khoa);
            $m.find('.pmh-form').find('input, select, textarea, button').each(function () {
                const $o = $(this);
                $o.prop('disabled', khoa || $o.attr('data-khoa-san') === '1');
            });
            // Bộ công cụ tìm hàng gập hẳn — v2 cũng để `d-none` cho phiếu đã duyệt,
            // vì thêm được hàng vào phiếu đã vào kho là sai từ gốc.
            $m.find('.content_midd_title .pmh-hang-cong-cu').toggleClass('d-none', khoa);
            // Ảnh chứng từ: xem thì chỉ còn đường dẫn, không còn nút tải lên / gỡ.
            $m.find('.anh_file').closest('label').toggleClass('d-none', khoa);
            $m.find('.anh-go').toggleClass('pmh-an', khoa);
        }

        /** Dựng khối công nợ + lịch sử cho phiếu đã duyệt. */
        function veLichSu(d) {
            const $o = $mc().find('.pmh-lichsu');
            if (!CHI_XEM) { $o.addClass('d-none').empty(); return; }

            const tong = Number(d.total_amount || 0);
            const daTra = Number(d.paid_amount || 0);
            const conNo = Math.max(0, tong - daTra);
            const ls = (d.histories || []).map((h) =>
                '<div class="view-cell"><span class="view-lb">' + esc(gioNgay(h.created_at)) + '</span>'
                + '<span class="view-vl">' + esc(h.note || (NHAN_TRANG_THAI[h.to_status] || h.to_status)) + '</span></div>'
            ).join('');

            // Thoả thuận nợ chỉ bày khi CÓ. Lưu được mà không hiện ra thì hạn trả
            // và số điện thoại nằm trong bảng cũng như không.
            const no = d.is_debt
                ? '<div class="mt-3"><b>{{ __('message.debt_record') }}</b>'
                    + '<div class="view-cell"><span class="view-lb">{{ __('message.due_date') }}</span>'
                        + '<span class="view-vl">' + esc(ngayVN(d.debt_due_date) || '—') + '</span></div>'
                    + '<div class="view-cell"><span class="view-lb">{{ __('message.representative') }}</span>'
                        + '<span class="view-vl">' + esc(d.debt_contact_name || '—') + '</span></div>'
                    + '<div class="view-cell"><span class="view-lb">{{ __('message.phone') }}</span>'
                        + '<span class="view-vl">' + esc(d.debt_contact_phone || '—') + '</span></div>'
                    + '</div>'
                : '';

            const cachTra = { cash: '{{ __('message.cash') }}', transfer: '{{ __('message.transfer') }}' };

            // SỔ TỪNG LƯỢT TRẢ. `paid_amount` chỉ nói tổng; không có sổ thì "đã trả
            // 700.000" không cho biết đó là một lượt hay ba lượt, tiền mặt hay
            // chuyển khoản, ai ghi. Dòng ÂM là lượt CHỮA lại con số ghi sai, tô
            // khác màu để không đọc nhầm thành một lượt trả.
            const soTra = (d.payments || []).map((x) => {
                const am = Number(x.amount || 0);

                return '<div class="view-cell">'
                    + '<span class="view-lb">' + esc(gioNgay(x.created_at)) + '</span>'
                    + '<span class="view-vl">'
                        + '<b' + (am < 0 ? ' class="money-no"' : '') + '>'
                        + (am < 0 ? '− ' : '') + tien(Math.abs(am)) + '</b>'
                        + (x.payment_method ? ' · ' + esc(cachTra[x.payment_method] || x.payment_method) : '')
                        + ' · còn lại ' + tien(Math.max(0, tong - Number(x.paid_after || 0)))
                        + (x.created_by_name ? ' · ' + esc(x.created_by_name) : '')
                        + (x.note ? ' · ' + esc(x.note) : '')
                    + '</span></div>';
            }).join('');

            $o.removeClass('d-none').html(
                '<div class="money-box">'
                    + '<div class="money-row"><span>{{ __('message.amount_paid') }}</span><b>' + tien(daTra) + '</b></div>'
                    + '<div class="money-row"><span>{{ __('message.still_in_debt') }}</span>'
                        + '<b class="money-no">' + tien(conNo) + '</b></div>'
                    + (d.payment_method
                        ? '<div class="money-row"><span>{{ __('message.payment-method') }}</span><b>'
                            + esc(cachTra[d.payment_method] || d.payment_method) + '</b></div>'
                        : '')
                + '</div>'
                + (soTra ? '<div class="mt-3"><b>Sổ trả tiền</b>' + soTra + '</div>' : '')
                + no
                + (d.payment_attachment
                    ? '<p class="mt-2 mb-0">{{ __('message.attachment') }}: <a href="' + esc(d.payment_attachment)
                        + '" target="_blank" rel="noopener">{{ __('message.image') }}</a></p>'
                    : '')
                + (ls ? '<div class="mt-3"><b>Lịch sử phiếu</b>' + ls + '</div>' : ''));
        }

        function moPhieu(p) {
            const $m = $mc();
            const sua = !!(p && p.id);
            SUA_ID = sua ? Number(p.id) : 0;
            SUA_MOC = sua ? String(p.updated_at || '') : '';
            const d = p || {};

            // Phiếu nào KHÔNG sửa được nữa thì mở ở chế độ xem. Đọc theo cờ API trả
            // về chứ không tự suy từ trạng thái — luật nằm ở server, chép sang đây
            // là sớm muộn hai bên nói khác nhau. Phiếu mở bằng {id} rỗng (mở lại sau
            // khi lưu hỏng) chưa có cờ, cứ cho sửa như cũ.
            CHI_XEM = sua && p.can_edit === false;
            khoaPhieu(CHI_XEM);
            $m.find('.pmh-ma-phieu').text(sua
                ? [d.po_code, d.supplier_name].filter(Boolean).join(' — ') : '');
            // Lập mới lấy bộ nút của `create.blade.php`, phiếu đã lưu lấy bộ của
            // `edit.blade.php`. Xem thì không còn nút lưu nào.
            $m.find('.pmh-nut-moi').toggleClass('d-none', sua);
            $m.find('.pmh-nut-sua').toggleClass('d-none', !sua || CHI_XEM);
            $m.find('.pmh-tra').toggleClass('d-none', !d.can_pay);
            // Phiếu chưa lưu thì chưa có gì để in hay xuất.
            $m.find('.pmh-in, .pmh-excel').toggleClass('d-none', !sua);
            $m.find('.pmh-thanh-nut').data('phieu', d);

            $m.find('.modal-title').text(CHI_XEM
                ? '{{ __('message.purchase_order_details') }} ' + (d.po_code || '')
                : (sua
                    ? '{{ __('message.edit_purchase_order') }} ' + (d.po_code || '')
                    : '{{ __('message.create_purchase_order') }}'));

            $m.find('.supplier_id').val(String(d.supplier_id || 0)).trigger('change.select2');
            // Ngày chứng từ điền sẵn HÔM NAY khi lập mới: phiếu nào cũng có ngày,
            // và chín trên mười lần đó là hôm nay. Hẹn giao thì để TRỐNG — đó là
            // thoả thuận với bên bán, đoán hộ là ghi vào chứng từ một lời hứa
            // không ai đưa ra.
            $m.find('[name="document_date"]').val(sua
                ? ngayVN(d.document_date)
                : moment().format(KHUON_NGAY));
            $m.find('[name="expected_date"]').val(ngayVN(d.expected_date));
            $m.find('.purchaser_id').val(String(d.purchaser_id || 0)).trigger('change.select2');
            $m.find('[name="supplier_delivery_code"]').val(d.supplier_delivery_code || '');
            $m.find('.note').val(d.note || '');
            veHoSoNCC();
            // Phiếu cũ giữ NGUYÊN tên bên bán đã chụp, kể cả khi danh mục đã đổi tên.
            if (sua && d.supplier_name) TEN_NCC = d.supplier_name;

            $m.find('[name="created_date"]').val(sua ? gioNgay(d.created_at) : moment().format('DD-MM-YYYY'));
            if (sua && d.created_by_name) $m.find('[name="created_by_name"]').val(d.created_by_name);
            $m.find('[name="po_status"]').val(sua
                ? (NHAN_TRANG_THAI[d.status] || '') : '{{ __('message.create_new') }}');

            $m.find('.vat_mode').val(d.vat_mode || 'order');
            $m.find('.vat_percent').val(String(d.vat_percent == null ? 0 : d.vat_percent));
            veAnh(d.attachment || '');
            $('#import_error').hide();
            $m.find('.select-categories').val('').trigger('change.select2');

            DONG = [];
            dongSeq = 0;
            // Lô tạm là của LƯỢT LẬP PHIẾU này; giữ lại sang phiếu sau là bày ra
            // những số lô chưa từng vào kho.
            LO_MOI = {};

            if (sua && Array.isArray(d.items)) {
                // Dòng cũ dựng lại từ chính phiếu: hộp thoại phải mở ra đúng như phiếu
                // đang lưu, kể cả khi hàng hóa đã đổi tên sau đó.
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
                        vat_percent: Number(it.vat_percent || 0),
                        // Dòng dựng lại từ phiếu cũ chưa biết mặt hàng còn những lô
                        // nào — napDonViChoDongCu hỏi lại rồi ghép vào. Trong lúc
                        // chờ vẫn để Ô CHỌN chứ không mở ô gõ tay: danhSachLo() luôn
                        // nhét lô đang chọn vào danh sách, nên số lô đã lưu hiện ra
                        // đủ. Mở ô gõ ở đây là bày ra một ô RỖNG kèm hai nút Áp dụng
                        // / Đóng cho một dòng vốn đã có số lô.
                        lots: [],
                        lot_number: it.lot_number || '',
                        lo_moi: false,
                        expire_date: (it.expire_date || '').slice(0, 10),
                    });
                });
            }

            napSelectMenus();
            veKieuVat();
            veLichSu(d);
            $m.modal('show');

            if (sua && DONG.length) napDonViChoDongCu();
        }

        /**
         * Dòng dựng lại từ phiếu cũ chỉ mang ĐÚNG đơn vị đã lưu, nên ô chọn đơn vị chỉ
         * có một lựa chọn — sửa phiếu mà không đổi được từ Thùng sang Cái thì coi như
         * không sửa được. Hỏi lại đúng đường ô tìm hàng vẫn dùng rồi ghép danh sách
         * đơn vị vào. Hỏng thì im lặng bỏ qua: mọi thứ khác vẫn sửa được.
         */
        async function napDonViChoDongCu() {
            const sku = [...new Set(DONG.map((d) => d.sku).filter(Boolean))];
            if (!sku.length) return;

            try {
                const ds = await Promise.all(sku.map((s) =>
                    fetch(URL_MAT_HANG + '?keyword=' + encodeURIComponent(s), { headers: { Accept: 'application/json' } })
                        .then((r) => r.json())
                        .then((j) => j.data || [])
                        .catch(() => [])));

                const theoBienThe = new Map();
                ds.flat().forEach((mh) => theoBienThe.set(Number(mh.variant_id), mh));

                let doi = false;
                DONG.forEach((d) => {
                    const mh = theoBienThe.get(Number(d.variant_id));
                    if (!mh || !Array.isArray(mh.units) || !mh.units.length) return;
                    d.units = mh.units;
                    d.base_unit_name = mh.base_unit_name || '';
                    // KHÔNG đụng vào d.lo_moi: lượt hỏi này chạy bất đồng bộ, người
                    // dùng có thể đã bấm "+ Lô mới…" và đang gõ dở. Đặt lại cờ ở đây
                    // là xoá ô gõ ngay dưới tay họ.
                    d.lots = mh.lots || [];
                    doi = true;
                });
                if (doi) veLuoi();
            } catch (e) { /* giữ nguyên ô đơn vị một lựa chọn */ }
        }

        $(document).on('click', '.btn_create', () => moPhieu(null));

        // ---------- Lưu ----------
        //
        /**
         * Lưu phiếu KHÔNG tải lại trang — đúng cách bản v2 làm:
         *
         *     $('#modalCreate').modal('hide');  list(1);  handleMessage(data);
         *
         * Lưu được: đóng hộp, bắn toast, nạp lại danh sách tại chỗ.
         * Lưu hỏng: hộp Ở NGUYÊN cùng mọi thứ vừa gõ — lưới hàng cả chục dòng mà bắt
         * gõ lại từ đầu chỉ vì server từ chối một ô là cách nhanh nhất để người ta bỏ
         * luôn cái phiếu đang lập.
         *
         * KHÔNG dùng V2.luuHop dù nó gần giống: đường này có một kết cục thứ ba —
         * phiếu đã lưu nhưng lượt duyệt kèm theo hỏng. Lúc đó vẫn phải ĐÓNG hộp (để
         * mở là người dùng bấm Lưu lần nữa và đẻ ra phiếu thứ hai) nhưng câu báo phải
         * ĐỎ. V2.luuHop chỉ biết xanh khi 200.
         */
        function luuPhieu($nut, action, method, fields) {
            if ($nut.prop('disabled')) return;
            $nut.prop('disabled', true);

            const fd = new FormData();
            fd.append('_token', CSRF);
            if (method !== 'POST') fd.append('_method', method);
            $.each(fields, (k, v) => fd.append(k, v == null ? '' : v));

            fetch(action, {
                method: 'POST',
                body: fd,
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            })
                .then((res) => res.json().then((body) => ({ ok: res.ok, body: body || {} })))
                .then((r) => {
                    if (!r.ok) {
                        toastr.error(r.body.message || 'Lưu phiếu không thành công.');

                        return;
                    }

                    $mc().modal('hide');
                    (r.body.warning ? toastr.error : toastr.success)(r.body.message || 'Đã lưu.');
                    V2.napLai(location.href, false);
                })
                .catch(() => toastr.error('Không gửi được lượt lưu. Kiểm tra kết nối rồi thử lại.'))
                .finally(() => $nut.prop('disabled', false));
        }

        // Nút nào bấm thì phiếu đi đường đó: Lưu tạm chỉ ghi, Duyệt thì ghi xong gọi
        // tiếp đường duyệt (xem PhieuMuaHangController::store).
        $(document).on('click', '#modalCreate .save-order', function () {
            // Thanh nút giữ CẢ HAI bộ trong khuôn, bộ nào không dùng thì mang d-none.
            // Nút đang ẩn mà vẫn nhận lượt bấm là một lượt lưu thứ hai không ai gọi.
            if ($(this).hasClass('d-none')) return;

            const hopLe = DONG.filter((d) => d.quantity > 0);
            if (!hopLe.length) { toastr.error('Phiếu chưa có dòng hàng nào có số lượng.'); return; }

            // Số lô BẮT BUỘC, đúng như v2. Từ khi lô là một chiều của tồn kho, dòng
            // không có lô đi thẳng vào lô "Không xác định" — và hàng đó về sau không
            // tra ngược được về phiếu nào, đúng việc mà số lô sinh ra để làm.
            const thieuLo = hopLe.find((d) => !String(d.lot_number || '').trim());
            if (thieuLo) {
                toastr.error('"' + thieuLo.product_name + '" chưa chọn số lô.');
                return;
            }

            // Hạn dùng cũng bắt buộc: từ khi lô là một chiều của tồn kho, FEFO rút
            // theo đúng cột này — lô không hạn bị xếp xuống cuối, nên bỏ trống là
            // lô ấy nằm lại trong kho tới lượt cuối cùng mà không ai hiểu vì sao.
            const thieuHan = hopLe.find((d) => !String(d.expire_date || '').trim());
            if (thieuHan) {
                toastr.error('"' + thieuHan.product_name + '" chưa chọn ngày hết hạn.');
                return;
            }

            const le = hopLe.find((d) => Math.abs(d.quantity * d.ratio - Math.round(d.quantity * d.ratio)) > 0.0001);
            if (le) {
                toastr.error('"' + le.product_name + '" quy đổi ra số lẻ — kho chỉ nhận số nguyên. '
                    + 'Đổi số lượng hoặc chọn đơn vị khác.');
                return;
            }

            const theoDong = kieuVat() === 'goods';
            const items = hopLe.map((d) => ({
                variant_id: d.variant_id,
                unit_id: d.unit_id,
                quantity: d.quantity,
                unit_cost: d.unit_cost,
                vat_percent: theoDong ? d.vat_percent : 0,
                lot_number: d.lot_number,
                expire_date: d.expire_date,
            }));

            const $m = $mc();
            luuPhieu($(this), SUA_ID ? URL_BASE + '/' + SUA_ID : URL_STORE, SUA_ID ? 'PUT' : 'POST', {
                id: SUA_ID || '',
                updated_at: SUA_MOC,
                duyet: $(this).data('duyet'),
                supplier_id: $m.find('.supplier_id').val() || 0,
                supplier_name: TEN_NCC,
                document_date: ngayISO($m.find('[name="document_date"]').val()),
                expected_date: ngayISO($m.find('[name="expected_date"]').val()),
                purchaser_id: $m.find('.purchaser_id').val() || 0,
                supplier_delivery_code: $m.find('[name="supplier_delivery_code"]').val() || '',
                vat_mode: kieuVat(),
                vat_percent: vatPhieu(),
                // Chiết khấu và tiền trả không có ô trên màn (giống v2), nhưng API vẫn
                // nhận — giữ hai ô này để payload không đổi hình dạng.
                discount_amount: 0,
                paid_amount: 0,
                note: $m.find('.note').val() || '',
                attachment: ANH_PHIEU,
                items: JSON.stringify(items),
                // Bản đầy đủ của lưới hàng (kèm tên, đơn vị) chỉ để dựng lại hộp thoại
                // khi lưu hỏng. API không đọc ô này.
                items_meta: JSON.stringify(hopLe),
            });
        });

        // =====================================================================
        //  Mở phiếu từ bảng
        //
        //  Một hộp thoại cho cả ba cảnh, đúng như v2: bên đó con mắt và mã phiếu
        //  đều nạp `edit.blade.php` vào `#modalCreate`, rồi chính form ấy tự khoá
        //  từng ô khi phiếu đã duyệt. Không có màn xem riêng, nên người dùng không
        //  phải đi hai nhịp "xem rồi bấm Sửa" cho một phiếu còn đang lưu tạm.
        // =====================================================================
        $(document).on('click', '.detail-item', function () {
            const id = $(this).data('id') || $(this).closest('.item').data('id');
            if (!id) return;

            $.getJSON(URL_BASE + '/' + id)
                .done((res) => moPhieu(res.data || { id }))
                .fail((x) => toastr.error(((x.responseJSON || {}).message) || 'Không đọc được phiếu.'));
        });

        const phieuDangXem = () => $mc().find('.pmh-thanh-nut').data('phieu') || {};

        // =====================================================================
        //  In phiếu
        //
        //  Hai đường vào, MỘT khuôn in: nút In trong hộp phiếu (in đúng cái đang
        //  mở, kể cả khi chưa lưu), và Nâng cao → In ngoài bảng (in những phiếu
        //  ĐÃ TICK, một hay nhiều đều được).
        //
        //  Trước đây Nâng cao → In gọi thẳng window.print(), tức là in cả trang
        //  quản trị: thanh điều hướng, khung lọc, phân trang. Không ai cầm tờ ấy
        //  đi đối chiếu với nhà cung cấp được.
        // =====================================================================

        /** Bản in gom từ HỘP PHIẾU đang mở — in được cả phiếu chưa lưu. */
        function baoPhieu() {
            const $m = $mc();
            const p = phieuDangXem();
            const dong = DONG.map((d, i) => {
                const tienHang = Math.round(d.unit_cost * d.quantity);
                const vat = vatCuaDong(d);
                const thue = Math.round(tienHang * Math.max(0, vat) / 100);

                return {
                    stt: i + 1,
                    sku: d.sku || '',
                    ten: [d.product_name, d.variant_name].filter(Boolean).join(' · '),
                    donVi: (d.units.find((u) => Number(u.unit_id) === d.unit_id) || {}).name
                        || d.base_unit_name || '',
                    sl: d.quantity,
                    gia: d.unit_cost,
                    tienHang,
                    vat,
                    thue,
                    cong: tienHang + thue,
                    lo: d.lot_number || '',
                    han: ngayVN(d.expire_date),
                };
            });

            return congBao({
                ma: p.po_code || '',
                ncc: p.supplier_name || TEN_NCC || '',
                ngay: $m.find('[name="document_date"]').val() || '',
                hen: $m.find('[name="expected_date"]').val() || '',
                ghiChu: $m.find('.note').val() || '',
                trangThai: $m.find('[name="po_status"]').val() || '',
                dong,
            });
        }

        /** Bản in gom từ một phiếu ĐÃ LƯU, đọc thẳng từ API. */
        function baoTuPhieu(p) {
            const dong = (p.items || []).map((it, i) => {
                const sl = Number(it.quantity || 0);
                const gia = Number(it.unit_cost || 0);
                const vat = Number(it.vat_percent || 0);
                const tienHang = Math.round(gia * sl);

                return {
                    stt: i + 1,
                    sku: it.variant_sku || '',
                    ten: [it.product_name, it.variant_name].filter(Boolean).join(' · '),
                    donVi: it.unit_name || '',
                    sl,
                    gia,
                    tienHang,
                    vat,
                    thue: Math.round(tienHang * Math.max(0, vat) / 100),
                    cong: tienHang + Math.round(tienHang * Math.max(0, vat) / 100),
                    lo: it.lot_number || '',
                    han: ngayVN(it.expire_date),
                };
            });

            return congBao({
                ma: p.po_code || '',
                ncc: p.supplier_name || '',
                ngay: ngayVN(p.document_date),
                hen: ngayVN(p.expected_date),
                ghiChu: p.note || '',
                trangThai: NHAN_TRANG_THAI[p.status] || p.status || '',
                dong,
            });
        }

        /** Cộng ba con số tổng — chỗ duy nhất cộng, để hai đường vào không lệch nhau. */
        function congBao(b) {
            b.tienHang = b.dong.reduce((t, d) => t + d.tienHang, 0);
            b.thue = b.dong.reduce((t, d) => t + d.thue, 0);
            b.cong = b.dong.reduce((t, d) => t + d.cong, 0);

            return b;
        }

        const KIEU_IN =
            'body{font:13px/1.5 system-ui,Arial,sans-serif;color:#1a2b58;padding:24px}'
            + '.to{page-break-after:always}.to:last-child{page-break-after:auto}'
            + 'h1{font-size:19px;margin:0 0 4px}.ph{color:#6c757d;margin:0 0 16px}'
            + '.ho{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:4px 24px;margin-bottom:16px}'
            + '.ho b{display:inline-block;min-width:130px}'
            + 'table{width:100%;border-collapse:collapse}'
            + 'th,td{border:1px solid #cfd6e4;padding:5px 7px}'
            + 'th{background:#f4f6f8;font-size:12px}.p{text-align:right}.g{text-align:center}'
            + '.tg{margin-top:14px;width:auto;margin-left:auto}'
            + '@media print{body{padding:0}}';

        /**
         * Một tờ phiếu. Nhiều phiếu thì mỗi tờ một trang giấy.
         *
         * Hộ kinh doanh nộp thuế TRỰC TIẾP thì bỏ hẳn ba cột VAT khỏi tờ in —
         * màn hình đã giấu chúng, in ra một dải "0%" là mâu thuẫn với chính
         * chứng từ người ta vừa xem.
         */
        function toPhieu(b) {
            const cotVat = (noiDung) => (THUE_TRUC_TIEP ? '' : noiDung);

            const hang = b.dong.map((d) => '<tr>'
                + '<td class="g">' + d.stt + '</td><td>' + esc(d.sku) + '</td><td>' + esc(d.ten) + '</td>'
                + '<td class="g">' + esc(d.donVi) + '</td><td class="p">' + nhomSo(d.sl) + '</td>'
                + '<td class="p">' + nhomSo(d.gia) + '</td><td class="p">' + nhomSo(d.tienHang) + '</td>'
                + cotVat('<td class="p">' + d.vat + '%</td><td class="p">' + nhomSo(d.thue) + '</td>'
                    + '<td class="p"><b>' + nhomSo(d.cong) + '</b></td>')
                + '<td class="g">' + esc(d.lo) + '</td><td class="g">' + esc(d.han) + '</td></tr>').join('');

            return '<div class="to">'
                + '<h1>{{ __('message.purchase_order') }} ' + esc(b.ma) + '</h1>'
                + '<p class="ph">' + esc(b.trangThai) + '</p>'
                + '<div class="ho">'
                    + '<div><b>{{ __('message.supplier') }}:</b> ' + esc(b.ncc) + '</div>'
                    + '<div><b>{{ __('message.document_date') }}:</b> ' + esc(b.ngay) + '</div>'
                    + '<div><b>{{ __('message.expiry_date') }}:</b> ' + esc(b.hen || '—') + '</div>'
                    + '<div><b>{{ __('message.note') }}:</b> ' + esc(b.ghiChu || '—') + '</div>'
                + '</div>'
                + '<table><thead><tr><th>#</th><th>{{ __('message.menu-code') }}</th>'
                    + '<th>{{ __('message.menu-name') }}</th><th>{{ __('message.unit') }}</th>'
                    + '<th>{{ __('message.quantity') }}</th><th>{{ __('message.import_price') }}</th>'
                    + '<th>' + (THUE_TRUC_TIEP
                        ? '{{ __('message.money_into') }}' : '{{ __('message.subtotal_before_vat') }}') + '</th>'
                    + cotVat('<th>VAT</th><th>{{ __('message.pch_ord_VAT') }}</th>'
                        + '<th>{{ __('message.total_money') }}</th>')
                    + '<th>{{ __('message.batch_number') }}</th><th>{{ __('message.expiry_date') }}</th>'
                + '</tr></thead><tbody>' + hang + '</tbody></table>'
                + '<table class="tg"><tbody>'
                    + cotVat('<tr><th>{{ __('message.total_subtotal_before_vat') }}</th>'
                        + '<td class="p">' + nhomSo(b.tienHang) + '</td></tr>'
                        + '<tr><th>{{ __('message.pch_ord_VAT') }}</th>'
                        + '<td class="p">' + nhomSo(b.thue) + '</td></tr>')
                    + '<tr><th>{{ __('message.total_money') }}</th><td class="p"><b>' + nhomSo(b.cong) + '</b></td></tr>'
                + '</tbody></table></div>';
        }

        /** Mở cửa sổ in với một hay nhiều tờ phiếu. */
        function inCacTo(ds) {
            const w = window.open('', '_blank');
            if (!w) { toastr.error('Trình duyệt đang chặn cửa sổ in.'); return; }

            const ten = ds.length === 1
                ? '{{ __('message.purchase_order') }} ' + ds[0].ma
                : '{{ __('message.purchase_order') }} (' + ds.length + ')';

            w.document.write('<!doctype html><html lang="vi"><head><meta charset="utf-8">'
                + '<title>' + esc(ten) + '</title><style>' + KIEU_IN + '</style></head><body>'
                + ds.map(toPhieu).join('')
                + '</body></html>');
            w.document.close();
            w.focus();
            w.print();
        }

        $(document).on('click', '#modalCreate .pmh-in', function () {
            inCacTo([baoPhieu()]);
        });

        // Nâng cao → In: phải TICK dòng trước. In cả trang danh sách thì mỗi tờ
        // in ra là một bản chụp màn hình, không phải chứng từ.
        $(document).on('click', '.btn_print_list', function () {
            const ids = idsDaChon();
            if (!ids.length) {
                toastr.error('Chọn phiếu muốn in ở cột đầu bảng đã.');

                return;
            }

            // Đọc theo ĐÚNG thứ tự đã tick, và chỉ in khi đọc được HẾT: in thiếu
            // một tờ giữa chừng mà không ai hay còn tệ hơn không in.
            Promise.all(ids.map((id) => $.getJSON(URL_BASE + '/' + id).then((r) => r.data || {})))
                .then((ds) => inCacTo(ds.map(baoTuPhieu)))
                .catch(() => toastr.error('Không đọc được phiếu để in.'));
        });

        // Xuất Excel: đi qua máy chủ chứ không dựng tệp ở trình duyệt.
        //
        // Dựng ở đây thì chỉ ra được CSV — muốn .xlsx thật phải kéo một thư viện
        // từ CDN về. Máy chủ đã có XlsxDon dựng bằng ZipArchive sẵn có của PHP,
        // và đằng nào tệp này cũng phải là bản ĐÃ LƯU: nó rời khỏi phần mềm, gửi
        // cho kế toán hay bên bán, nên phải khớp với chứng từ trong sổ. (Nút In
        // thì ngược lại — in đúng cái đang nhìn thấy, kể cả chưa lưu.)
        $(document).on('click', '#modalCreate .pmh-excel', function () {
            if (!SUA_ID) { toastr.error('Lưu phiếu rồi mới xuất được tệp.'); return; }
            window.location = URL_BASE + '/' + SUA_ID + '/export';
        });

        $(document).on('click', '#modalCreate .pmh-tra', function () {
            $mc().modal('hide');
            moTra(phieuDangXem());
        });
        // Huỷ đứng cạnh Xoá ở cột Hành động — cùng một họ việc, và cùng chỉ hợp lệ
        // với phiếu lưu tạm nên khuôn chỉ vẽ hai icon ấy cho dòng lưu tạm.
        $(document).on('click', '.cancel-item', function () {
            const id = $(this).closest('.item').data('id');
            moHuy(ROWS[id] || { id });
        });
        // =====================================================================
        //  Duyệt · huỷ · thanh toán · xoá
        // =====================================================================
        const idsDaChon = () => [...new Set($('.item-select:checked').map((i, el) => el.value).get())].filter(Boolean);

        /** Chỉ phiếu lưu tạm mới duyệt / xoá được — API từ chối phần còn lại. */
        const chiLuuTam = (ids) => ids.filter((id) => (ROWS[id] || {}).status === 'draft');

        // Không còn đường DUYỆT HÀNG LOẠT từ màn này: nút Duyệt ngoài bảng đã gỡ,
        // và duyệt từng phiếu nằm trong hộp phiếu (nút Duyệt trên thanh đầu).
        // Đường API gộp `bulkApprove` vẫn còn bên máy chủ, chỉ là giao diện này
        // không gọi nữa.

        function moHuy(p) {
            $('#modalCancel').data('id', p.id);
            $('#modalCancel .cancel_info').text('Phiếu ' + (p.po_code || '')
                + ' đang ở trạng thái lưu tạm nên chưa đụng tới kho. Huỷ xong phiếu vẫn nằm lại trong sổ để tra cứu.');
            $('#modalCancel .cancel_note').val('');
            $('#modalCancel').modal('show');
        }

        $(document).on('click', '.save-cancel', function () {
            const lyDo = $('#modalCancel .cancel_note').val().trim();
            if (!lyDo) { toastr.error('Vui lòng nói rõ vì sao huỷ phiếu.'); return; }
            postForm(URL_BASE + '/' + $('#modalCancel').data('id') + '/cancel', 'POST', { note: lyDo });
        });

        // =====================================================================
        //  Thanh toán — dựng theo hộp `paymentModal` của bản order v2
        //
        //  Luật của bên đó, giữ nguyên từng cái một:
        //
        //    • Số tiền không vượt quá tổng — gõ quá thì kéo về đúng tổng.
        //    • Gõ THIẾU thì tự bật Ghi nợ; gõ ĐỦ thì tự tắt. Người dùng chỉ khai
        //      một con số, hộp thoại tự suy ra đây là trả đủ hay còn nợ.
        //    • Bật Ghi nợ bằng tay thì số tiền về 0 (nợ trọn), hạn nợ mặc định
        //      cộng thêm 7 ngày.
        //
        //  Khác v2 một chỗ và CỐ Ý: hộp này không tự dọn ô ghi chú và ảnh chứng
        //  từ mỗi lần bật/tắt ghi nợ. Bên đó gõ ghi chú xong lỡ tay tick một cái
        //  là mất trắng.
        // =====================================================================
        const NGAY_NO_MAC_DINH = 7;

        const $hopTra = () => $('#modalPayment');
        const tongCuaHopTra = () => soN($hopTra().find('.pay_total').val());

        /** Bày / giấu ba ô chỉ có nghĩa khi ghi nợ. */
        function veKhoiNo(coNo) {
            $hopTra().find('.pay-khoi-no').toggleClass('d-none', !coNo);
        }

        /** Đặt hạn nợ theo SỐ NGÀY kể từ hôm nay. */
        function hanNoTheoNgay(soNgay) {
            const n = Math.max(1, Math.min(365, Number(soNgay) || NGAY_NO_MAC_DINH));
            $hopTra().find('.pay_debt_days').val(n);
            $hopTra().find('.pay_debt_date').val(moment().add(n, 'days').format(KHUON_NGAY));
        }

        function veAnhTra(url) {
            const $m = $hopTra();
            $m.data('anh', url || '');
            $m.find('.pay-anh-chu').text(url ? '{{ __('message.edit') }}' : '{{ __('message.upload') }}');
            $m.find('.pay-anh-xem').toggleClass('d-none', !url).attr('href', url || '#');
            $m.find('.pay-anh-go').toggleClass('d-none', !url);
        }

        function moTra(p) {
            const tong = Number(p.total_amount || 0);
            const daTra = Number(p.paid_amount || 0);
            const $m = $hopTra();

            $m.data('id', p.id);
            $m.find('.pay_info').text('Phiếu ' + (p.po_code || '') + ' · tổng ' + tien(tong)
                + ' · đã trả ' + tien(daTra) + ' · còn nợ ' + tien(Math.max(0, tong - daTra)) + '.');
            $m.find('.pay_total').val(nhomSo(tong));
            // Mở ra là điền sẵn TRẢ ĐỦ — chín trên mười lần người ta bấm Thanh
            // toán là để trả nốt. Muốn nợ thì sửa con số xuống, ghi nợ tự bật.
            $m.find('.pay_amount').val(nhomSo(tong));
            $m.find('.pay_method').val(p.payment_method || 'cash');
            $m.find('.pay_note').val('');
            veAnhTra(p.payment_attachment || '');

            // Phiếu đang mang sẵn một thoả thuận nợ thì mở lại đúng thoả thuận ấy.
            const coNo = !!p.is_debt;
            $m.find('.pay_debt').prop('checked', coNo);
            $m.find('.pay_contact_name').val(p.debt_contact_name || '');
            $m.find('.pay_contact_phone').val(p.debt_contact_phone || '');
            if (coNo && p.debt_due_date) {
                $m.find('.pay_debt_date').val(ngayVN(p.debt_due_date));
                $m.find('.pay_debt_days').val(
                    Math.max(1, moment(String(p.debt_due_date).slice(0, 10)).diff(moment(), 'days')));
                $m.find('.pay_amount').val(nhomSo(daTra));
            } else {
                hanNoTheoNgay(NGAY_NO_MAC_DINH);
            }
            veKhoiNo(coNo);

            $m.modal('show');
        }

        $(document).on('input', '#modalPayment .pay_amount', function () {
            const phai = viTriTuPhai(this);
            const tong = tongCuaHopTra();
            let tra = soN(this.value);

            // Trả quá tổng thì kéo về đúng tổng, y như v2.
            if (tra > tong) tra = tong;
            this.value = nhomSo(tra);
            datConTro(this, phai);

            // Thiếu thì tự bật ghi nợ, đủ thì tự tắt.
            const $tick = $hopTra().find('.pay_debt');
            if (tra < tong && !$tick.is(':checked')) {
                $tick.prop('checked', true);
                hanNoTheoNgay($hopTra().find('.pay_debt_days').val());
                veKhoiNo(true);
            } else if (tra >= tong && $tick.is(':checked')) {
                $tick.prop('checked', false);
                veKhoiNo(false);
            }
        });

        $(document).on('change', '#modalPayment .pay_debt', function () {
            const coNo = $(this).is(':checked');
            veKhoiNo(coNo);
            if (coNo) {
                // Bật bằng tay = nợ trọn gói. Ai trả một phần thì sửa lại con số.
                $hopTra().find('.pay_amount').val(0);
                hanNoTheoNgay($hopTra().find('.pay_debt_days').val());
            } else {
                $hopTra().find('.pay_amount').val(nhomSo(tongCuaHopTra()));
            }
        });

        // Gõ số ngày thì ngày cụ thể nhảy theo. Chiều ngược lại KHÔNG tính lại số
        // ngày: người ta chọn thẳng một ngày trên lịch là đã nói rõ ý, sửa con số
        // bên cạnh chỉ làm ô nhảy lung tung dưới tay họ.
        $(document).on('input', '#modalPayment .pay_debt_days', function () {
            hanNoTheoNgay(this.value);
        });

        $(document).on('change', '#modalPayment .pay_file', function () {
            const f = this.files && this.files[0];
            if (!f) return;

            const fd = new FormData();
            fd.append('anh', f);
            fd.append('_token', CSRF);
            fetch(URL_ANH, { method: 'POST', body: fd, headers: { Accept: 'application/json' } })
                .then((r) => r.json())
                .then((j) => {
                    if (!j.url) throw new Error(j.message || 'Tải ảnh hỏng.');
                    veAnhTra(j.url);
                })
                .catch((e) => toastr.error(e.message || 'Tải ảnh hỏng.'))
                .finally(() => { this.value = ''; });
        });

        $(document).on('click', '#modalPayment .pay-anh-go', () => veAnhTra(''));

        $(document).on('click', '.save-payment', function () {
            const $m = $hopTra();
            const coNo = $m.find('.pay_debt').is(':checked');
            const tong = tongCuaHopTra();
            const tra = soN($m.find('.pay_amount').val());

            // Ba lượt soát của v2. Server soát lại y hệt — đây chỉ để người dùng
            // biết ngay chứ không phải đợi một vòng gọi đi gọi về.
            if (coNo && tra >= tong) {
                toastr.error('Đã trả đủ thì không còn gì để ghi nợ.');

                return;
            }
            if (!coNo && tra !== tong) {
                toastr.error('Không ghi nợ thì số tiền thanh toán phải bằng tổng tiền phiếu.');

                return;
            }
            if (coNo && (!$m.find('.pay_contact_name').val().trim() || !$m.find('.pay_contact_phone').val().trim())) {
                toastr.error('Ghi nợ thì phải có người đại diện bên bán và số điện thoại.');

                return;
            }

            postForm(URL_BASE + '/' + $m.data('id') + '/payment', 'POST', {
                paid_amount: tra,
                note: $m.find('.pay_note').val() || '',
                payment_method: $m.find('.pay_method').val() || 'cash',
                payment_attachment: $m.data('anh') || '',
                is_debt: coNo ? 1 : 0,
                debt_due_date: coNo ? ngayISO($m.find('.pay_debt_date').val()) : '',
                debt_contact_name: coNo ? $m.find('.pay_contact_name').val().trim() : '',
                debt_contact_phone: coNo ? $m.find('.pay_contact_phone').val().trim() : '',
            });
        });

        $(document).on('click', '.delete-item', function () {
            $('#deleteValue').val($(this).closest('.item').data('id'));
            $('#deleteItem').modal('show');
        });

        $(document).on('click', '.mass-delete', function () {
            // Bảng và dãy thẻ điện thoại cùng dựng một danh sách nên id hay lặp.
            const ids = chiLuuTam(idsDaChon());
            if (!ids.length) { toastr.error('{{ __('message.delete-none') }}'); return; }
            $('#deleteValue').val(ids.join(','));
            $('#deleteItem').modal('show');
        });

        $(document).on('click', '.delete-value', function () {
            const ids = String($('#deleteValue').val()).split(',').filter(Boolean);
            if (!ids.length) return;
            ids.length === 1
                ? postForm(URL_BASE + '/' + ids[0], 'DELETE', {})
                : postForm(URL_BULK_DEL, 'POST', { ids });
        });

        // =====================================================================
        //  Thêm nhanh nhà cung cấp ngay trong hộp lập phiếu
        // =====================================================================
        const $ncc = () => $('#modalCreateSupplier');

        $(document).on('click', '#modalCreate .add_supplier', function () {
            const $m = $ncc();
            $m.find('input[type=text], input[type=email], textarea').val('');
            $m.find('.ip_image').val('');
            $m.find('.ip_img').val('');
            $m.find('.ip_status').prop('checked', true);
            $m.find('#img-preview-ncc').attr('src', ANH_MAC_DINH);
            $m.modal('show');
        });

        $(document).on('change', '#modalCreateSupplier .ip_img', function () {
            const f = this.files[0];
            if (!f) return;
            const fd = new FormData();
            fd.append('anh', f);
            fd.append('_token', CSRF);
            $.ajax({ url: URL_NCC_ANH, method: 'POST', data: fd, contentType: false, processData: false })
                .done((r) => {
                    $ncc().find('.ip_image').val(r.url);
                    $ncc().find('#img-preview-ncc').attr('src', r.url);
                })
                .fail((x) => toastr.error(((x.responseJSON || {}).message) || 'Không tải được ảnh lên.'));
        });

        $(document).on('click', '.save-supplier', function () {
            const $m = $ncc();
            const ten = $m.find('.ip_name').val().trim();
            const diaChi = $m.find('.ip_address').val().trim();
            if (!ten) { toastr.error('Chưa nhập tên nhà cung cấp.'); return; }
            if (!diaChi) { toastr.error('Chưa nhập địa chỉ.'); return; }

            const $nut = $(this).prop('disabled', true);
            $.ajax({
                url: URL_NCC_NHANH,
                method: 'POST',
                contentType: 'application/json',
                headers: { Accept: 'application/json', 'X-CSRF-TOKEN': CSRF },
                data: JSON.stringify({
                    code: $m.find('.ip_code').val().trim(),
                    name: ten,
                    short_name: $m.find('.ip_short_name').val().trim(),
                    phone: $m.find('.ip_phone').val().trim(),
                    email: $m.find('.ip_email').val().trim(),
                    tax_code: $m.find('.ip_tax_code').val().trim(),
                    representative_name: $m.find('.ip_representative_name').val().trim(),
                    representative_phone: $m.find('.ip_representative_phone').val().trim(),
                    address: diaChi,
                    address_line2: $m.find('.ip_address_line2').val().trim(),
                    note: $m.find('.ip_note').val().trim(),
                    image: $m.find('.ip_image').val(),
                    status: $m.find('.ip_status').is(':checked') ? 1 : 0,
                }),
            })
                .done((r) => {
                    // Nhét thẳng vào ô chọn và chọn luôn: người dùng vừa khai bên bán
                    // này là để dùng cho đúng phiếu đang gõ dở.
                    const s = r.data || {};
                    const o = new Option((s.code ? s.code + ' - ' : '') + (s.name || ''), s.id, true, true);
                    o.dataset.name = s.name || '';
                    o.dataset.phone = s.phone || '';
                    o.dataset.address = s.address || '';
                    o.dataset.address2 = s.address_line2 || '';
                    o.dataset.repPhone = s.representative_phone || '';
                    $mc().find('.supplier_id').append(o).val(String(s.id)).trigger('change.select2');
                    veHoSoNCC();
                    $m.modal('hide');
                    toastr.success('Đã thêm nhà cung cấp.');
                })
                .fail((x) => {
                    const b = x.responseJSON || {};
                    const theoO = Object.keys(b.errors || {}).map((k) => [].concat(b.errors[k]).join(' ')).join(' ');
                    toastr.error(theoO || b.message || 'Thêm nhà cung cấp không thành công.');
                })
                .always(() => $nut.prop('disabled', false));
        });

        // =====================================================================
        //  Lưu hỏng thì mở lại hộp thoại kèm ĐÚNG những gì vừa gõ, cả lưới hàng.
        //  Bắt người ta chọn lại từng dòng chỉ vì server từ chối một ô là cách
        //  nhanh nhất để họ bỏ luôn phiếu đang lập.
        // =====================================================================
        @if (old('items'))
            $(function () {
                const cu = @json(old());

                // Đang SỬA thì mở lại đúng ở chế độ sửa. Mở ở chế độ thêm là lượt gửi
                // sau đẻ ra phiếu nháp thứ hai, còn phiếu gốc thì vẫn nguyên như cũ.
                const id = Number(cu.id) || 0;
                moPhieu(id ? { id } : null);

                const $m = $mc();
                $m.find('.supplier_id').val(String(cu.supplier_id || 0)).trigger('change.select2');
                $m.find('[name="document_date"]').val(ngayVN(cu.document_date));
                $m.find('[name="expected_date"]').val(ngayVN(cu.expected_date));
                $m.find('.purchaser_id').val(String(cu.purchaser_id || 0)).trigger('change.select2');
                $m.find('[name="supplier_delivery_code"]').val(cu.supplier_delivery_code || '');
                $m.find('.note').val(cu.note || '');
                veHoSoNCC();
                $m.find('.vat_mode').val(cu.vat_mode || 'order');
                $m.find('.vat_percent').val(String(cu.vat_percent == null ? 0 : cu.vat_percent));
                veAnh(cu.attachment || '');

                try {
                    const dong = JSON.parse(cu.items_meta || '[]');
                    if (Array.isArray(dong) && dong.length) {
                        DONG = dong.map((d) => Object.assign({}, d, { key: ++dongSeq }));
                    }
                } catch (e) { /* mất lưới hàng còn hơn mất cả trang */ }

                veKieuVat();
            });
        @endif
    </script>
@endpush
