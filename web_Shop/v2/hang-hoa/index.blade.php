{{-- Màn Danh sách hàng hoá dựng theo khuôn v2 (menu/menu/index).

     Khung lọc, bảng và hộp Tạo/Sửa đều chép theo bản v2 cũ; hộp Tạo/Sửa nằm
     riêng ở _modal.blade.php + _modal_js.blade.php cho dễ đọc.

     Dữ liệu do ProductController đẩy sang — xem render(). --}}
@extends('v2::layouts.master')

@section('title', \App\Http\Controllers\ProductController::TITLE_PAGE)

@push('styles')
    <style>
        /* ================= CHÉP NGUYÊN CSS CỦA MÀN HÀNG HOÁ BÊN V2 =================
           Lấy đúng khối <style> trong menu/menu/index.blade.php của bản v2 (phần
           dùng chung + phần của hộp thoại). Bỏ đi phần topping / combo / sản
           phẩm dịch vụ tính giờ vì bán lẻ không có mấy thứ đó.

           Đặt TRƯỚC mấy luật riêng của mình ở dưới, để chỗ nào cố ý làm khác thì
           luật của mình vẫn thắng. */
        .tooltip-price-note .tooltip-inner {
            max-width: 320px;
            text-align: left;
        }

        /* phải là i.fa.price-note-tip: theme có "i.fa { font-size: 22px }" thắng nếu chỉ dùng 1 class */
        i.fa.price-note-tip {
            font-size: 13px;
            cursor: help;
            opacity: .7;
            vertical-align: middle;
            margin-left: 4px;
        }

        .table-custom thead th {
            position: sticky !important;
            top: 0 !important;
        }

        .btn-upload {
            width: 128px;
        }

        #table-detail-customer td {
            text-align: left !important
        }

        .nav-detail {
            padding: 5px 0px 0px 5px;
            background-color: #486a7f;
        }

        .nav-detail button {
            color: #fff;
            font-weight: bold;
            border-top-left-radius: 4px;
            border-top-right-radius: 4px;
            padding: 8px;
            min-width: 120px;
            border: 0 !important;
        }

        .nav-detail button.active {
            color: #1A2B58 !important;
        }

        .nav-detail button:hover {
            color: #1A2B58 !important;
            background-color: #fff
        }

        .btn-collapse {
            /* background-color: #cfe2ff; */
            padding: 6px;
            font-size: 14px
        }

        .detail_attribute {
            padding: 10px;
            border: #d9d9d9 1px solid;
            border-radius: 5px
        }

        .scroll-height {
            max-height: calc(100vh - 100px);
            overflow: auto
        }

        .time-range-group {
            display: flex;
            align-items: center;
            margin-bottom: 10px;
        }

        .time-range-group input,
        .time-range-group select {
            margin-right: 10px;
        }

        .time-range-group i {
            margin: 0 10px;
        }

        .title-search {
            font-size: 16px;
            font-weight: 500;
            color: #7083b6;
            margin-bottom: 5px
        }

        .label-custom {
            font-size: 13px;
        }

        /* [SYNC-QUY-TAC] màu icon thao tác trong table theo chuẩn admin (edit xanh dương, copy xanh nhạt,
           công thức cam, mũi tên xám). Bản cashier có rule .order-cashier-menu-page (3 class) thắng nên giữ màu xanh riêng. */
        td.action .btn-edit { color: #3f67d0; }
        td.action .btn-copy { color: #57abdc; }
        td.action .btn-edit-recipe { color: #f29220; }
        /* Icon xóa = dấu X theo chuẩn dele_bt: đỏ #ff0000, phóng 1.18 cho khỏi lép (glyph fa-times nhỏ hơn edit/copy) */
        td.action .btn-delete { color: #ff0000 !important; transform: scale(1.18); }
        td.action i.fa-times.text-muted { transform: scale(1.18); }
        td.action .sort-item i { color: #6c757d; }
        td.action .sort-item { margin-right: 5px; }
        td.action .sort-item i.fa-arrow-up { margin-right: 5px; }

        /* Nút "Thêm" đơn vị trong accordion: btn-sm khiến padding ngang quá hẹp so với chiều cao 30px → cho padding đều + căn giữa */
        #modalCreate .add-unit {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0 14px;
        }

        .form-check {
            width: 100%;
        }

        .hide {
            display: none;
        }

        .scrollDiv {
            max-height: calc(100vh - 180px);
            overflow: auto
        }

        a.model_destroy_menu:hover {
            color: #fff !important;
        }

        a.mass-delete:hover {
            background-color: #FF7979 !important;
            color: #fff !important;
        }


        #modalCreate .content label,
        #modalCombo .content label,
        #modalService .content label,
        .accordion-button {
            font-weight: bold;
            color: #011e44;
        }

        .remove_attr_detail,
        .remove_item,
        .upload_pic {
            cursor: pointer;
        }

        .attr-drag-handle {
            cursor: grab;
            color: #6c757d;
            touch-action: none;
        }

        .attr-drag-handle:active {
            cursor: grabbing;
        }

        .attr-detail-row.ui-sortable-helper {
            background: #fff;
            box-shadow: 0 2px 6px rgba(0, 0, 0, .15);
        }

        /* Bảng danh sách món kéo thả cả dòng để sắp xếp (không có handle riêng) - chặn cử chỉ
           cuộn/pull-to-refresh của trình duyệt ngay tại điểm chạm để không bị load lại trang khi kéo */
        #sortableTable tbody tr.item {
            touch-action: none;
        }

        button.btn_green, button.btn_advanced {
            border: none;
            cursor: pointer;
            border-radius: 6px;
            min-height: 32px;
            color: #fff;
            font-weight: 500;
            padding: 5px 10px;

        }

        .create-optitons button {
            max-height: 32px;
            background: #07B29B !important;
            color: white !important;
            font-weight: bold !important;
            font-size: 14px;
            padding: 5px 10px !important;
        }

        .create-optitons ul {
            padding: 0
        }

        .create-optitons .dropdown-item {
            padding: 10px;
            color: #fff !important;
            font-size: 14px;
            background: #16bfa9 !important;
        }

        .create-optitons .dropdown-item:hover {
            background: #31c8b5 !important;
        }

        .time {
            border: 1px solid #D9D9D9;
        }

        /* ================= Nắn riêng của bản này ================= */
        /* Gỡ mấy đường viền bao quanh hộp thoại.

           style.css của v2 kẻ `border: 1px solid #dbdbdb` quanh .modal-content,
           còn Bootstrap kẻ thêm một đường dưới đầu hộp và một đường trên chân
           hộp. Ba đường đó cộng lại thành mấy lớp khung lồng nhau — thấy rõ nhất
           khi hộp cao hết màn mà nội dung chỉ chiếm nửa trên. Bỏ hết, giữ lại
           bóng đổ để hộp vẫn tách khỏi nền. */
        #modalCreate .modal-content {
            border: 0 !important;
            box-shadow: 0 8px 32px rgba(0, 0, 0, .25);
        }
        #modalCreate .modal-header { border-bottom: 0; }

        /* ---------- Bảng thuộc tính (khuôn v2) ----------
           Mỗi thuộc tính là một hàng; ruột hàng là nhiều dòng chi tiết, mỗi dòng
           một giá trị. Nhãn con ("Mặc định / Chi tiết / Giá trị cộng thêm") nằm
           MỘT LẦN trên <thead>, dùng chung đúng bộ bề rộng dưới đây nên thẳng
           cột với các dòng dữ liệu. */
        #modalCreate #add-attribute > tr > td { vertical-align: middle; padding: 8px 10px; }
        #modalCreate .tt-dau,
        #modalCreate .tt-dong { display: flex; align-items: center; gap: 10px; }
        #modalCreate .tt-dong + .tt-dong { margin-top: 8px; }

        /* Bốn ô cùng một bộ bề rộng ở cả tiêu đề lẫn dòng dữ liệu. */
        #modalCreate .tt-o-keo  { flex: 0 0 18px; text-align: center; }
        #modalCreate .tt-o-md   { flex: 0 0 72px; text-align: center; }
        #modalCreate .tt-o-gt   { flex: 1 1 0; min-width: 150px; }
        #modalCreate .tt-o-them { flex: 1 1 0; min-width: 150px; }

        #modalCreate .tt-dau { font-weight: 600; color: #011e44; }
        /* Tầng nhãn con nhẹ hơn tầng tên cột, để mắt phân được hai tầng. */
        #modalCreate .tt-dau-o { padding-top: 0; font-size: 12px; font-weight: 500; }
        #modalCreate .tt-o-keo { color: #ced4da; cursor: grab; }
        #modalCreate .tt-o-keo:active { cursor: grabbing; }
        #modalCreate .tt-dong.tt-dang-keo { opacity: .4; }

        /* Hai ô nhập cùng chiều cao với ô chọn bên cạnh, không cái cao cái thấp. */
        #modalCreate .tt-dong .form-control,
        #modalCreate .tt-dong .form-select { height: 34px; }

        /* Cột Hành động: dấu cộng thêm một dòng, dấu trừ bớt một dòng. Hai dấu
           cùng cỡ, cùng hàng, canh giữa — không lẫn với dấu xoá của dòng. */
        #modalCreate .tt-thao-tac { text-align: center; white-space: nowrap; }
        #modalCreate .tt-thao-tac i {
            width: 26px;
            height: 26px;
            line-height: 26px;
            font-size: 12px;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            background: #fff;
        }
        #modalCreate .tt-them-dong { color: #0d6efd; }
        #modalCreate .tt-bot-dong { color: #dc3545; margin-left: 6px; }
        #modalCreate .tt-thao-tac i:hover { background: #f1f3f5; }

        /* Chỉ cuộn khi nội dung THẬT SỰ dài quá màn.
           `max-height` chứ không phải `height`: hộp ít ô thì co đúng theo
           nội dung, không thanh cuộn, không khoảng trống thừa phía dưới.
           Trừ hao ~200px cho đầu hộp, chân hộp và lề của modal. */
        #modalCreate .modal-body {
            max-height: calc(100vh - 200px);
            overflow-y: auto;
        }

        /* Chế độ CHỈ XEM: giấu mọi nút làm thay đổi nội dung. Khoá ô thôi chưa
           đủ — mấy nút này không phải ô nhập nên `disabled` không giấu được
           chúng khỏi mắt người dùng. */
        #modalCreate.dang-xem .add-unit,
        #modalCreate.dang-xem .close-all-unit,
        #modalCreate.dang-xem .add-attribute-select,
        #modalCreate.dang-xem .qd-xoa,
        #modalCreate.dang-xem .tt-xoa,
        #modalCreate.dang-xem .upload_pic { display: none !important; }

        /* Câu nói rõ vì sao một ô chọn đang rỗng — "chưa khai" khác hẳn "máy chủ
           từ chối", mà ô trắng trơn thì hai ca nhìn y như nhau. */
        #modalCreate .loi-nap {
            display: block;
            margin-top: 4px;
            font-size: 11px;
            line-height: 1.4;
            color: #d4380d;
        }
        #modalCreate .modal-footer { border-top: 0; }

        /* Quét sạch viền của MỌI lớp bọc bên trong hộp — thân hộp, khối .content,
           các tab-pane, hàng hai cột. Đây là mấy vạch dọc chạy suốt chiều cao hộp
           ở sát mép trái và mép phải: chúng là viền của lớp bọc chứ không phải
           của .modal-content, nên gỡ .modal-content không hết.

           Chừa ra hai thứ CÓ viền là cố ý: khối Quy đổi / Thuộc tính, và các ô
           nhập (.form-control, .form-select). */
        #modalCreate .modal-body,
        #modalCreate .content,
        #modalCreate .tab-pane,
        #modalCreate .nav-normal-info-container,
        #modalCreate .data-body,
        #modalCreate .data-body-container,
        #modalCreate .img_st {
            border: 0 !important;
        }

        /* Nội dung chạy sát mép hộp, không chừa dải trống hai bên. */
        #modalCreate .modal-body { padding: 5px 0 !important; }
        #modalCreate .nav-normal-info-container { padding-left: 0; padding-right: 0; }

        /* Dãy công tắc ở cột trái hộp thoại: gom vào một khung như .type-product
           của v2 (viền #ccc, bo 6px), nhưng có padding trong để công tắc không
           dính mép — v2 không chừa nên nút gạt chạm sát viền. */
        #modalCreate .cong-tac {
            width: 100%;
            border: 1px solid #ccc;
            border-radius: 6px;
            padding: 10px 6px;
        }
        #modalCreate .cong-tac .form-label { margin-bottom: 0; }
        /* Hàng đầu và hàng cuối không cần lề trên/dưới nữa vì khung đã có padding. */
        #modalCreate .cong-tac > div:first-child { margin-top: 0 !important; }
        #modalCreate .cong-tac > div:last-child { margin-bottom: 0 !important; }

        /* Khối Quy đổi đơn vị: một khung duy nhất, hàng tiêu đề có nền nhạt để
           tách khỏi bảng bên dưới — thay cho hai lớp viền chồng nhau của bản v2
           (.accordion-item đã có viền, .accordion-body lại thêm class .border). */
        #modalCreate .khoi-quy-doi {
            border: 1px solid #dee2e6;
            border-radius: 6px;
            overflow: hidden;
        }
        #modalCreate .qd-dau {
            background: #f8f9fa;
            padding: 6px 10px;
            border-bottom: 1px solid #dee2e6;
        }
        #modalCreate .qd-dau .btn-collapse {
            background: transparent;
            border: 0;
            box-shadow: none;
            padding: 0;
        }
        #modalCreate .qd-than { padding: 10px; }
        #modalCreate .qd-than table { border: 0; }

        .btn_top_content > * { margin-left: 8px; }

        /* Ô chọn nhiều nhóm hàng: select2 của v2, khung lọc hẹp nên kéo hết bề ngang. */
        .fillter-box .select2-container { width: 100% !important; }

        /* Bảng: 11 cột không nhét vừa màn thường nên CHO CUỘN NGANG chứ không
           bóp cột lại. Bốn cột chữ dài cắt bằng "…", di chuột vào xem đủ. */
        #sortableTable { min-width: 1200px; }
        #sortableTable .item-name, #sortableTable .item-group {
            max-width: 240px; overflow: hidden; text-overflow: ellipsis;
        }
        /* Hàng nút trên bảng: mọi nút cùng một khối — cao 32px, bo 6px, chữ
           14px — theo đúng luật `.bt` của v2. Nút "Nâng cao" là dropdown nên
           Bootstrap tự nhét padding riêng, phải kéo về cho bằng ba nút kia. */
        .btn_top_content .bt,
        .btn_top_content .dropdown_advanced .bt {
            height: 32px !important;
            padding: 0 15px !important;
            font-size: 14px;
            font-weight: 600;
            border-radius: 6px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        /* Nút "Tạo mới" của màn Hàng hoá bên v2 KHÔNG dùng xanh lá chung mà là
           một sắc lam-lục riêng. */
        .btn_top_content .btn_create {
            background: #07B29B !important;
            color: #fff !important;
        }
        .btn_top_content .btn_create:hover { background: #31c8b5 !important; }

        /* Menu "Nâng cao" nổi lên TRÊN bảng, nên phải có bóng để tách lớp —
           không thì nền xanh đặc dán phẳng vào bảng, trông như một mảng màu vỡ
           ra chứ không phải một tấm menu. */
        #prdUtil .dropdown-menu {
            box-shadow: 0 6px 16px rgba(0, 0, 0, .18);
            border: 0;
        }
        /* Ba mục là thẻ <a> KHÔNG có href (chúng chạy bằng JS), mà thẻ <a> trống
           href thì trỏ chuột vẫn là mũi tên — người dùng không biết bấm được. */
        #prdUtil .dropdown-item { cursor: pointer; }

        .loi-nhap-tep { margin: 0; padding-left: 18px; max-height: 320px; overflow-y: auto; }
        .loi-nhap-tep li { margin-bottom: 6px; color: #cf1322; }

        /* Kéo thả đổi thứ tự. Con trỏ bốn chiều là thứ duy nhất báo cho người
           dùng biết dòng này nhấc lên được — không có nó thì tính năng vô hình. */
        #sortableTable tbody tr.keo-duoc { cursor: grab; }
        #sortableTable tbody tr.keo-duoc:active { cursor: grabbing; }
        /* Dòng đang bị nhấc: mờ đi để mắt bám theo CHỖ TRỐNG nó để lại, đó mới là
           thông tin cần nhìn khi đang chọn điểm thả. */
        #sortableTable tbody tr.dang-keo { opacity: .4; }
        /* Ô nhập và ô tick không được nuốt cú kéo: giữ nguyên hành vi bấm của chúng. */
        #sortableTable tbody tr.keo-duoc input,
        #sortableTable tbody tr.keo-duoc .action i { cursor: pointer; }

        .sort-item i { cursor: pointer; padding: 0 2px; }
        .sort-item.khoa i { cursor: not-allowed; opacity: .3; }
        td.action i { cursor: pointer; margin-left: 5px; }
    </style>
@endpush

@php
        // Sắp xếp nhóm hàng theo cây (thụt lề) để dropdown dễ đọc.
        // API trả về parent_id = null cho nhóm gốc → dùng 'root' thay vì 0 để groupBy đúng.
        //
        // Mỗi dòng mang theo ba thứ mà hộp thoại cần, đúng quy tắc bản cũ v2:
        //   leaf     — nhóm KHÔNG có nhóm con. Chỉ nhóm lá mới gắn hàng được; nhóm
        //              cha là khung phân loại, gắn hàng vào đó thì báo cáo theo
        //              nhóm đếm hai lần và người bán không biết món nằm nhánh nào.
        //   sellable — nằm dưới nhóm gốc "Hàng bán". v2 lọc đúng như vậy
        //              (id_menu_group_parent = 1), phần còn lại là hàng không bán.
        //   vat      — mức thuế của nhóm, để chọn nhóm là ô thuế tự điền.
        $catByParent = collect($categories)->groupBy(fn ($c) => $c['parent_id'] ?? 'root');
        $orderedCats = [];
        $walkCats = function ($parentId, $level, $sellable, $duong = []) use (&$walkCats, $catByParent, &$orderedCats) {
            foreach ($catByParent->get($parentId, []) as $c) {
                $con = $catByParent->get($c['id'], []);
                $duocBan = $sellable || ($c['slug'] ?? '') === 'hang-ban';
                $orderedCats[] = [
                    'id' => $c['id'],
                    'name' => $c['name'],
                    'level' => $level,
                    'leaf' => count($con) === 0,
                    'sellable' => $duocBan,
                    'vat' => (int) ($c['vat'] ?? 0),
                    // Đường từ nhóm gốc xuống, KHÔNG kể chính nó. Ô chọn trong hộp
                    // thoại chỉ bày nhóm lá nên mất hẳn phần thụt lề — mà tên nhóm
                    // lá thì trùng nhau được (hai nhóm "Điện thoại" nằm hai nhánh
                    // khác nhau), không có đường dẫn thì không phân biệt nổi.
                    'path' => $duong,
                ];
                $walkCats($c['id'], $level + 1, $duocBan, array_merge($duong, [$c['name']]));
            }
        };
        $walkCats('root', 0, false, []);

        // Ô chọn nhóm trong hộp thoại chỉ bày nhóm NHỎ NHẤT — nhóm không có nhóm
        // con. Có nhóm con thì chính các nhóm con ấy hiện lên thay, còn nhóm cha
        // lui về làm khung phân loại: gắn hàng vào nhóm cha thì báo cáo theo nhóm
        // đếm hai lần và người bán không biết món nằm nhánh nào.
        //
        // Không xét cấp: một nhóm gốc chưa có nhánh con nào thì chính nó là nhóm
        // nhỏ nhất của nhánh đó, gắn hàng vào được.
        $catsChonDuoc = array_values(array_filter($orderedCats, fn ($c) => $c['leaf']));

        // Ô lọc ngoài bảng theo CÙNG luật ấy, cộng thêm một ràng buộc nữa: chỉ
        // nhóm ĐANG CÓ HÀNG. Nhóm rỗng bày ra chỉ tổ chọn vào rồi nhận bảng
        // trắng — đúng thứ ô lọc này sinh ra để tránh.
        //
        // Nhóm cha lọt vào đây được khi hàng cũ còn gắn thẳng vào nó (khai từ
        // trước lúc có luật nhóm nhỏ nhất). Lọc nó ra thì đúng luật, đổi lại
        // chỗ hàng ấy phải tìm bằng ô tìm kiếm thay vì bằng ô lọc nhóm.
        $idNhomNhoNhat = array_column($catsChonDuoc, 'id');
        $nhomLocDuoc = array_values(array_filter(
            $nhomCoHang,
            fn ($c) => in_array((int) $c['id'], array_map('intval', $idNhomNhoNhat), true)
        ));

        $firstRank = ($meta['page'] - 1) * $meta['page_size'];

        // Đổi thứ tự chỉ có nghĩa khi danh sách đang ở ĐÚNG thứ tự tự xếp: không
        // lọc gì, không sắp theo cột. Bản cũ v2 cũng khoá hai mũi tên đúng như vậy.
        //
        // Vì sao phải khoá: hai mũi tên ĐỔI CHỖ với hàng xóm trong danh sách ĐẦY
        // ĐỦ (xem productRepository.DoiChoThuTu), không phải với dòng ngay trên
        // màn hình. Đang lọc mà bấm thì nó đổi chỗ với một mặt hàng đang bị giấu
        // — dòng đứng im, người dùng bấm tiếp, và thứ tự thật thì loạn dần.
        //
        // Ô CHI NHÁNH là ngoại lệ khi cửa hàng chỉ có MỘT chi nhánh: lúc ấy bộ
        // lọc không loại ra mặt hàng nào (hàng chưa gán = mọi chi nhánh, hàng đã
        // gán thì gán vào đúng chi nhánh đó), nên danh sách vẫn đầy đủ và hai mũi
        // tên vẫn đúng. Không có ngoại lệ này thì gần như mọi cửa hàng hôm nay
        // mất luôn tính năng sắp xếp, chỉ vì phiên nào cũng ghim sẵn một chi nhánh.
        $locChiNhanhAnhHuong = $filters['shop_id'] > 0 && count($branches) > 1;

        $doiThuTuDuoc = $filters['keyword'] === ''
            && empty($filters['category_ids'])
            && $filters['location_id'] === ''
            && ! $filters['unit_id']
            && $filters['multi_variant'] === ''
            && empty($filters['statuses'])
            && ! $locChiNhanhAnhHuong
            && $filters['sort'] === 'newest';

        $locNhom = $filters['category_ids'];
        // filters() đã quy mọi mức cũ về hai mức đang bày, nên đọc thẳng.
        $locTrangThai = $filters['statuses'];

        $coLoc = ! $doiThuTuDuoc;

        // v2 giữ trạng thái cột trong $columns; ở đây đọc từ ?hide= để lựa chọn
        // sống qua lượt tải lại và qua cả đổi trang.
        $cotTat = array_filter(explode(',', (string) request()->query('hide', '')));
        $cot = [];
        foreach (['code', 'name', 'group', 'vat', 'unit', 'sale_price', 'branch', 'status'] as $c) {
            $cot[$c] = in_array($c, $cotTat, true) ? 0 : 1;
        }
        $tenCot = [
            'code' => __('message.menu-code'), 'name' => __('message.menu-name'),
            'group' => __('message.product_group'), 'vat' => __('message.vat'),
            'unit' => __('message.dvt'), 'sale_price' => __('message.sale-price'),
            'branch' => __('message.branch'), 'status' => __('message.status'),
        ];
    @endphp

@section('content')
    {{-- Nút mở từng khối lọc, chỉ hiện trên điện thoại. --}}
    <div class="call-to-action-container">
        <div class="wrapper-call-to-action">
            <div class="btn-open-modal" type="button" data-bs-toggle="offcanvas" data-bs-target="#offcanvasBottomInMobile"
                aria-controls="offcanvasBottomInMobile" data-offcanvas-target="#filterBaseOnNameProduct">
                <p class="open-modal-label">{{ __('message.search') }}</p>
                <div class="icon-for-cta"><i class="fa-solid fa-magnifying-glass"></i></div>
            </div>
            <div class="btn-open-modal" type="button" data-bs-toggle="offcanvas" data-bs-target="#offcanvasBottomInMobile"
                aria-controls="offcanvasBottomInMobile" data-offcanvas-target="#filterBaseOnCategoryProduct">
                <p class="open-modal-label">{{ __('message.product_group') }}</p>
                <div class="icon-for-cta"><i class="fa-solid fa-layer-group"></i></div>
            </div>
            <div class="btn-open-modal" type="button" data-bs-toggle="offcanvas" data-bs-target="#offcanvasBottomInMobile"
                aria-controls="offcanvasBottomInMobile" data-offcanvas-target="#filterBaseOnBranch">
                <p class="open-modal-label">{{ __('message.branch') }}</p>
                <div class="icon-for-cta"><i class="fa-solid fa-store"></i></div>
            </div>
            <div class="btn-open-modal" type="button" data-bs-toggle="offcanvas" data-bs-target="#offcanvasBottomInMobile"
                aria-controls="offcanvasBottomInMobile" data-offcanvas-target="#filterBaseOnStatus">
                <p class="open-modal-label">{{ __('message.status') }}</p>
                <div class="icon-for-cta"><i class="fa-solid fa-toggle-on"></i></div>
            </div>
        </div>
    </div>

    <div class="row index-menu-page">
        {{-- Cột lọc bên trái — lưới nửa bậc 2_5 / 9_5 của riêng v2. --}}
        <div class="col-12 col-lg-2_5 col-xl-2 d-none d-lg-block pe-lg-0 fillter-box-container">
            <div class="fillter-box">
                <div class="card">
                    <div class="card-header card-header-primary header_search">{{ __('message.filter') }}</div>
                    <div class="card-body px-2">
                        {{-- Không có nút "Lọc": gõ hay tick ô nào là lọc lại ngay.
                             Giữ nguyên id #prdFilter và tên các ô — JS của màn cũ
                             đọc đúng những cái tên này. --}}
                        <form method="GET" action="{{ route('admin.products.index') }}" id="prdFilter">

                            {{-- Từng khối lọc mang đúng id của v2: JS của khung v2 bưng
                                 khối .inner-modal-in-mobile sang tấm offcanvas trên điện
                                 thoại theo mấy cái id này. --}}
                            <div id="filterBaseOnBranch">
                                <div class="inner-modal-in-mobile">
                                    <label class="form-label title_search d-none d-md-block">{{ __('message.branch') }}</label>
                                    {{-- Mặc định là CHI NHÁNH ĐANG LÀM VIỆC chứ không phải
                                         "Tất cả" — cột Tồn kho và tập mặt hàng bày ra đều
                                         đổi nghĩa theo nó. Chọn "Tất cả" là một lựa chọn
                                         cố ý, và nó cũng là cách mở lại hai mũi tên đổi
                                         thứ tự khi cửa hàng có nhiều chi nhánh. --}}
                                    <select name="shop_id" class="form-select">
                                        <option value="0" {{ (int) $filters['shop_id'] === 0 ? 'selected' : '' }}>
                                            {{ __('message.all') }}
                                        </option>
                                        @foreach($branches as $b)
                                            <option value="{{ $b['id'] }}" {{ (int) $filters['shop_id'] === (int) $b['id'] ? 'selected' : '' }}>
                                                {{ $b['name'] ?? '' }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>

                            <div id="filterBaseOnNameProduct">
                                <div class="inner-modal-in-mobile">
                                    <label class="form-label title_search d-none d-md-block">{{ __('message.by_goods') }}</label>
                                    <input type="text" name="keyword" value="{{ $filters['keyword'] }}"
                                        class="form-control ip_search ip_search_menu" autocomplete="off"
                                        placeholder="{{ __('message.enter-name-or-code') }}">
                                </div>
                            </div>

                            <div id="filterBaseOnCategoryProduct">
                                <div class="inner-modal-in-mobile">
                                    <label class="form-label title_search d-none d-md-block">{{ __('message.by-menu-group') }}</label>
                                    <div class="custom-multiselect">
                                        {{-- Cùng luật với ô trong hộp thoại (chỉ nhóm nhỏ nhất, chỉ in
                                             tên), cộng ràng buộc riêng của ô lọc: nhóm phải ĐANG CÓ
                                             HÀNG. Số trong ngoặc là số mặt hàng của nhóm. --}}
                                        <select class="form-select select_menu_group_search select2" multiple
                                            name="category_ids[]">
                                            @foreach($nhomLocDuoc as $c)
                                                <option value="{{ $c['id'] }}" {{ in_array((int) $c['id'], $locNhom, true) ? 'selected' : '' }}>
                                                    {{ $c['name'] }} ({{ $c['so_mat_hang'] ?? 0 }})
                                                </option>
                                            @endforeach
                                        </select>
                                        <span class="chevron-down"></span>
                                    </div>
                                </div>
                            </div>

                            <div id="filterBaseOnStatus">
                                <div class="input-group inner-modal-in-mobile d-flex flex-column">
                                    <label class="form-label title_search d-none d-md-block">{{ __('message.status') }}</label>
                                    <div class="form-check">
                                        <input class="me-2 form-check-input check-to-search" type="checkbox"
                                            name="statuses[]" value="active" id="menu_status_active"
                                            {{ in_array('active', $locTrangThai, true) ? 'checked' : '' }}>
                                        <label class="form-check-label label-custom" for="menu_status_active">{{ __('message.active') }}</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="me-2 form-check-input check-to-search" type="checkbox"
                                            name="statuses[]" value="hidden" id="menu_status_inactive"
                                            {{ in_array('hidden', $locTrangThai, true) ? 'checked' : '' }}>
                                        <label class="form-check-label label-custom" for="menu_status_inactive">{{ __('message.inactive') }}</label>
                                    </div>
                                </div>
                            </div>

                            {{-- Kiểu sắp xếp đi theo tiêu đề cột, nhưng vẫn phải mang sang
                                 lượt lọc sau — không thì đổi bộ lọc là mất thứ tự vừa chọn. --}}
                            <input type="hidden" name="sort" value="{{ $filters['sort'] }}">
                            <input type="hidden" name="per_page" value="{{ $filters['per_page'] }}">
                            {{-- Cột đang ẩn đi theo bộ lọc, không thì lọc một cái là cột hiện lại hết. --}}
                            <input type="hidden" name="hide" value="{{ request()->query('hide', '') }}">
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-9_5 col-xl-10 mt-md-2 mt-lg-0 content_midd_container">
            <div class="content_midd">
                <div class="content_midd_title">
                    {{-- h1 chứ không phải h4: đây là tiêu đề cấp 1 của trang, trình
                         đọc màn hình lấy nó làm mốc. Class .tieu-de-trang giữ nguyên
                         dáng chữ của h4 bên v2. --}}
                    <h1 class="tieu-de-trang">{{ \App\Http\Controllers\ProductController::TITLE_PAGE }}</h1>
                    <div class="justify-content-end">
                        <div class="btn_top_content">
                            {{-- v2 để nút này xổ xuống vì bên đó có ba kiểu hàng (thường /
                                 combo / dịch vụ tính giờ). Bán lẻ mới có kiểu thường, một
                                 dropdown chứa đúng một mục chỉ tổ bắt bấm thêm một nhịp —
                                 nên để nút thẳng, giữ nguyên sắc lam-lục của v2. --}}
                            <a type="button" class="bt btn_create" id="prdAddBtn">{{ __('message.create_new') }}</a>
                            <a type="button" class="bt btn_red mass-delete">{{ __('message.delete') }}</a>

                            <div class="dropdown dropdown_advanced" id="prdUtil">
                                <button class="bt btn_advanced dropdown-toggle" type="button" id="prdUtilBtn"
                                    data-bs-toggle="dropdown" aria-expanded="false">{{ __('message.advanced') }}</button>
                                {{-- Căn PHẢI: hàng nút dồn sát mép phải khung nội dung, để menu
                                     đổ sang phải như mặc định thì nó chạm rìa và nằm lệch hẳn
                                     khỏi cái nút vừa bấm, trườn xuống dưới nút chọn cột. --}}
                                <ul class="dropdown-menu dropdown-menu-end">
                                    <li>
                                        <a class="dropdown-item btn_import_file" type="button">{{ __('message.import_file') }}</a>
                                        <a class="dropdown-item" href="{{ route('admin.products.importTemplate') }}">{{ __('message.download_sample_file') }}</a>
                                        <a class="dropdown-item" href="{{ route('admin.products.export', request()->query()) }}">{{ __('message.export-excel') }}</a>
                                    </li>
                                </ul>
                            </div>

                            {{-- Chọn cột: bỏ tick là thêm cột vào ?hide=, tải lại giữ nguyên. --}}
                            <div class="dropup">
                                <button type="button" class="btn active dropbtn setting-col" href="#">
                                    <i class="fa fa-sliders" aria-hidden="true"></i>
                                    <div class="dropup-content">
                                        <div class="list_filter">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" id="show_all"
                                                    {{ count($cotTat) ? '' : 'checked' }}>
                                                <label for="show_all">{{ __('message.all') }}</label>
                                            </div>
                                            @foreach ($cot as $ma => $bat)
                                                <div class="form-check">
                                                    <input class="form-check-input show_col" data-col="{{ $ma }}"
                                                        type="checkbox" id="show_{{ $ma }}" {{ $bat ? 'checked' : '' }}>
                                                    <label for="show_{{ $ma }}">{{ $tenCot[$ma] }}</label>
                                                </div>
                                            @endforeach
                                        </div>
                                    </div>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Bảng — khuôn của v2 (menu/menu/list): .table-list-container +
                     lớp show_* trên từng ô để nút chọn cột bật/tắt cả cột. --}}
                <div class="table-list-container table-border-style table-responsive">
                    <table id="sortableTable" data-stt-dau="{{ $firstRank + 1 }}">
                        <thead>
                            <tr class="header-table-list">
                                <th class="text-center not-export">
                                    <input class="form-check-input item-select-all" type="checkbox">
                                </th>
                                <th class="text-center">{{ __('message.stt') }}</th>
                                <th class="text-left show_code {{ $cot['code'] ? '' : 'hide' }}">{{ __('message.menu-code') }}</th>
                                <th class="text-left show_name {{ $cot['name'] ? '' : 'hide' }}">
                                    @include('v2::partials.sortable-label', ['key' => 'name', 'label' => __('message.menu-name')])
                                </th>
                                <th class="text-left show_group {{ $cot['group'] ? '' : 'hide' }}">
                                    @include('v2::partials.sortable-label', ['key' => 'group', 'label' => __('message.product_group')])
                                </th>
                                <th class="text-right show_vat {{ $cot['vat'] ? '' : 'hide' }}">{{ __('message.vat') }}</th>
                                <th class="text-left show_unit {{ $cot['unit'] ? '' : 'hide' }}">{{ __('message.dvt') }}</th>
                                <th class="text-right show_sale_price {{ $cot['sale_price'] ? '' : 'hide' }}">
                                    @include('v2::partials.sortable-label', ['key' => 'price', 'label' => __('message.sale-price')])
                                </th>
                                <th class="text-left show_branch {{ $cot['branch'] ? '' : 'hide' }}">{{ __('message.branch') }}</th>
                                <th class="text-center show_status {{ $cot['status'] ? '' : 'hide' }}">{{ __('message.status') }}</th>
                                <th class="text-center not-export">{{ __('message.action') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($products as $i => $p)
                                @php
                                    $base = (float) ($p['base_price'] ?? 0);

                                    // Giá khách THỰC TRẢ, theo đúng thứ tự tầng thanh toán dùng:
                                    // giá sau khuyến mãi > giá giảm gõ tay > giá niêm yết.
                                    $now = $base;
                                    foreach (['final_price', 'sale_price'] as $key) {
                                        $v = $p[$key] ?? null;
                                        if ($v !== null && (float) $v > 0 && (float) $v < $now) {
                                            $now = (float) $v;
                                        }
                                    }
                                    $sale = $now < $base;

                                    // mucTrangThai() quy cả mức `discontinued` cũ về "Không hoạt động".
                                    $tt = \App\Http\Controllers\ProductController::mucTrangThai(
                                        $p['status'] ?? (! empty($p['is_active']) ? 'active' : 'hidden')
                                    );
                                    $chiNhanh = \App\Http\Controllers\ProductController::chiNhanhText($p);
                                @endphp
                                <tr class="item {{ $doiThuTuDuoc ? 'keo-duoc' : '' }}" data-id="{{ $p['id'] }}"
                                    draggable="{{ $doiThuTuDuoc ? 'true' : 'false' }}">
                                    <td class="text-center not-export">
                                        <input class="form-check-input item-select" type="checkbox" value="{{ $p['id'] }}">
                                    </td>
                                    <td class="text-center stt">{{ $firstRank + $i + 1 }}</td>
                                    <td class="text-left show_code {{ $cot['code'] ? '' : 'hide' }} item-code">
                                        <a type="button" class="show-item text-decoration-none" data-id="{{ $p['id'] }}">{{ $p['sku'] ?? '' }}</a>
                                    </td>
                                    <td class="text-left show_name {{ $cot['name'] ? '' : 'hide' }} item-name"
                                        title="{{ $p['name'] ?? '' }}">{{ $p['name'] ?? '' }}</td>
                                    <td class="text-left show_group {{ $cot['group'] ? '' : 'hide' }} item-group"
                                        title="{{ $p['category']['name'] ?? '' }}">{{ $p['category']['name'] ?? '' }}</td>
                                    <td class="text-right show_vat {{ $cot['vat'] ? '' : 'hide' }}">
                                        {{ \App\Http\Controllers\ProductController::vatText($p['vat'] ?? 0) }}
                                    </td>
                                    <td class="text-left show_unit {{ $cot['unit'] ? '' : 'hide' }}">{{ $p['unit']['name'] ?? '' }}</td>
                                    <td class="text-right show_sale_price {{ $cot['sale_price'] ? '' : 'hide' }}">
                                        {{ number_format($now, 0, ',', '.') }}
                                        @if($sale)
                                            <br><span style="text-decoration: line-through; color:#8c8c8c; font-size:12px">{{ number_format($base, 0, ',', '.') }}</span>
                                        @endif
                                    </td>
                                    {{-- Chưa gán chi nhánh nào = bán ở MỌI chi nhánh. Nói thẳng ra
                                         chứ không để trống: ô trống ở đây đọc như dữ liệu thiếu. --}}
                                    <td class="text-left show_branch {{ $cot['branch'] ? '' : 'hide' }}"
                                        title="{{ $chiNhanh }}">{{ $chiNhanh !== '' ? $chiNhanh : 'Mọi chi nhánh' }}</td>
                                    <td class="text-center show_status {{ $cot['status'] ? '' : 'hide' }}">
                                        <input type="checkbox" class="switch_customer item-status" data-id="{{ $p['id'] }}"
                                            {{ $tt === 'active' ? 'checked' : '' }}
                                            title="{{ $statuses[$tt] }} — {{ $statusHints[$tt] ?? '' }}">
                                    </td>
                                    <td class="text-center action not-export">
                                        {{-- Hai mũi tên đổi thứ tự, đúng như v2. Chỉ bấm được khi danh
                                             sách đang ở thứ tự tự xếp: đang lọc hay đang sắp theo cột
                                             thì "lên một bậc" không còn nghĩa gì. --}}
                                        <span class="sort-item d-inline-block {{ $doiThuTuDuoc ? '' : 'khoa' }}">
                                            <i type="button" class="fa fa-arrow-up" data-move="up" data-id="{{ $p['id'] }}"
                                                title="{{ $doiThuTuDuoc ? 'Đưa lên trên' : 'Xoá lọc và bỏ sắp xếp cột thì mới đổi được thứ tự' }}"></i>
                                            <i type="button" class="fa fa-arrow-down" data-move="down" data-id="{{ $p['id'] }}"
                                                title="{{ $doiThuTuDuoc ? 'Đưa xuống dưới' : 'Xoá lọc và bỏ sắp xếp cột thì mới đổi được thứ tự' }}"></i>
                                        </span>
                                        <i type="button" class="fa fa-edit btn-edit" data-id="{{ $p['id'] }}" title="{{ __('message.edit') }}"></i>
                                        <i type="button" class="fa fa-copy btn-copy" data-id="{{ $p['id'] }}" title="{{ __('message.copy') }}"></i>
                                        <i type="button" class="fa fa-times btn-delete text-danger" data-id="{{ $p['id'] }}" title="{{ __('message.delete') }}"></i>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="11" class="text-center py-4">
                                        @if($coLoc)
                                            Không tìm thấy mặt hàng nào khớp bộ lọc. Thử xoá bớt điều kiện lọc.
                                        @else
                                            Chưa có mặt hàng nào. Bấm "Tạo mới" để khai mặt hàng đầu tiên.
                                        @endif
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                {{-- Form ẩn để nhân bản: v2 dùng nút biểu tượng, ở đây vẫn phải đi
                     qua POST có CSRF. --}}
                <form id="prdCopyForm" method="POST" style="display:none">@csrf</form>


                {{-- Phân trang dựng đúng khuôn bootstrap-4 mà bản v2 in ra. --}}
                <div class="form_pagi">
                    @include('v2::partials.pagination', ['meta' => $meta])
                </div>

                <select class="form-control item-per-page select-width" data-param="per_page">
                    @foreach ($perPageOptions as $muc)
                        <option value="{{ $muc }}" {{ $filters['per_page'] == $muc ? 'selected' : '' }}>
                            {{ __('message.display', ['name' => $muc]) }}
                        </option>
                    @endforeach
                </select>
            </div>
        </div>
    </div>

    @include('v2::hang-hoa._modal')

    {{-- ===================== Hộp Nhập file ===================== --}}
    <div class="modal" id="modalImport">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h4 class="modal-title">{{ __('message.import_file') }}</h4>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="{{ route('admin.products.import') }}" enctype="multipart/form-data">
                    @csrf
                    <div class="modal-body">
                        <div class="modal_center">
                            <p style="color:#6c757d">
                                Chọn tệp <b>CSV</b> theo mẫu. Mỗi dòng một mặt hàng; cột <b>bien_the</b> nhập
                                nhiều tên cách nhau dấu phẩy — mỗi tên tạo một biến thể, bỏ trống là hàng đơn.
                                Tổ hợp thuộc tính khai trong hộp sửa, không khai qua tệp.
                            </p>
                            <label class="form-label">Tệp CSV <span style="color:red">*</span></label>
                            <input type="file" name="file" accept=".csv,text/csv" required class="form-control">
                        </div>
                    </div>
                    <div class="modal-footer justify-content-center">
                        <button type="button" class="bt btn_red" data-bs-dismiss="modal">{{ __('message.close') }}</button>
                        <button type="submit" class="bt btn_green">{{ __('message.import_file') }}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    {{-- ============ Hộp "Dòng chưa nhập được" ============ --}}
    {{-- Toast chỉ nói ĐƯỢC BAO NHIÊU dòng; còn dòng nào hỏng và hỏng vì gì thì
         phải đọc và đối chiếu với tệp, nên nó thuộc về một hộp thoại chứ không
         phải một câu thoáng qua. Hộp tự bật ngay sau lượt nhập có dòng hỏng. --}}
    @if(session('importErrors'))
        <div class="modal" id="modalImportErrors">
            <div class="modal-dialog modal-dialog-centered modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h6 class="modal-title">Những dòng chưa nhập được</h6>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <ul class="loi-nhap-tep">
                            @foreach(session('importErrors') as $dong)
                                <li>{{ $dong }}</li>
                            @endforeach
                        </ul>
                        @if((int) session('importErrorsMore', 0) > 0)
                            <p class="text-muted mb-0">… và {{ session('importErrorsMore') }} dòng nữa.</p>
                        @endif
                    </div>
                    <div class="modal-footer justify-content-center">
                        <button type="button" class="bt btn_red" data-bs-dismiss="modal">{{ __('message.close') }}</button>
                    </div>
                </div>
            </div>
        </div>
    @endif

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
                    <button type="button" class="bt btn_gray" data-bs-dismiss="modal">{{ __('message.close') }}</button>
                    <button type="button" class="bt btn_red delete-value">{{ __('message.delete') }}</button>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        const CSRF_HH = '{{ csrf_token() }}';
        const URL_HH = @json(url('/admin/products'));
        const URL_HH_STORE = @json(route('admin.products.store'));
        const URL_HH_BULK = @json(route('admin.products.bulkDestroy'));
        const URL_HH_VE = @json(route('admin.products.index', request()->query()));
        const URL_HH_SAPXEP = @json(route('admin.products.sapXep'));

        /** Mọi thao tác ghi đi bằng form POST ẩn: trang tải lại và toast do session bắn ra. */
        window.PRD = {
            post: function (action, method, fields) {
                const $f = $('<form>', { method: 'POST', action, css: { display: 'none' } });
                const them = (n, v) => $f.append($('<input>', {
                    type: 'hidden', name: n, value: typeof v === 'boolean' ? (v ? 1 : 0) : (v == null ? '' : v),
                }));
                them('_token', CSRF_HH);
                if (method && method !== 'POST') them('_method', method);
                them('return', URL_HH_VE);
                $.each(fields || {}, (k, v) => {
                    Array.isArray(v) ? v.forEach((x) => them(k, x)) : them(k, v);
                });
                $('body').append($f);
                $f.trigger('submit');
            },
            toast: (msg) => toastr.error(msg),
            toastOk: (msg) => toastr.success(msg),
        };

        // ---------- Chọn cột ----------
        // Cột đang tắt ghi vào ?hide= rồi tải lại, để giữ sau khi đổi trang.
        function apDungCot() {
            const tat = $('.show_col').filter((i, el) => !el.checked).map((i, el) => $(el).data('col')).get();
            const q = new URLSearchParams(location.search);
            tat.length ? q.set('hide', tat.join(',')) : q.delete('hide');
            V2.napLai(location.pathname + '?' + q);
        }
        $(document).on('change', '.show_col', apDungCot);
        $(document).on('change', '#show_all', function () {
            $('.show_col').prop('checked', this.checked);
            apDungCot();
        });

        // ---------- Bộ lọc: đổi ô nào là lọc lại ngay, gõ thì chờ 400ms ----------
        const $loc = $('#prdFilter');
        $loc.on('change', 'select, input[type="checkbox"]', function () {
            $loc.trigger('submit');
        });
        let timerHH = null;
        $loc.on('input', 'input[name="keyword"]', function () {
            clearTimeout(timerHH);
            timerHH = setTimeout(() => $loc.trigger('submit'), 400);
        });

        // Ô chọn nhiều nhóm hàng: select2 như v2, chọn xong là lọc ngay.
        // Neo vào thẻ `select`: `.select2` cũng khớp cái span mà chính select2
        // dựng ra, mà gọi select2() lên span là hỏng phần hiển thị.
        $('#prdFilter select.select2').not('.select2-hidden-accessible')
            .select2({ placeholder: '{{ __('message.all') }}', width: '100%' })
            .on('change', () => $loc.trigger('submit'));

        // ---------- Bảng ----------
        $(document).on('change', '.item-select-all', function () {
            $('.item-select').prop('checked', this.checked);
        });

        // Công tắc: gửi DUY NHẤT cờ bật/tắt tới đường chuyên biệt. Không gửi lại cả
        // mặt hàng — API ghi cả dòng khi PUT, thiếu một trường là bấm công tắc một
        // cái mất luôn dữ liệu đó.
        $(document).on('change', '.item-status', function () {
            V2.ghi(URL_HH + '/' + $(this).data('id') + '/toggle-status', 'PUT',
                { is_active: this.checked ? 1 : 0 });
        });

        $(document).on('click', '.sort-item i', function () {
            if ($(this).closest('.sort-item').hasClass('khoa')) return;
            V2.ghi(URL_HH + '/' + $(this).data('id') + '/sort', 'PUT', { huong: $(this).data('move') });
        });

        // ---------- Kéo thả đổi thứ tự ----------
        //
        // Cùng lối với bảng thuộc tính trong hộp sửa: kéo thả sẵn có của trình
        // duyệt, không kéo thêm jQuery UI về chỉ để làm mỗi việc này.
        //
        // Chỉ bật khi danh sách đang ở thứ tự tự xếp (không lọc, không sắp theo
        // cột) — cùng điều kiện với hai mũi tên. Đang sắp theo Giá bán mà thả một
        // dòng vào giữa thì cái thứ tự vừa dựng ra không sống nổi qua lượt tải lại.
        let $keo = null;

        $(document).on('dragstart', '#sortableTable tbody tr.keo-duoc', function (e) {
            $keo = $(this).addClass('dang-keo');
            e.originalEvent.dataTransfer.effectAllowed = 'move';
            // Firefox không khởi động lượt kéo nếu không đặt dữ liệu.
            e.originalEvent.dataTransfer.setData('text/plain', '');
        });

        $(document).on('dragover', '#sortableTable tbody tr.keo-duoc', function (e) {
            if (!$keo || $keo[0] === this) return;
            e.preventDefault();
            const o = this.getBoundingClientRect();
            $keo[e.originalEvent.clientY < o.top + o.height / 2 ? 'insertBefore' : 'insertAfter'](this);
            danhSoLai();
        });

        $(document).on('dragend drop', '#sortableTable tbody tr.keo-duoc', function (e) {
            e.preventDefault();
            if (!$keo) return;
            $keo.removeClass('dang-keo');
            $keo = null;
            danhSoLai();
            luuThuTu();
        });

        /** Đánh lại cột STT ngay tại chỗ — số phải khớp cái mắt đang thấy. */
        function danhSoLai() {
            const dau = Number($('#sortableTable').data('stt-dau') || 1);
            $('#sortableTable tbody tr.item').each(function (i) {
                $(this).find('td.stt').text(dau + i);
            });
        }

        /**
         * Gửi NGUYÊN trình tự của trang.
         *
         * Không tải lại trang sau khi lưu: người dùng vừa tự tay đặt các dòng vào
         * đúng chỗ, bảng nhảy một cái là mất luôn mạch việc — và nếu họ định kéo
         * tiếp dòng thứ hai thì phải chờ cả trang dựng lại. Hỏng thì mới tải lại,
         * để bảng trở về đúng thứ tự máy chủ đang giữ chứ không đứng đó nói dối.
         */
        function luuThuTu() {
            const ids = $('#sortableTable tbody tr.item').map((i, el) => $(el).data('id')).get();
            if (ids.length < 2) return;

            $.ajax({
                url: URL_HH_SAPXEP,
                method: 'POST',
                dataType: 'json',
                // Đòi JSON đích danh: thiếu header này máy chủ trả chuyển hướng,
                // mà chuyển hướng thì jQuery đọc thành công kể cả khi lưu hỏng.
                headers: { Accept: 'application/json' },
                data: { _token: CSRF_HH, _method: 'PUT', ids },
            }).done((r) => {
                window.PRD.toastOk((r && r.message) || 'Đã lưu thứ tự mới.');
            }).fail((x) => {
                window.PRD.toast((x.responseJSON && x.responseJSON.message) || 'Không lưu được thứ tự mới.');
                // Nạp lại để bảng về đúng thứ tự máy chủ đang giữ, thay vì đứng
                // đó bày ra một trình tự chưa hề được ghi.
                V2.napLai(location.href);
            });
        }

        // Sửa: nạp lại mặt hàng từ máy chủ chứ không dùng bản nhúng sẵn trong trang —
        // bản đó là ảnh chụp lúc mở danh sách, người khác vừa sửa là nó đã cũ.
        // Bấm MÃ hàng hoá = XEM (hộp khoá hết ô, không có nút Xác nhận);
        // bấm nút bút chì mới là SỬA. Hai việc khác nhau nên không mở chung một
        // hộp toang, tránh lỡ tay đổi rồi lưu lúc chỉ định tra cứu.
        $(document).on('click', '.btn-edit, .show-item', function () {
            const id = Number($(this).data('id'));
            const chiXem = $(this).hasClass('show-item');
            $.getJSON(URL_HH + '/' + id)
                .done((r) => window.moHopHangHoa(r.data, chiXem))
                .fail((x) => window.PRD.toast((x.responseJSON && x.responseJSON.message) || 'Không tải được mặt hàng.'));
        });

        $(document).on('click', '#prdAddBtn', () => window.moHopHangHoa(null));
        $(document).on('click', '.btn_import_file', () => $('#modalImport').modal('show'));

        // Vừa nhập tệp xong mà có dòng hỏng thì bày luôn lý do, không bắt đi tìm.
        //
        // Đợi DOM xong mới gọi: cầu nối `$.fn.modal` do script.js dựng, mà tệp
        // ấy nạp SAU khối này (xem @stack('scripts') trong layout) — gọi ngay ở
        // đây là "modal is not a function", và lỗi đó giết luôn mọi dòng phía sau.
        $(function () {
            if ($('#modalImportErrors').length) {
                $('#modalImportErrors').modal('show');
            }
        });

        $(document).on('click', '.btn-copy', function () {
            const $f = $('#prdCopyForm');
            $f.attr('action', URL_HH + '/' + $(this).data('id') + '/duplicate').trigger('submit');
        });

        // ---------- Xoá ----------
        $(document).on('click', '.btn-delete', function () {
            $('#deleteValue').val($(this).data('id'));
            $('#deleteItem').modal('show');
        });

        $(document).on('click', '.mass-delete', function () {
            const ids = $('.item-select:checked').map((i, el) => el.value).get();
            if (!ids.length) { window.PRD.toast('{{ __('message.delete-none') }}'); return; }
            $('#deleteValue').val(ids.join(','));
            $('#deleteItem').modal('show');
        });

        $(document).on('click', '#deleteItem .delete-value', function () {
            const ids = String($('#deleteValue').val()).split(',').filter(Boolean);
            if (!ids.length) return;
            ids.length === 1
                ? window.PRD.post(URL_HH + '/' + ids[0], 'DELETE', {})
                : window.PRD.post(URL_HH_BULK, 'POST', { 'ids[]': ids });
        });
    </script>

    @include('v2::hang-hoa._modal_js')
@endpush
