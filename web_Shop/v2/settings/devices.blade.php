{{-- Cài đặt → Thông số chung → Cài đặt thiết bị, dựng theo system/parameter/setup-store
     của ordertable v2 (bản thiết kế đã duyệt: design/html/thiet-bi.html).

     Một trang, bốn khung đổi bằng ?v= không tải lại: Tổng quan, Máy in (ba tab),
     Mẫu in (tự lưu, xem trước), Máy quẹt thẻ. Dữ liệu ban đầu do
     DeviceSettingController::index đẩy vào #tbData; mỗi lượt ghi gọi đường JSON
     rồi nạp lại. Mọi luật nằm ở API — trang chỉ chặn sớm ô trống. --}}
@extends('v2::layouts.master')

@section('title', \App\Http\Controllers\DeviceSettingController::TITLE)

@php
    $C = \App\Http\Controllers\DeviceSettingController::class;
    $cuaHang = app(\App\Services\ApiClient::class)->shopInfo();
@endphp

@push('styles')
    <style>
        /* Cài đặt thiết bị — màu theo khung v2 (như Phương thức thanh toán): navy #1a2b58, cam #f29220, đầu bảng #D5E1F3. */
        /* Khai trên cả .tb-hop: hộp thoại nằm ngoài .tb, thiếu biến là mất viền và màu chọn. */
        .tb, .tb-hop { --tb-navy: #1a2b58; --tb-cam: #f29220; --tb-cam-nhat: #fff4e5; --tb-nen: #f4f6fb; --tb-vien: #e2e6ef; --tb-mo: #6b7280; --tb-xanh: #157a34; --tb-xanh-nhat: #e7f5ec; }
        label.form-label { font-weight: bold; }
        .tb-hop .modal-header { background: #D5E1F3 !important; }
        .tb-hop label.form-label { display: block; }
        .tb-hop .modal-title { color: var(--tb-navy, #1a2b58); font-weight: 700; }

        /* --- Cột trái: mục Thông số chung, kiểu nút viền như bản cũ --- */
        .tb-muc .card-body { padding-top: 10px; }
        .tb-nav { display: flex; flex-direction: column; gap: 8px; }
        .tb-nav-muc { display: block; padding: 8px 10px; border: 1px solid #dee2e6; border-radius: 6px; color: #212529; font-weight: 700; font-size: 14px; text-decoration: none; }
        .tb-nav-muc:hover { border-color: #b9c3d9; color: var(--tb-navy); }
        .tb-nav-muc.active, .tb-nav-muc.mo { color: var(--tb-cam) !important; border-color: var(--tb-cam); }
        .tb-nav-con { display: flex; flex-direction: column; gap: 2px; margin: -2px 0 0 10px; padding-left: 10px; border-left: 2px solid #eef0f4; }
        .tb-nav-con a { display: flex; align-items: center; gap: 8px; padding: 6px 8px; border-radius: 6px; color: #374151; font-size: 13.5px; text-decoration: none; white-space: nowrap; }
        .tb-nav-con a i { width: 15px; color: #9aa0a6; text-align: center; }
        .tb-nav-con a:hover { background: var(--tb-nen); color: var(--tb-navy); }
        .tb-nav-con a.active { background: var(--tb-cam-nhat); color: #8a4b00; font-weight: 600; }
        .tb-nav-con a.active i { color: var(--tb-cam); }
        .tb-nav a:focus-visible { outline: 2px solid #1586ff; outline-offset: 1px; }

        /* --- Đầu trang --- */
        .tb-dau { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; padding: 16px 20px !important; border-bottom: 1px solid var(--tb-vien); }
        .tb-dau-chu { min-width: 0; }
        .tieu-de-trang { margin: 0; font-size: 20px; font-weight: 700; color: var(--tb-navy); }
        .tb-sub { margin: 4px 0 0; color: var(--tb-mo); font-size: 13.5px; max-width: 760px; }
        .tb-dau-phai { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .tb-cn { display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; border-radius: 999px; background: var(--tb-nen); color: var(--tb-navy); font-size: 13px; font-weight: 500; white-space: nowrap; }
        .tb-dau-phai .bt { white-space: nowrap; }
        .tb-luu { font-size: 13px; color: var(--tb-mo); white-space: nowrap; }
        .tb-luu.dang { color: #8a4b00; }
        .tb-luu i { margin-right: 4px; color: var(--tb-xanh); }
        .tb-than { padding: 16px 20px 24px; }

        .tb-ghi { display: flex; gap: 8px; align-items: flex-start; margin-bottom: 14px; padding: 10px 12px; border-radius: 8px; background: var(--tb-nen); color: var(--tb-navy); font-size: 13.5px; line-height: 20px; }
        .tb-ghi > i { margin-top: 3px; }
        .tb-ghi.vang { background: #fff8e6; color: #7a4a00; }
        .tb-note { margin: 10px 0 0; color: var(--tb-mo); font-size: 12.5px; line-height: 18px; }
        .tb-view { display: none; }
        .tb-view.is-on { display: block; }

        /* --- Tổng quan --- */
        .tb-the-luoi { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 16px; }
        @media (max-width: 1199.98px) { .tb-the-luoi { grid-template-columns: minmax(0, 1fr); } }
        .tb-the { display: flex; flex-direction: column; border: 1px solid var(--tb-vien); border-radius: 10px; background: #fff; color: inherit; text-decoration: none; transition: border-color .15s, box-shadow .15s; }
        .tb-the:hover { border-color: var(--tb-navy); box-shadow: inset 4px 0 0 var(--tb-cam); color: inherit; }
        .tb-the:focus-visible { outline: 2px solid #1586ff; outline-offset: 2px; }
        .tb-the-dau { display: flex; gap: 12px; padding: 16px 16px 12px; }
        .tb-the-icon { flex: 0 0 48px; height: 48px; border-radius: 10px; display: grid; place-items: center; background: var(--tb-nen); color: var(--tb-navy); font-size: 21px; }
        .tb-the-ten { margin: 0; font-size: 15px; font-weight: 700; color: var(--tb-navy); }
        .tb-the-mota { margin: 2px 0 0; font-size: 13px; color: var(--tb-mo); line-height: 18px; }
        .tb-the-so { padding: 10px 16px; border-top: 1px solid var(--tb-vien); font-size: 13.5px; }
        .tb-the-so ul { margin: 0; padding: 0; list-style: none; }
        .tb-the-so li { display: flex; gap: 8px; padding: 3px 0; line-height: 19px; }
        .tb-the-so li i { flex: 0 0 15px; margin-top: 2px; }
        .tb-the-so .ok i { color: var(--tb-xanh); }
        .tb-the-so .canh { color: #8a4b00; }
        .tb-the-so .canh i { color: var(--tb-cam); }
        .tb-the-so .nhat { color: var(--tb-mo); }
        .tb-the-so .nhat i { color: #b8bec9; }
        .tb-the-mo { margin-top: auto; padding: 10px 16px; border-top: 1px solid var(--tb-vien); font-size: 13.5px; font-weight: 600; color: var(--tb-navy); }

        /* --- Tab --- */
        .tb-tabs { display: flex; gap: 4px; margin-bottom: 14px; border-bottom: 1px solid var(--tb-vien); overflow-x: auto; }
        .tb-tab { position: relative; flex: 0 0 auto; border: 0; background: none; padding: 10px 14px; font-size: 14px; font-weight: 600; color: var(--tb-mo); white-space: nowrap; cursor: pointer; }
        .tb-tab:hover { color: var(--tb-navy); }
        .tb-tab.is-on { color: var(--tb-navy); }
        .tb-tab.is-on::after { content: ""; position: absolute; left: 8px; right: 8px; bottom: -1px; height: 3px; background: var(--tb-cam); border-radius: 2px; }
        .tb-tab:focus-visible { outline: 2px solid #1586ff; outline-offset: -2px; }
        .tb-dem { display: inline-block; min-width: 20px; margin-left: 4px; padding: 0 6px; border-radius: 10px; background: var(--tb-nen); color: var(--tb-navy); font-size: 12px; line-height: 20px; text-align: center; }
        .tb-pane { display: none; }
        .tb-pane.is-on { display: block; }

        /* --- Bảng (khuôn bảng của Phương thức thanh toán): một dòng, không gãy --- */
        .tb-bang-o { border: 1px solid var(--tb-vien); border-radius: 10px; background: #fff; overflow: hidden; }
        .tb-bang-o .table-responsive { margin: 0; }
        .tb-table { width: 100%; border-collapse: collapse; table-layout: auto; }
        .tb-table th, .tb-table td { padding: 10px 12px; border-bottom: 1px solid #e9ebec; font-size: 14px; white-space: nowrap; vertical-align: middle; }
        .tb-table thead th { background: #D5E1F3; color: #1f2937; font-weight: 700; }
        .tb-table tbody tr:last-child td { border-bottom: 0; }
        /* style.css của v2 ép `th, td { text-align: center !important }` — cột chữ ở
           đây căn trái cho dễ dò nên phải đè lại bằng chính !important. */
        .tb-bang-o .tb-table th, .tb-bang-o .tb-table td { text-align: left !important; }
        .tb-bang-o .tb-table .giua { text-align: center !important; }
        .tb-bang-o .tb-table .phai { text-align: right !important; font-variant-numeric: tabular-nums; }
        .tb-table td.mota { white-space: normal; min-width: 180px; max-width: 280px; color: #4b5563; }
        .tb-table tr.tat td:not(.giu) { color: #9aa0a6; }
        .tb-table tr.tat .tb-chip { opacity: .6; }
        .tb-bang-o .tb-table .action { text-align: right !important; }
        .tb-table .action a, .tb-the-m .action a { display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px; border-radius: 6px; }
        .tb-table .action a:hover, .tb-the-m .action a:hover { background: var(--tb-nen); }
        .tb-ten-may { font-weight: 600; color: #1f2937; }
        .tb-mono { font-family: ui-monospace, "Cascadia Mono", Consolas, monospace; font-size: 13px; }
        .tb-ket { display: inline-flex; align-items: center; gap: 6px; }
        .tb-ket i { color: var(--tb-mo); width: 14px; text-align: center; }
        .tb-nhat { color: var(--tb-mo); }
        .tb-chip { display: inline-flex; align-items: center; gap: 4px; margin: 1px 2px 1px 0; padding: 2px 8px; border-radius: 999px; background: #e7f0ff; color: #1a4fb4; font-size: 12px; font-weight: 500; line-height: 18px; white-space: nowrap; }
        .tb-chip.cam { background: var(--tb-cam-nhat); color: #8a4b00; }
        .tb-chip.xanh { background: var(--tb-xanh-nhat); color: var(--tb-xanh); }
        .tb-chip.vang { background: #fff8e6; color: #7a4a00; }
        .tb-chip.xam { background: #f1f1f1; color: #444; }
        .tb-chip .x { margin-left: 2px; border: 0; background: none; padding: 0 2px; color: inherit; opacity: .6; cursor: pointer; font-size: 11px; line-height: 1; }
        .tb-chip .x:hover { opacity: 1; }
        .tb-trong { display: flex; flex-direction: column; align-items: center; gap: 8px; padding: 40px 16px; text-align: center; }
        .tb-trong-ic { display: inline-flex; align-items: center; justify-content: center; width: 56px; height: 56px; border-radius: 50%; background: var(--tb-nen); color: var(--tb-navy); font-size: 22px; }
        .tb-trong b { color: var(--tb-navy); font-size: 15px; }
        .tb-trong p { max-width: 380px; margin: 0; color: var(--tb-mo); }

        /* Bản thẻ cho màn hẹp (dưới 992px), thay cho bảng — như Phương thức thanh toán. */
        .tb-the-list { display: none; flex-direction: column; gap: 10px; padding: 12px; }
        .tb-the-m { padding: 10px 12px; border: 1px solid var(--tb-vien); border-radius: 8px; }
        .tb-the-m.tat .tb-the-m-ten, .tb-the-m.tat .tb-the-m-dong { color: #9aa0a6; }
        .tb-the-m-dau { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
        .tb-the-m-ten { font-weight: 700; color: var(--tb-navy); }
        .tb-the-m-dong { display: flex; justify-content: space-between; gap: 10px; margin-top: 4px; font-size: 13px; }
        .tb-the-m-dong > span:first-child { color: var(--tb-mo); white-space: nowrap; }
        .tb-the-m-dong > span:last-child { text-align: right; }
        .tb-the-m-duoi { display: flex; justify-content: flex-end; gap: 4px; margin-top: 6px; padding-top: 6px; border-top: 1px dashed var(--tb-vien); }
        @media (max-width: 991.98px) {
            .tb-bang-o .table-responsive { display: none; }
            .tb-the-list { display: flex; }
        }

        /* Dải phủ phiếu */
        .tb-phu { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 12px; }
        .tb-phu-o { display: inline-flex; align-items: center; gap: 6px; padding: 6px 10px; border: 1px solid var(--tb-vien); border-radius: 8px; font-size: 13px; white-space: nowrap; }
        .tb-phu-o.ok i { color: var(--tb-xanh); }
        .tb-phu-o.thieu { border-color: #ffd8a8; background: var(--tb-cam-nhat); color: #8a4b00; }
        .tb-phu-o.thieu i { color: var(--tb-cam); }
        .tb-phu-o small { color: var(--tb-mo); }

        /* --- Mẫu in --- */
        .tb-mau-tabs { display: flex; flex-wrap: wrap; gap: 6px; }
        .tb-mau-tab { border: 1px solid var(--tb-vien); border-radius: 999px; background: #fff; padding: 6px 14px; font-size: 13.5px; font-weight: 500; color: #374151; white-space: nowrap; cursor: pointer; }
        .tb-mau-tab:hover { border-color: var(--tb-navy); color: var(--tb-navy); }
        .tb-mau-tab.is-on { border-color: var(--tb-navy); background: var(--tb-navy); color: #fff; }
        .tb-mau-tab:focus-visible { outline: 2px solid #1586ff; outline-offset: 1px; }
        .tb-mau { display: grid; grid-template-columns: minmax(0, 1fr) 372px; gap: 20px; margin-top: 14px; align-items: start; }
        @media (max-width: 1199.98px) { .tb-mau { grid-template-columns: minmax(0, 1fr); } }
        .tb-mau-dau { display: flex; flex-wrap: wrap; gap: 12px 24px; padding: 12px 14px; border: 1px solid var(--tb-vien); border-radius: 10px; background: var(--tb-nen); }
        .tb-mau-dau .tb-truong { margin: 0; }
        .tb-mau-dau .tb-truong > span:first-child { display: block; margin-bottom: 4px; font-size: 12.5px; font-weight: 600; color: var(--tb-mo); }
        .tb-mau-chinh { display: flex; flex-wrap: wrap; gap: 12px 24px; }
        .tb-tuy { display: inline-flex; align-items: center; gap: 8px; }
        .tb-tuy select { width: 140px; }
        .tb-sap { font-style: normal; font-size: 12px; color: var(--tb-mo); white-space: nowrap; }
        .tsc-input { height: 34px; padding: 0 10px; border: 1px solid #ced4da; border-radius: 6px; background: #fff; color: #212529; font-size: 14px; }
        .tsc-input:focus { border-color: #86b7fe; outline: 0; box-shadow: 0 0 0 .25rem rgba(13, 110, 253, .25); }
        .tsc-input:disabled { background: #e9ecef; color: #6c757d; }
        .tb-nhom { margin-top: 16px; }
        .tb-nhom-dau { display: flex; align-items: baseline; justify-content: space-between; gap: 8px; padding-bottom: 6px; margin-bottom: 6px; border-bottom: 1px solid var(--tb-vien); font-size: 14px; font-weight: 700; color: var(--tb-navy); }
        .tb-nhom-dau button { border: 0; background: none; padding: 0; font-size: 12.5px; font-weight: 500; color: #1a4fb4; cursor: pointer; white-space: nowrap; }
        .tb-o-luoi { display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 0 16px; }
        .tb-o { display: flex; align-items: flex-start; gap: 8px; padding: 5px 0; margin: 0; font-size: 14px; line-height: 20px; cursor: pointer; }
        .tb-o input { flex: 0 0 16px; width: 16px; height: 16px; margin-top: 2px; cursor: pointer; accent-color: var(--tb-navy); }
        .tb-o.con { padding-left: 24px; color: #4b5563; }
        .tb-o.an { display: none; }
        .tb-o-them { grid-column: 1 / -1; padding: 0 0 6px 24px; }
        .tb-o-them input { width: 100%; max-width: 440px; }
        .tb-o-them small { display: block; margin-top: 3px; color: var(--tb-mo); font-size: 12px; }
        .tb-mau-chan { margin-top: 16px; padding-top: 10px; border-top: 1px solid var(--tb-vien); }
        .tb-mau-phai { position: sticky; top: 12px; }
        .tb-xem-dau { display: flex; align-items: baseline; flex-wrap: wrap; gap: 2px 8px; margin-bottom: 8px; font-size: 14px; font-weight: 700; color: var(--tb-navy); }
        .tb-xem-dau > span { white-space: nowrap; }
        .tb-xem-dau small { font-weight: 400; color: var(--tb-mo); font-size: 12.5px; }
        .tb-xem-dau .btn { margin-left: auto; white-space: nowrap; }
        .tb-giay-khung { display: flex; justify-content: center; padding: 16px 10px; border-radius: 10px; background: #e9edf3; max-height: calc(100vh - 230px); overflow: auto; }
        .tb-xem-that { overflow: hidden; background: #fff; box-shadow: 0 1px 4px rgba(16, 24, 40, .18); }
        .tb-xem-that iframe { display: block; border: 0; background: #fff; transform-origin: 0 0; }
        @media (max-width: 1199.98px) { .tb-mau-phai { position: static; } .tb-giay-khung { max-height: none; } }

        /* Giấy in nhiệt */
        .gi { flex: 0 0 auto; background: #fff; color: #000; box-shadow: 0 1px 4px rgba(0,0,0,.12); font-family: ui-monospace, "Cascadia Mono", Consolas, "DejaVu Sans Mono", monospace; line-height: 1.45; }
        .gi.k80 { width: 302px; padding: 14px 8px 18px; font-size: 11.5px; }
        .gi.k58 { width: 218px; padding: 12px 6px 16px; font-size: 10px; }
        .gi .g { text-align: center; } .gi .d { font-weight: 700; } .gi .n { font-size: .88em; }
        .gi .tt { margin: 0; font-size: 1.25em; font-weight: 700; }
        .gi .ke { margin: 4px 0; border-top: 1px dashed #000; }
        .gi .dg { display: flex; justify-content: space-between; gap: 6px; }
        .gi .dg span:last-child { white-space: nowrap; }
        .gi .ct { padding-left: 8px; }
        .gi .to { margin-top: 3px; font-size: 1.2em; font-weight: 700; }
        .gi .logo { display: block; width: 46px; height: 46px; margin: 0 auto 4px; }
        .gi .qr { display: block; width: 92px; height: 92px; margin: 6px auto 2px; }
        .gi .ky { display: flex; justify-content: space-around; margin-top: 10px; text-align: center; }
        .gi .ky div { min-height: 48px; }
        .gi .sao { letter-spacing: 2px; }
        .gi-moi { outline: 2px solid rgba(242, 146, 32, .6); outline-offset: 1px; background: #fff4e5; transition: background 1.2s, outline-color 1.2s; }
        .tem-luoi { display: grid; gap: 6px; }
        .tem { box-sizing: border-box; background: #fff; color: #000; box-shadow: 0 1px 4px rgba(0,0,0,.12); padding: 5px 6px; font-family: Arial, sans-serif; line-height: 1.25; overflow: hidden; }
        .tem.nguoc { transform: rotate(180deg); }
        .tem .d { font-weight: 700; } .tem .n { font-size: .85em; }
        .tem .dg { display: flex; justify-content: space-between; gap: 4px; }
        .giay-a { background: #fff; color: #111; box-shadow: 0 1px 4px rgba(0,0,0,.12); font-family: Arial, sans-serif; }
        .giay-a table { width: 100%; border-collapse: collapse; }
        .giay-a th, .giay-a td { border: 1px solid #555; padding: 3px 4px; }
        .giay-a th { background: #f2f2f2; }
        .giay-a .p { text-align: right; } .giay-a .g { text-align: center; }
        .giay-a .ky { display: flex; justify-content: space-between; margin-top: 14px; text-align: center; font-size: .92em; }
        .giay-a .ky div { flex: 1; min-height: 60px; }
        .giay-a .ky small { display: block; font-style: italic; color: #555; }

        /* --- Ô trong hộp thoại --- */
        .tb-loi { display: none; margin-top: 4px; font-size: 12.5px; color: #dc3545; }
        .tb-loi.is-on { display: block; }
        .form-control.is-loi, .form-select.is-loi { border-color: #dc3545; }
        .tb-goi { display: block; margin-top: 4px; font-size: 12.5px; color: var(--tb-mo); line-height: 17px; }
        /* Dò tìm máy in */
        .tb-do { margin-top: 10px; }
        .tb-do-dau { display: flex; align-items: center; flex-wrap: wrap; gap: 6px 10px; }
        .tb-do-dau .btn { white-space: nowrap; }
        .tb-do-kq { margin-top: 8px; padding: 10px; border: 1px dashed var(--tb-vien); border-radius: 10px; background: var(--tb-nen); }
        .tb-do-ds { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 6px; }
        .tb-do-ds button { display: flex; flex-direction: column; align-items: flex-start; gap: 1px; padding: 8px 10px; border: 1px solid var(--tb-vien); border-radius: 8px; background: #fff; text-align: left; cursor: pointer; }
        .tb-do-ds button:hover:not(:disabled) { border-color: var(--tb-navy); box-shadow: inset 4px 0 0 var(--tb-cam); }
        .tb-do-ds button:disabled { cursor: not-allowed; opacity: .6; }
        .tb-do-ds button.chon { border-color: var(--tb-navy); background: var(--tb-cam-nhat); }
        .tb-do-ds b { font-size: 13.5px; color: var(--tb-navy); }
        .tb-do-ds small { color: var(--tb-mo); font-size: 12px; }
        .tb-do-kq p { margin: 0; font-size: 13px; color: #374151; line-height: 19px; }
        .tb-do-kq ol { margin: 4px 0 0; padding-left: 18px; font-size: 13px; color: #374151; line-height: 19px; }
        .tb-do-chan { display: flex; justify-content: flex-end; margin-top: 6px; }
        .tb-chi-doc { padding: 7px 12px; border-radius: 6px; background: var(--tb-nen); font-weight: 600; color: var(--tb-navy); }
        .tb-chon4 { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 8px; }
        .tb-chon2 { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; }
        @media (max-width: 575.98px) { .tb-chon4 { grid-template-columns: repeat(2, minmax(0, 1fr)); } .tb-chon2 { grid-template-columns: minmax(0, 1fr); } }
        .tb-chon4 label, .tb-chon2 label { margin: 0; cursor: pointer; }
        /* Ô chọn thật ẩn hẳn — CSS v2 tô lại ô :disabled nên opacity thôi chưa đủ. */
        .tb-chon4 input, .tb-chon2 input, .tb-doan input, .tb-chip-chon input { position: absolute; opacity: 0 !important; width: 0 !important; height: 0 !important; margin: 0; pointer-events: none; -webkit-appearance: none; appearance: none; }
        .tb-chon4 span, .tb-chon2 span { display: flex; flex-direction: column; height: 100%; padding: 10px 12px; border: 1px solid var(--tb-vien); border-radius: 10px; transition: border-color .15s, background .15s; }
        .tb-chon4 span i { font-size: 18px; color: #9aa0a6; margin-bottom: 4px; }
        .tb-chon4 b, .tb-chon2 b { font-size: 14px; color: var(--tb-navy); }
        .tb-chon4 small, .tb-chon2 small { margin-top: 2px; color: var(--tb-mo); font-size: 12.5px; line-height: 17px; }
        .tb-chon4 input:checked + span, .tb-chon2 input:checked + span { border-color: var(--tb-navy); background: var(--tb-nen); box-shadow: inset 4px 0 0 var(--tb-cam); }
        .tb-chon4 input:checked + span i { color: var(--tb-cam); }
        .tb-chon4 input:focus-visible + span, .tb-chon2 input:focus-visible + span, .tb-doan input:focus-visible + span, .tb-chip-chon input:focus-visible + span { box-shadow: 0 0 0 .25rem rgba(13, 110, 253, .25); }
        .tb-chon2 input:disabled + span { cursor: not-allowed; opacity: .55; }
        .tb-chon2 input:disabled:checked + span { opacity: 1; }
        .tb-doan { display: inline-flex; border: 1px solid #ced4da; border-radius: 6px; overflow: hidden; }
        .tb-doan label { margin: 0; cursor: pointer; }
        .tb-doan label + label span { border-left: 1px solid #ced4da; }
        .tb-doan span { display: block; padding: 0 12px; height: 34px; line-height: 34px; font-size: 14px; white-space: nowrap; }
        .tb-doan input:checked + span { background: var(--tb-navy); color: #fff; }
        .tb-doan input:disabled + span { cursor: not-allowed; color: #adb5bd; }
        .tb-doan input:disabled:checked + span { background: #adb5bd; color: #fff; }
        .tb-chip-chon { display: flex; flex-wrap: wrap; gap: 6px; }
        .tb-chip-chon label { margin: 0; cursor: pointer; }
        .tb-chip-chon span { display: inline-flex; align-items: center; gap: 5px; padding: 5px 12px; border: 1px solid #ced4da; border-radius: 999px; font-size: 13.5px; white-space: nowrap; }
        .tb-chip-chon input:checked + span { border-color: var(--tb-navy); background: var(--tb-navy); color: #fff; }
        .tb-chip-chon input:checked + span::before { content: "\2713"; font-size: 11px; }
        .tb-chip-chon input:disabled + span { cursor: not-allowed; color: #adb5bd; border-style: dashed; }
        .tb-chip-chon .tb-tat-ca span { font-weight: 700; }
        .tb-btn-link { border: 0; background: none; padding: 0; color: #1a4fb4; font-size: 13.5px; cursor: pointer; }
        .tb-btn-link:hover { text-decoration: underline; }

        .tb-chi-xem .tb-sua { display: none !important; }

        @media (max-width: 1439.98px) {
            .tb-table th, .tb-table td { padding: 8px 10px; font-size: 13.5px; }
        }
        /* Dưới 1200: cột mục lên trên thành dải ngang, trả bề rộng cho bảng. */
        @media (max-width: 1199.98px) {
            .tb-trai-cot { margin-bottom: 10px; }
            .tb-muc .card-header { display: none; }
            .tb-muc .card-body { padding: 8px !important; }
            .tb-nav { flex-direction: row; gap: 6px; overflow-x: auto; }
            .tb-nav-con { display: contents; margin: 0; padding: 0; border: 0; }
            .tb-nav-muc, .tb-nav-con a { flex: 0 0 auto; white-space: nowrap; padding: 6px 12px; border: 1px solid #dee2e6; border-radius: 999px; font-size: 13.5px; }
            .tb-phu-cot { display: none; }
            .tb-table td.mota { min-width: 140px; max-width: 220px; }
        }
        @media (max-width: 991.98px) {
            .tb-dau { padding: 12px 14px !important; }
            .tb-than { padding: 12px 12px 80px; }
        }
        @media (max-width: 575.98px) {
            .tb-dau-phai { width: 100%; }
            .tb-dau-phai .bt { margin-left: auto; }
        }
    </style>
@endpush

@section('content')
    <div class="row index-device-setting-page tb">
        {{-- Cột trái: các trang con của Thông số chung, đúng lối bản cũ (system/parameter/sidebar). --}}
        <div class="col-12 col-xl-2 pe-xl-0 tb-trai-cot">
            <div class="card tb-muc">
                <div class="card-header card-header-primary header_search">{{ \App\Http\Controllers\ParameterController::TITLE }}</div>
                <div class="card-body px-2">
                    <nav class="tb-nav" aria-label="{{ \App\Http\Controllers\ParameterController::TITLE }}">
                        <a href="{{ route('admin.thong-so-chung.numbering-rule') }}" class="tb-nav-muc">{{ \App\Http\Controllers\ParameterController::PAGES['numbering-rule']['title'] }}</a>
                        <a href="?v=tong-quan" class="tb-nav-muc" data-nav="tong-quan">{{ $C::TITLE }}</a>
                        <div class="tb-nav-con">
                            <a href="?v=may-in" data-nav="may-in"><i class="fa-solid fa-print"></i>Máy in</a>
                            <a href="?v=mau-in" data-nav="mau-in"><i class="fa-regular fa-file-lines"></i>Mẫu in</a>
                            <a href="?v=quet-the" data-nav="quet-the"><i class="fa-regular fa-credit-card"></i>Máy quẹt thẻ</a>
                        </div>
                    </nav>
                </div>
            </div>
        </div>

        <div class="col-12 col-xl-10">
            <div class="content_midd">
                <div class="content_midd_title tb-dau">
                    <div class="tb-dau-chu">
                        <h1 class="tieu-de-trang" id="tbTitle">{{ $C::TITLE }}</h1>
                        <p class="tb-sub" id="tbSub"></p>
                    </div>
                    <div class="tb-dau-phai">
                        <span class="tb-luu" id="tbLuu" hidden></span>
                        @if ($tenChiNhanh !== '')
                            <span class="tb-cn" title="Mỗi chi nhánh có bộ thiết bị riêng. Đổi chi nhánh ở góc trên để cấu hình chi nhánh khác."><i class="fa-solid fa-store"></i> {{ $tenChiNhanh }}</span>
                        @endif
                        <a type="button" class="bt btn_green tb-sua" id="tbNutChinh" hidden><i class="fa-solid fa-plus me-1"></i><span>Thêm</span></a>
                    </div>
                </div>

                <div class="tb-than">
                    @if ($loiTai)
                        <div class="tb-trong">
                            <span class="tb-trong-ic"><i class="fa-solid fa-triangle-exclamation"></i></span>
                            <p>{{ $loiTai }}</p>
                            <a href="{{ route('admin.thong-so-chung.devices') }}" class="bt btn_gray">Tải lại</a>
                        </div>
                    @else
                        <div class="tb-ghi vang" id="tbChiXem" hidden><i class="fa-solid fa-eye"></i><span>Bạn chỉ có quyền xem. Muốn sửa, nhờ quản trị cấp quyền <b>Hệ thống → Cài đặt thiết bị</b>.</span></div>

                        <div class="tb-view" data-view="tong-quan">
                            <div class="tb-the-luoi" id="tbTongQuan"></div>
                        </div>

                        <div class="tb-view" data-view="may-in">
                            <div class="tb-tabs" role="tablist">
                                <button type="button" class="tb-tab" data-tab="ds" role="tab">Danh sách máy in <span class="tb-dem" id="tbDemMay"></span></button>
                                <button type="button" class="tb-tab" data-tab="phieu" role="tab">Phiếu in ra máy nào</button>
                                <button type="button" class="tb-tab" data-tab="nut" role="tab">Nút in</button>
                            </div>
                            <div class="tb-pane" data-pane="ds">
                                <div class="tb-ghi"><i class="fa-solid fa-circle-info"></i><span>Đây là sổ máy in của chi nhánh. Phiếu ở quầy hiện vẫn in qua hộp in của trình duyệt; sổ này dùng khi có ứng dụng in trên máy quầy gửi thẳng ra từng máy.</span></div>
                                <div class="tb-bang-o" id="tbBangMay"></div>
                            </div>
                            <div class="tb-pane" data-pane="phieu">
                                <div class="tb-phu" id="tbPhuPhieu"></div>
                                <div class="tb-bang-o" id="tbBangPhieu"></div>
                                <p class="tb-note">Tem in theo <b>nhóm hàng</b>: món thuộc nhóm nào ra máy đó. Hai dòng tem của cùng một máy không được chung nhóm hàng. Các phiếu khác mỗi máy chỉ gán một lần.</p>
                            </div>
                            <div class="tb-pane" data-pane="nut">
                                <div class="tb-bang-o" id="tbBangNut"></div>
                                <p class="tb-note">Bấm nút ở màn Thu ngân thì in lần lượt các phiếu trong cột <b>In ra</b>, mỗi phiếu ra máy đã gán ở tab trước. Bỏ hết phiếu là bấm nút không in gì.</p>
                            </div>
                        </div>

                        <div class="tb-view" data-view="mau-in">
                            <div class="tb-mau-tabs" id="tbMauTabs" role="tablist"></div>
                            <div class="tb-mau">
                                <div class="tb-mau-trai">
                                    <div class="tb-mau-dau"><div class="tb-mau-chinh" id="tbMauChinh"></div></div>
                                    <div id="tbMauNhom"></div>
                                    <div class="tb-mau-chan">
                                        <button type="button" class="tb-btn-link tb-sua" id="tbMacDinh"><i class="fa-solid fa-rotate-left"></i> Về mặc định</button>
                                    </div>
                                </div>
                                <div class="tb-mau-phai">
                                    <div class="tb-xem-dau">
                                        <span>Xem trước</span>
                                        <small id="tbXemKho"></small>
                                        {{-- In đúng tờ đang xem ra máy in mặc định — thử khổ giấy, lề, logo trước khi bán thật. --}}
                                        <button type="button" class="btn btn-sm btn-outline-secondary" id="tbInThu" hidden><i class="fa-solid fa-print"></i> In thử</button>
                                    </div>
                                    <div class="tb-giay-khung"><div id="tbXem"></div></div>
                                    <p class="tb-note">Số liệu mẫu, vẽ bằng <b>đúng trang phiếu in thật</b> của quầy (Hoá đơn, A5, A4, Phiếu trả hàng). Tên, địa chỉ, điện thoại, logo lấy từ <b>Cài đặt → Chung</b>. Quầy in Hoá đơn khi bán; A5 / A4 và phiếu trả in từ hộp <b>In lại</b> ở màn Đơn hàng.</p>
                                </div>
                            </div>
                        </div>

                        <div class="tb-view" data-view="quet-the">
                            <div class="tb-ghi"><i class="fa-solid fa-circle-info"></i><span>Đây là danh sách <b>máy</b>. Khoá cổng NextPay và phí thẻ khai ở <a href="{{ route('admin.phuong-thuc-tt.index') }}">Phương thức thanh toán → Quẹt thẻ</a>, rồi chọn máy ở đó.</span></div>
                            <div class="tb-bang-o" id="tbBangQuet"></div>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    @unless ($loiTai)
    {{-- Hộp thoại theo khuôn v2: đầu #D5E1F3, chân Đóng đỏ / Lưu xanh ở giữa. --}}
    <div class="modal fade tb-hop" id="mdMay" tabindex="-1" aria-hidden="true" aria-labelledby="mdMayTieuDe">
        <div class="modal-dialog modal-dialog-centered modal-lg modal-fullscreen-sm-down">
            <form class="modal-content" id="fMay" novalidate>
                <div class="modal-header"><h4 class="modal-title" id="mdMayTieuDe">Thêm máy in</h4><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Đóng"></button></div>
                <div class="modal-body">
                    <div class="tb-truong" data-o="conn_type"><label class="form-label">Kết nối</label>
                        <div class="tb-chon4">
                            <label><input type="radio" name="loai" value="lan" checked><span><i class="fa-solid fa-network-wired"></i><b>Mạng LAN / Wi-Fi</b><small>Máy có địa chỉ IP</small></span></label>
                            <label><input type="radio" name="loai" value="usb"><span><i class="fa-brands fa-usb"></i><b>USB</b><small>Cắm vào máy quầy</small></span></label>
                            <label><input type="radio" name="loai" value="bt"><span><i class="fa-brands fa-bluetooth-b"></i><b>Bluetooth</b><small>Ghép đôi với máy quầy</small></span></label>
                            <label><input type="radio" name="loai" value="urovo"><span><i class="fa-solid fa-mobile-screen"></i><b>Máy POS Urovo</b><small>Máy in liền thân</small></span></label>
                        </div><div class="tb-loi"></div>
                    </div>
                    {{-- Dò tìm: app Android của bản cũ (window.Android) dò được cả ba loại;
                         trên trình duyệt chỉ USB / Bluetooth qua hộp chọn thiết bị của Chrome. --}}
                    <div class="tb-do" id="fMayDoO">
                        <div class="tb-do-dau">
                            <button type="button" class="btn btn-sm btn-outline-secondary" id="fMayDo"><i class="fa-solid fa-magnifying-glass me-1"></i><span>Dò tìm máy in</span></button>
                            <small class="tb-goi m-0" id="fMayDoGoi"></small>
                        </div>
                        <div class="tb-do-kq" id="fMayDoKq" hidden></div>
                    </div>
                    <div class="row g-3 mt-0">
                        <div class="col-md-6 tb-truong" data-o="name"><label class="form-label" for="fMayTen">Tên máy in <span class="required" style="color:red">*</span></label>
                            <input id="fMayTen" class="form-control" maxlength="100" placeholder="VD: Máy in quầy 1" autocomplete="off"><div class="tb-loi"></div></div>
                        <div class="col-md-6 tb-truong" id="fMayDcO" data-o="address"><label class="form-label" for="fMayDc"><span id="fMayDcNhan">Địa chỉ IP</span> <span class="required" style="color:red" id="fMayDcBb">*</span></label>
                            <input id="fMayDc" class="form-control" placeholder="VD: 192.168.1.50" inputmode="decimal" autocomplete="off"><div class="tb-loi"></div></div>
                        <div class="col-md-6 tb-truong" data-o="paper"><label class="form-label">Khổ giấy</label>
                            <div class="tb-doan">
                                <label><input type="radio" name="kho" value="58"><span>58mm</span></label>
                                <label><input type="radio" name="kho" value="80" checked><span>80mm</span></label>
                                <label><input type="radio" name="kho" value="a5"><span>A5</span></label>
                                <label><input type="radio" name="kho" value="a4"><span>A4</span></label>
                            </div><div class="tb-loi"></div></div>
                        <div class="col-6 col-md-3 tb-truong" data-o="dpi"><label class="form-label">Độ phân giải</label>
                            <div class="tb-doan">
                                <label><input type="radio" name="dpi" value="203" checked><span>203 dpi</span></label>
                                <label><input type="radio" name="dpi" value="180"><span>180 dpi</span></label>
                            </div><div class="tb-loi"></div></div>
                        <div class="col-6 col-md-3 tb-truong"><label class="form-label d-block" for="fMayBat">Trạng thái</label>
                            <input type="checkbox" class="switch_customer" id="fMayBat" checked></div>
                    </div>
                    @if ($tenChiNhanh !== '')
                        <p class="tb-note mb-0">Chi nhánh: <b>{{ $tenChiNhanh }}</b>. Máy in thuộc về một chi nhánh, không dùng chung.</p>
                    @endif
                </div>
                <div class="modal-footer justify-content-center"><button type="button" class="bt btn_red" data-bs-dismiss="modal">Đóng</button><button type="submit" class="bt btn_green">Lưu</button></div>
            </form>
        </div>
    </div>

    <div class="modal fade tb-hop" id="mdPhieu" tabindex="-1" aria-hidden="true" aria-labelledby="mdPhieuTieuDe">
        <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down">
            <form class="modal-content" id="fPhieu" novalidate>
                <div class="modal-header"><h4 class="modal-title" id="mdPhieuTieuDe">Gán phiếu cho máy in</h4><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Đóng"></button></div>
                <div class="modal-body">
                    <div class="tb-truong" data-o="slip_type"><label class="form-label" for="fPhieuLoai">Loại phiếu</label>
                        <select id="fPhieuLoai" class="form-select"></select><div class="tb-loi"></div></div>
                    <div class="tb-truong mt-3" data-o="printer_id"><label class="form-label" for="fPhieuMay">Máy in <span class="required" style="color:red">*</span></label>
                        <select id="fPhieuMay" class="form-select"></select><div class="tb-loi"></div>
                        <small class="tb-goi" id="fPhieuMayGoi"></small></div>
                    <div class="tb-truong mt-3" id="fPhieuNhomO" data-o="category_ids"><label class="form-label">Nhóm hàng <span class="required" style="color:red">*</span></label>
                        <div class="tb-chip-chon" id="fPhieuNhom"></div><div class="tb-loi"></div></div>
                    <div class="tb-truong mt-3"><label class="form-label d-block" for="fPhieuBat">Trạng thái</label>
                        <input type="checkbox" class="switch_customer" id="fPhieuBat" checked></div>
                </div>
                <div class="modal-footer justify-content-center"><button type="button" class="bt btn_red" data-bs-dismiss="modal">Đóng</button><button type="submit" class="bt btn_green">Lưu</button></div>
            </form>
        </div>
    </div>

    <div class="modal fade tb-hop" id="mdNut" tabindex="-1" aria-hidden="true" aria-labelledby="mdNutTieuDe">
        <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down">
            <form class="modal-content" id="fNut" novalidate>
                <div class="modal-header"><h4 class="modal-title" id="mdNutTieuDe">Sửa nút in</h4><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Đóng"></button></div>
                <div class="modal-body">
                    <div class="tb-truong"><label class="form-label">Nút bấm</label><div class="tb-chi-doc" id="fNutTen"></div></div>
                    <div class="tb-truong mt-3" data-o="slips"><label class="form-label">In ra</label><div class="tb-chip-chon" id="fNutPhieu"></div><div class="tb-loi"></div>
                        <small class="tb-goi" id="fNutGoi"></small></div>
                    <div class="tb-truong mt-3"><label class="form-label d-block" for="fNutBat">Trạng thái</label>
                        <input type="checkbox" class="switch_customer" id="fNutBat"></div>
                </div>
                <div class="modal-footer justify-content-center"><button type="button" class="bt btn_red" data-bs-dismiss="modal">Đóng</button><button type="submit" class="bt btn_green">Lưu</button></div>
            </form>
        </div>
    </div>

    <div class="modal fade tb-hop" id="mdQuet" tabindex="-1" aria-hidden="true" aria-labelledby="mdQuetTieuDe">
        <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down">
            <form class="modal-content" id="fQuet" novalidate>
                <div class="modal-header"><h4 class="modal-title" id="mdQuetTieuDe">Thêm máy quẹt thẻ</h4><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Đóng"></button></div>
                <div class="modal-body">
                    <div class="tb-truong"><label class="form-label">Loại máy</label>
                        <div class="tb-chon2">
                            <label><input type="radio" name="qloai" value="0" checked><span><b>mPOS (NextPay)</b><small>Thu ngân bấm gửi số tiền xuống máy, NextPay báo về là đơn tự hoàn tất.</small></span></label>
                            <label><input type="radio" name="qloai" value="1"><span><b>Máy độc lập</b><small>Máy POS của ngân hàng. Thu ngân gõ tiền trên máy và tự xác nhận đã nhận.</small></span></label>
                        </div>
                        <small class="tb-goi" id="fQuetLoaiGoi" hidden>Loại máy không đổi được — cấu hình ở Phương thức thanh toán đang dựa vào nó. Muốn đổi thì thêm máy mới.</small>
                    </div>
                    <div class="tb-truong mt-3" data-o="code"><label class="form-label" for="fQuetMa">POS ID <span class="required" style="color:red">*</span></label>
                        <input id="fQuetMa" class="form-control tb-mono" maxlength="50" placeholder="Số serial in sau lưng máy" autocomplete="off"><div class="tb-loi"></div></div>
                    <div class="tb-truong mt-3" data-o="name"><label class="form-label" for="fQuetTen">Tên máy <span class="required" style="color:red">*</span></label>
                        <input id="fQuetTen" class="form-control" maxlength="100" placeholder="VD: Máy mPOS quầy 1" autocomplete="off"><div class="tb-loi"></div></div>
                    <div class="tb-truong mt-3" data-o="description"><label class="form-label" for="fQuetMoTa">Mô tả</label>
                        <textarea id="fQuetMoTa" class="form-control" rows="2" maxlength="255" placeholder="Đặt ở đâu, ai giữ…"></textarea><div class="tb-loi"></div></div>
                </div>
                <div class="modal-footer justify-content-center"><button type="button" class="bt btn_red" data-bs-dismiss="modal">Đóng</button><button type="submit" class="bt btn_green">Lưu</button></div>
            </form>
        </div>
    </div>

    <div class="modal fade tb-hop" id="mdXoa" tabindex="-1" aria-hidden="true" aria-labelledby="mdXoaTieuDe">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header"><h6 class="modal-title" id="mdXoaTieuDe">Xoá ?</h6><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Đóng"></button></div>
                <div class="modal-body"><div class="modal_center" id="mdXoaNoiDung"></div></div>
                <div class="modal-footer justify-content-center"><button type="button" class="bt btn_gray" data-bs-dismiss="modal" id="mdXoaDong">Đóng</button><button type="button" class="bt btn_red" id="mdXoaNut">Xoá</button></div>
            </div>
        </div>
    </div>

    <script type="application/json" id="tbData">@json(['data' => $data, 'pm' => $pm, 'loi_pm' => $loiPM])</script>
    @endunless
@endsection

@unless ($loiTai)
@push('scripts')
    <script>
    (function () {
        'use strict';
        var GOC = JSON.parse(document.getElementById('tbData').textContent || '{}');
        var D = GOC.data || {}, PM = GOC.pm, LOI_PM = GOC.loi_pm;
        var CH = @json(['ten' => $cuaHang['name'], 'diachi' => $cuaHang['address'], 'sdt' => $cuaHang['phone']]);
        var URL = {
            data: @json(route('admin.thong-so-chung.devices.data')),
            goc: @json(url('/admin/parameters/devices')),
            may: @json(route('admin.phuong-thuc-tt.taoMay')),
            mayGoc: @json(url('/admin/payment-devices')),
            pm: @json(route('admin.phuong-thuc-tt.index'))
        };
        var q = new URLSearchParams(location.search);
        var $1 = function (s, r) { return (r || document).querySelector(s); };
        var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
        var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
        var so = function (n) { return Number(n || 0).toLocaleString('vi-VN'); };
        var bao = function (ok, msg) { if (msg && window.toastr) toastr[ok ? 'success' : 'error'](msg); };
        var sua = function () { return !!D.can_edit; };

        // ---------- Gọi API ----------
        function goi(method, url, body) {
            // Gắn chi nhánh CỦA TAB vào từng lượt gọi, như Phương thức thanh toán.
            if (window.V2 && V2.themChiNhanh) url = V2.themChiNhanh(url);
            return $.ajax({ url: url, method: method, data: body ? JSON.stringify(body) : undefined, contentType: 'application/json', dataType: 'json' });
        }
        function napLai() {
            return goi('GET', URL.data).then(function (r) { D = r.data || D; PM = r.pm; LOI_PM = r.loi_pm; veLai(); });
        }
        function toLoi(form, errors) {
            Object.keys(errors || {}).forEach(function (k) {
                var o = form.querySelector('[data-o="' + k + '"]');
                if (!o) return;
                var l = o.querySelector('.tb-loi'); if (l) { l.textContent = errors[k]; l.classList.add('is-on'); }
                var i = o.querySelector('.form-control, .form-select'); if (i) i.classList.add('is-loi');
            });
            var dau = form.querySelector('.is-loi'); if (dau) dau.focus();
        }
        function xoaLoi(form) {
            $$('.tb-loi', form).forEach(function (l) { l.textContent = ''; l.classList.remove('is-on'); });
            $$('.is-loi', form).forEach(function (i) { i.classList.remove('is-loi'); });
        }
        // Lượt ghi: được thì báo + nạp lại; hỏng thì tô ô (nếu có hộp) và vẽ lại
        // từ dữ liệu cũ — công tắc vừa bấm tự về chỗ cũ.
        function ghi(method, url, body, form) {
            return goi(method, url, body).then(function (r) {
                bao(true, r.message);
                return napLai().then(function () { return true; });
            }, function (x) {
                var j = x.responseJSON || {};
                if (form && j.errors) toLoi(form, j.errors);
                bao(false, j.message || 'Không lưu được. Vui lòng thử lại.');
                veLai();
                return false;
            });
        }

        // ---------- Tra cứu ----------
        var KET = {
            lan: { ten: 'LAN', icon: 'fa-solid fa-network-wired' }, usb: { ten: 'USB', icon: 'fa-brands fa-usb' },
            bt: { ten: 'Bluetooth', icon: 'fa-brands fa-bluetooth-b' }, urovo: { ten: 'Urovo', icon: 'fa-solid fa-mobile-screen' }
        };
        var KHO = { '58': '58mm', '80': '80mm', a5: 'A5', a4: 'A4' };
        var phieu = function (ma) { return (D.slips || []).filter(function (p) { return p.ma === ma; })[0] || { ma: ma, ten: ma }; };
        var may = function (id) { return (D.printers || []).filter(function (m) { return m.id === id; })[0]; };
        var nhom = function (id) { var n = (D.groups || []).filter(function (g) { return g.id === id; })[0]; return n ? n.name : '#' + id; };
        var mayBat = function (id) { var m = may(id); return m && m.is_active; };
        var mau = function (ma) { return (D.templates || []).filter(function (m) { return m.ma === ma; })[0]; };

        // ---------- Điều hướng ----------
        var VIEW = {
            'tong-quan': { tieuDe: @json($C::TITLE), phu: 'Máy in, mẫu phiếu và máy quẹt thẻ của chi nhánh. Phiếu in và thanh toán ở màn Thu ngân dùng các cài đặt này.' },
            'may-in': { tieuDe: 'Máy in', phu: 'Khai máy in, chọn phiếu nào in ra máy nào và bấm nút nào thì in phiếu gì.' },
            'mau-in': { tieuDe: 'Mẫu in', phu: 'Bật / tắt từng dòng trên phiếu — đổi là tự lưu và xem ngay bên phải. Áp cho lần in sau, phiếu đã in không đổi.' },
            'quet-the': { tieuDe: 'Máy quẹt thẻ', phu: 'Máy POS / mPOS của chi nhánh. Thu ngân chọn máy khi khách trả bằng thẻ.' }
        };
        var view = VIEW[q.get('v')] ? q.get('v') : 'tong-quan';
        var tab = ['ds', 'phieu', 'nut'].indexOf(q.get('tab')) >= 0 ? q.get('tab') : 'ds';
        var mauMo = mau(q.get('mau')) ? q.get('mau') : 'hd';

        function moView(v, ghiUrl) {
            view = v;
            $$('.tb-view').forEach(function (el) { el.classList.toggle('is-on', el.dataset.view === v); });
            $$('[data-nav]').forEach(function (a) { a.classList.toggle('active', a.dataset.nav === v); });
            // Đang ở màn con thì mục cha vẫn tô cam như bản cũ.
            $1('.tb-nav-muc[data-nav="tong-quan"]').classList.toggle('mo', v !== 'tong-quan');
            $1('#tbTitle').textContent = VIEW[v].tieuDe;
            $1('#tbSub').textContent = VIEW[v].phu;
            $1('#tbLuu').hidden = v !== 'mau-in';
            veLai();
            if (ghiUrl) {
                var u = new URLSearchParams(location.search);
                u.set('v', v); u.delete('tab'); u.delete('mau');
                if (v === 'may-in' && tab !== 'ds') u.set('tab', tab);
                if (v === 'mau-in' && mauMo !== 'hd') u.set('mau', mauMo);
                history.replaceState(null, '', '?' + u.toString());
            }
        }
        function veLai() {
            if (view === 'tong-quan') veTongQuan();
            if (view === 'may-in') moTab(tab);
            if (view === 'mau-in') moMau(mauMo);
            if (view === 'quet-the') veQuet();
            nutChinh();
        }
        function nutChinh() {
            var b = $1('#tbNutChinh'), nhan = '';
            if (view === 'may-in') nhan = { ds: 'Thêm máy in', phieu: 'Gán phiếu', nut: '' }[tab];
            if (view === 'quet-the' && PM && PM.can_edit) nhan = 'Thêm máy';
            b.hidden = !nhan || !sua(); b.querySelector('span').textContent = nhan;
        }
        $$('[data-nav]').forEach(function (a) { a.addEventListener('click', function (e) { e.preventDefault(); moView(a.dataset.nav, true); }); });
        $1('#tbNutChinh').addEventListener('click', function () {
            if (view === 'may-in' && tab === 'ds') moHopMay(null);
            if (view === 'may-in' && tab === 'phieu') moHopPhieu(null);
            if (view === 'quet-the') moHopQuet(null);
        });

        // ---------- Tổng quan ----------
        function veTongQuan() {
            var ds = D.printers || [], bat = ds.filter(function (m) { return m.is_active; }).length;
            var thieu = (D.slips || []).filter(function (p) { return !(D.routes || []).some(function (g) { return g.slip_type === p.ma && g.is_active && mayBat(g.printer_id); }); });
            var dong = function (cls, icon, txt) { return '<li class="' + cls + '"><i class="' + icon + '"></i><span>' + txt + '</span></li>'; };
            var OK = 'fa-solid fa-circle-check', CANH = 'fa-solid fa-triangle-exclamation', NHAT = 'fa-regular fa-circle';
            var mayIn = ds.length
                ? dong('ok', OK, '<b>' + ds.length + '</b> máy in · ' + bat + ' đang dùng') +
                  (thieu.length ? dong('canh', CANH, thieu.map(function (p) { return p.ten; }).join(', ') + ' chưa có máy in — in qua hộp in của trình duyệt')
                                : dong('ok', OK, 'Mọi loại phiếu đã có máy in'))
                : dong('nhat', NHAT, 'Chưa khai máy in nào') + dong('nhat', NHAT, 'Phiếu đang in qua hộp in của trình duyệt');
            var doi = (D.templates || []).filter(function (m) { return m.da_chinh; });
            var hd = mau('hd') || { settings: {}, options: {} };
            var mauIn = dong('ok', OK, '<b>' + (D.templates || []).length + '</b> mẫu phiếu') +
                (doi.length ? dong('nhat', 'fa-solid fa-pen', 'Đã chỉnh: ' + doi.map(function (m) { return esc(m.ngan); }).join(', '))
                            : dong('nhat', NHAT, 'Tất cả đang theo mặc định')) +
                dong('nhat', 'fa-solid fa-receipt', 'Hoá đơn: khổ ' + esc(hd.settings.kho) + 'mm · ' + so(hd.settings.ban) + ' bản' + (hd.options.qr ? ' · có QR thanh toán' : ''));
            var quet;
            if (!PM) quet = dong('nhat', NHAT, esc(LOI_PM || 'Không tải được máy quẹt thẻ.'));
            else {
                var tb = PM.devices || [], mpos = tb.filter(function (x) { return !x.is_physical; }).length, chua = tb.filter(function (x) { return !ganVoi(x.id); }).length;
                quet = tb.length
                    ? dong('ok', OK, '<b>' + tb.length + '</b> máy · ' + mpos + ' mPOS, ' + (tb.length - mpos) + ' độc lập') +
                      (chua ? dong('canh', CANH, chua + ' máy chưa gắn vào phương thức thanh toán — thu ngân chưa chọn được') : '')
                    : dong('nhat', NHAT, 'Chưa có máy quẹt thẻ') + dong('nhat', NHAT, 'Không cần nếu cửa hàng không nhận thẻ');
            }
            var the = function (v, icon, ten, moTa, noi) {
                return '<a class="tb-the" href="?v=' + v + '" data-di="' + v + '"><div class="tb-the-dau"><span class="tb-the-icon"><i class="' + icon + '"></i></span><div><p class="tb-the-ten">' + ten + '</p><p class="tb-the-mota">' + moTa + '</p></div></div>' +
                    '<div class="tb-the-so"><ul>' + noi + '</ul></div><div class="tb-the-mo">Mở ' + ten.toLowerCase() + ' <i class="fa-solid fa-arrow-right ms-1"></i></div></a>';
            };
            $1('#tbTongQuan').innerHTML =
                the('mau-in', 'fa-regular fa-file-lines', 'Thiết lập mẫu in', 'Chọn dòng nào hiện trên hoá đơn, tem, phiếu trả hàng, phiếu kết ca.', mauIn) +
                the('may-in', 'fa-solid fa-print', 'Thiết lập máy in', 'Mỗi loại phiếu in ra một máy riêng; nút nào ở quầy in phiếu gì.', mayIn) +
                the('quet-the', 'fa-regular fa-credit-card', 'Máy quẹt thẻ', 'Máy mPOS NextPay và máy POS ngân hàng của chi nhánh.', quet);
            $$('#tbTongQuan [data-di]').forEach(function (a) { a.addEventListener('click', function (e) { e.preventDefault(); moView(a.dataset.di, true); }); });
        }

        // ---------- Khuôn bảng + bản thẻ màn hẹp ----------
        function veBang(oId, cot, dong, rong) {
            if (!dong.length) { $1(oId).innerHTML = rong; return; }
            var bang = '<div class="table-responsive"><table class="tb-table"><thead><tr>' + cot.map(function (c) { return '<th class="' + (c.c || '') + '">' + c.t + '</th>'; }).join('') + '</tr></thead><tbody>' +
                dong.map(function (d) { return '<tr class="' + (d.cls || '') + '">' + d.o.map(function (o, i) { return '<td class="' + (cot[i].c || '') + '">' + o + '</td>'; }).join('') + '</tr>'; }).join('') + '</tbody></table></div>';
            var the = '<div class="tb-the-list">' + dong.map(function (d) {
                return '<div class="tb-the-m ' + (d.cls || '') + '"><div class="tb-the-m-dau"><span class="tb-the-m-ten">' + d.ten + '</span>' + (d.cong || '') + '</div>' +
                    d.the.map(function (x) { return '<div class="tb-the-m-dong"><span>' + x[0] + '</span><span>' + x[1] + '</span></div>'; }).join('') +
                    (d.hd ? '<div class="tb-the-m-duoi action">' + d.hd + '</div>' : '') + '</div>';
            }).join('') + '</div>';
            $1(oId).innerHTML = bang + the;
        }
        function trong(icon, tieuDe, moTa, nutNhan, nutId, coQuyen) {
            return '<div class="tb-trong"><span class="tb-trong-ic"><i class="' + icon + '"></i></span><b>' + tieuDe + '</b><p>' + moTa + '</p>' +
                (nutNhan && coQuyen ? '<a type="button" class="bt btn_green mt-1" id="' + nutId + '"><i class="fa-solid fa-plus me-1"></i>' + nutNhan + '</a>' : '') + '</div>';
        }
        var congTac = function (attr, id, bat, nhan, khoa) {
            return '<input type="checkbox" class="switch_customer" ' + attr + '="' + id + '"' + (bat ? ' checked' : '') + (khoa ? ' disabled' : '') + ' aria-label="' + esc(nhan) + '">';
        };
        var nutSuaXoa = function (loai, id, nhan) {
            return '<a class="edit_bt" type="button" data-sua-' + loai + '="' + id + '" title="Sửa" aria-label="Sửa ' + esc(nhan) + '"><i class="fa fa-edit"></i></a>' +
                '<a class="dele_bt" type="button" data-xoa-' + loai + '="' + id + '" title="Xoá" aria-label="Xoá ' + esc(nhan) + '"><i class="fa fa-times"></i></a>';
        };

        // ---------- Máy in ----------
        function moTab(t) {
            tab = t;
            $$('.tb-tab').forEach(function (b) { var on = b.dataset.tab === t; b.classList.toggle('is-on', on); b.setAttribute('aria-selected', on); });
            $$('.tb-pane').forEach(function (p) { p.classList.toggle('is-on', p.dataset.pane === t); });
            if (t === 'ds') veMay();
            if (t === 'phieu') vePhieu();
            if (t === 'nut') veNut();
            nutChinh();
        }
        $$('.tb-tab').forEach(function (b) { b.addEventListener('click', function () { tab = b.dataset.tab; moView('may-in', true); }); });

        function veMay() {
            var ds = D.printers || [];
            $1('#tbDemMay').textContent = ds.length;
            veBang('#tbBangMay',
                [{ t: 'Tên máy in' }, { t: 'Kết nối' }, { t: 'Khổ giấy', c: 'giua' }, { t: 'Đang in' }, { t: 'Đã in', c: 'phai tb-phu-cot' }, { t: 'Trạng thái', c: 'giua' }, { t: '', c: 'action' }],
                ds.map(function (m) {
                    var cac = (D.routes || []).filter(function (g) { return g.printer_id === m.id && g.is_active; }).map(function (g) { return phieu(g.slip_type).ten; })
                        .filter(function (v, i, a) { return a.indexOf(v) === i; });
                    var k = KET[m.conn_type] || { ten: m.conn_type, icon: 'fa-solid fa-print' };
                    var ket = '<span class="tb-ket"><i class="' + k.icon + '"></i>' + k.ten + (m.address ? ' · <span class="tb-mono">' + esc(m.address) + '</span>' : '') + '</span>';
                    var dangIn = cac.length ? cac.map(function (t) { return '<span class="tb-chip">' + esc(t) + '</span>'; }).join('') : '<span class="tb-nhat">Chưa gán phiếu</span>';
                    var cong = congTac('data-bat-may', m.id, m.is_active, 'Bật / tắt ' + m.name, !sua());
                    var hd = sua() ? nutSuaXoa('may', m.id, m.name) : '';
                    return {
                        cls: m.is_active ? '' : 'tat', ten: esc(m.name), cong: cong, hd: hd,
                        o: ['<span class="tb-ten-may">' + esc(m.name) + '</span>', ket, '<span title="' + m.dpi + ' dpi">' + (KHO[m.paper] || esc(m.paper)) + '</span>', dangIn, so(m.printed_count), cong, hd],
                        the: [['Kết nối', ket], ['Khổ giấy', (KHO[m.paper] || esc(m.paper)) + ' · ' + m.dpi + 'dpi'], ['Đang in', dangIn], ['Đã in', so(m.printed_count) + ' lần']]
                    };
                }),
                trong('fa-solid fa-print', 'Chưa có máy in', 'Phiếu đang in qua hộp in của trình duyệt. Khai máy in để dùng khi có ứng dụng in trên máy quầy.', 'Thêm máy in', 'tbTrongMay', sua()));
            var n = $1('#tbTrongMay'); if (n) n.onclick = function () { moHopMay(null); };
        }

        function vePhieu() {
            $1('#tbPhuPhieu').innerHTML = (D.slips || []).map(function (p) {
                var cac = (D.routes || []).filter(function (g) { return g.slip_type === p.ma && g.is_active && mayBat(g.printer_id); });
                return cac.length
                    ? '<span class="tb-phu-o ok"><i class="fa-solid fa-circle-check"></i>' + esc(p.ten) + ' <small>' + cac.map(function (g) { return esc(may(g.printer_id).name); }).filter(function (v, i, a) { return a.indexOf(v) === i; }).join(', ') + '</small></span>'
                    : '<span class="tb-phu-o thieu"><i class="fa-solid fa-triangle-exclamation"></i>' + esc(p.ten) + ' <small>chưa có máy · in qua trình duyệt</small></span>';
            }).join('');
            var thuTu = (D.slips || []).map(function (p) { return p.ma; });
            var ds = (D.routes || []).slice().sort(function (a, b) { return thuTu.indexOf(a.slip_type) - thuTu.indexOf(b.slip_type) || a.id - b.id; });
            veBang('#tbBangPhieu',
                [{ t: 'Loại phiếu' }, { t: 'Máy in' }, { t: 'Nhóm hàng' }, { t: 'Trạng thái', c: 'giua' }, { t: '', c: 'action' }],
                ds.map(function (g) {
                    var m = may(g.printer_id) || { name: '—', is_active: false };
                    var p = phieu(g.slip_type);
                    var nh = p.theo_nhom
                        ? (g.all_categories ? '<span class="tb-chip xanh">Tất cả nhóm</span>' : (g.category_ids || []).map(function (id) { return '<span class="tb-chip xam">' + esc(nhom(id)) + '</span>'; }).join(''))
                        : '<span class="tb-nhat">—</span>';
                    var tm = esc(m.name) + (m.is_active ? '' : ' <span class="tb-chip vang">máy đang tắt</span>');
                    var cong = congTac('data-bat-gan', g.id, g.is_active, 'Bật / tắt dòng ' + p.ten, !sua());
                    var hd = sua() ? nutSuaXoa('gan', g.id, p.ten) : '';
                    return {
                        cls: g.is_active ? '' : 'tat', ten: esc(p.ten), cong: cong, hd: hd,
                        o: ['<b>' + esc(p.ten) + '</b>', tm, nh, cong, hd],
                        the: [['Máy in', tm]].concat(p.theo_nhom ? [['Nhóm hàng', nh]] : [])
                    };
                }),
                trong('fa-solid fa-diagram-project', 'Chưa gán phiếu nào', (D.printers || []).length ? 'Gán từng loại phiếu cho một máy in.' : 'Thêm máy in ở tab Danh sách máy in trước, rồi gán phiếu ở đây.',
                    (D.printers || []).length ? 'Gán phiếu' : '', 'tbTrongPhieu', sua()));
            var n = $1('#tbTrongPhieu'); if (n) n.onclick = function () { moHopPhieu(null); };
        }

        function veNut() {
            veBang('#tbBangNut',
                [{ t: 'Nút bấm ở quầy' }, { t: 'In ra' }, { t: 'Trạng thái', c: 'giua' }, { t: '', c: 'action' }],
                (D.buttons || []).map(function (n) {
                    var inRa = n.slips.length ? n.slips.map(function (p) {
                        var t = phieu(p).ten;
                        return '<span class="tb-chip cam">' + esc(t) + (sua() ? '<button type="button" class="x" data-go-phieu="' + n.ma + '|' + p + '" title="Bỏ ' + esc(t) + '" aria-label="Bỏ ' + esc(t) + '">✕</button>' : '') + '</span>';
                    }).join('') : '<span class="tb-nhat">Không in gì</span>';
                    var ten = '<b>' + esc(n.ten) + '</b>' + (n.phim ? ' <span class="tb-chip xam">' + esc(n.phim) + '</span>' : '');
                    var cong = congTac('data-bat-nut', n.ma, n.is_active, 'Bật / tắt nút ' + n.ten, !sua());
                    var hd = sua() ? '<a class="edit_bt" type="button" data-sua-nut="' + n.ma + '" title="Sửa" aria-label="Sửa nút ' + esc(n.ten) + '"><i class="fa fa-edit"></i></a>' : '';
                    return { cls: n.is_active ? '' : 'tat', ten: ten, cong: cong, hd: hd, o: [ten, inRa, cong, hd], the: [['In ra', inRa]] };
                }), '');
        }

        // Một bộ nghe cho cả trang: bảng vẽ lại sau mỗi lượt nạp nên không gắn vào từng nút.
        document.addEventListener('change', function (e) {
            var t = e.target, id;
            if ((id = t.getAttribute('data-bat-may'))) ghi('PUT', URL.goc + '/printers/' + id + '/status', { is_active: t.checked });
            if ((id = t.getAttribute('data-bat-gan'))) ghi('PUT', URL.goc + '/routes/' + id + '/status', { is_active: t.checked });
            if ((id = t.getAttribute('data-bat-nut'))) {
                var n = (D.buttons || []).filter(function (x) { return x.ma === id; })[0];
                ghi('PUT', URL.goc + '/buttons/' + id, { slips: n.slips, is_active: t.checked });
            }
        });
        document.addEventListener('click', function (e) {
            var b = e.target.closest('a, button'); if (!b || !b.closest('.tb-than')) return;
            var id;
            if ((id = b.getAttribute('data-sua-may'))) moHopMay(+id);
            if ((id = b.getAttribute('data-xoa-may'))) xoaMay(+id);
            if ((id = b.getAttribute('data-sua-gan'))) moHopPhieu(+id);
            if ((id = b.getAttribute('data-xoa-gan'))) {
                // Chốt id ra biến riêng: các nhánh `id = …` bên dưới ghi đè nó thành null
                // trước khi người dùng kịp bấm Gỡ trong hộp xác nhận.
                var g = (D.routes || []).filter(function (x) { return x.id === +id; })[0], ganId = g.id;
                hoiXoa('Gỡ dòng gán ?', 'Gỡ <b>' + esc(phieu(g.slip_type).ten) + '</b> khỏi máy <b>' + esc((may(g.printer_id) || {}).name) + '</b>?', function () { return ghi('DELETE', URL.goc + '/routes/' + ganId); }, 'Gỡ');
            }
            if ((id = b.getAttribute('data-sua-nut'))) moHopNut(id);
            if ((id = b.getAttribute('data-go-phieu'))) {
                var p = id.split('|'), n = (D.buttons || []).filter(function (x) { return x.ma === p[0]; })[0];
                ghi('PUT', URL.goc + '/buttons/' + p[0], { slips: n.slips.filter(function (x) { return x !== p[1]; }), is_active: n.is_active });
            }
            if ((id = b.getAttribute('data-sua-quet'))) moHopQuet(+id);
            if ((id = b.getAttribute('data-xoa-quet'))) xoaQuet(+id);
        });

        // ---------- Hộp: máy in ----------
        var hopMay = new bootstrap.Modal('#mdMay'), dangSuaMay = null, fMay = $1('#fMay');
        function capNhatDc() {
            var l = $1('#fMay [name=loai]:checked').value;
            $1('#fMayDcO').hidden = l === 'usb' || l === 'urovo';
            $1('#fMayDcNhan').textContent = l === 'bt' ? 'Địa chỉ MAC' : 'Địa chỉ IP';
            $1('#fMayDcBb').hidden = l !== 'lan';
            $1('#fMayDc').placeholder = l === 'bt' ? 'VD: DC:0D:30:7A:11:4F' : 'VD: 192.168.1.50';
            $1('#fMayDc').inputMode = l === 'bt' ? 'text' : 'decimal';
            capNhatDo();
        }

        // ---------- Dò tìm máy in ----------
        // Ba đường, theo thứ tự: app Android của bản cũ (giao thức do_something
        // của ordertable v2: 0 = dò, loại "1" LAN / "0" Bluetooth / "2" USB, trả
        // {khoá: {address, name}}); Web Bluetooth / WebUSB của Chrome (hộp chọn
        // thiết bị, chỉ đọc được TÊN); còn lại thì nói thật là không dò được.
        var coApp = function () { return !!(window.Android && typeof window.Android.do_something === 'function'); };
        var LOAI_APP = { lan: '1', bt: '0', usb: '2' };
        function capNhatDo() {
            var l = $1('#fMay [name=loai]:checked').value, goi = '';
            $1('#fMayDoO').hidden = l === 'urovo';
            $1('#fMayDoKq').hidden = true; $1('#fMayDoKq').innerHTML = '';
            if (coApp()) goi = 'Dò qua ứng dụng in trên máy quầy (mạng LAN, Bluetooth, USB).';
            else if (l === 'bt') goi = navigator.bluetooth ? 'Trình duyệt mở hộp chọn máy Bluetooth ở gần.' : 'Trình duyệt này không dò được Bluetooth — dùng Chrome hoặc Edge.';
            else if (l === 'usb') goi = 'Máy in USB đã cài driver thì trình duyệt không thấy — bấm để xem cách dùng.';
            else goi = 'Trình duyệt không quét được mạng nội bộ — bấm để xem cách lấy IP.';
            $1('#fMayDoGoi').textContent = goi;
        }
        // Máy USB cắm vào máy tính: Windows / macOS giữ máy cho driver in, nên hộp
        // chọn WebUSB của Chrome không thấy máy, hoặc thấy mà mở ra "Access denied".
        // Thay driver bằng WinUSB (Zadig) thì Windows hết in được — mà phiếu của mình
        // đang in qua hộp in của trình duyệt bằng chính driver ấy. Nên không dò.
        var HUONG_DAN_USB = '<p><b>Máy in USB cắm vào máy tính không cần dò.</b> Windows nhận máy qua driver in, trình duyệt không được phép đọc máy đó. Cách dùng:</p><ol>' +
            '<li>Cài driver của máy in, in thử trang test trong <b>Cài đặt → Bluetooth &amp; thiết bị → Máy in &amp; máy quét</b> (Windows 11; Windows 10: Cài đặt → Thiết bị → Máy in &amp; máy quét).</li>' +
            '<li>Đặt máy này làm <b>máy in mặc định</b> — hộp in của phiếu sẽ chọn sẵn nó.</li>' +
            '<li>Ở đây chỉ cần gõ <b>tên máy</b> (VD: Xprinter XP-80 quầy 1), chọn khổ giấy rồi bấm Lưu.</li></ol>' +
            '<p class="mt-1">Muốn bấm là in ngay, không hiện hộp in: mở Chrome bằng lối tắt có thêm <code>--kiosk-printing</code>.</p>' +
            (navigator.usb ? '<div class="tb-do-chan"><button type="button" class="btn btn-sm btn-outline-secondary" data-do-usb>Vẫn thử hộp chọn USB của Chrome</button></div>' : '');
        function hienKq(html) { var k = $1('#fMayDoKq'); k.innerHTML = html; k.hidden = false; }
        // Danh sách máy tìm thấy: máy đã khai ở chi nhánh thì khoá kèm tên, khỏi khai hai lần.
        function veKetQua(ds, l) {
            if (!ds.length) {
                hienKq('<p>' + (l === 'bt' ? 'Không tìm thấy thiết bị Bluetooth nào.' : 'Không tìm thấy máy in nào.') + ' Bật máy in, kiểm tra cùng mạng / đã ghép đôi rồi quét lại.</p>' +
                    '<div class="tb-do-chan"><button type="button" class="btn btn-sm btn-outline-secondary" data-do-lai>Quét lại</button></div>');
                return;
            }
            hienKq('<div class="tb-do-ds">' + ds.map(function (x, i) {
                var trung = (D.printers || []).filter(function (m) { return m.id !== dangSuaMay && m.conn_type === l && x.address && String(m.address).toLowerCase() === String(x.address).toLowerCase(); })[0];
                return '<button type="button" data-do-chon="' + i + '"' + (trung ? ' disabled title="' + esc('Đã khai: ' + trung.name) + '"' : '') + '><b>' + esc(x.address || x.name) + '</b><small>' + esc(trung ? 'Đã khai: ' + trung.name : (x.address ? x.name : 'Không đọc được địa chỉ')) + '</small></button>';
            }).join('') + '</div><div class="tb-do-chan"><button type="button" class="btn btn-sm btn-outline-secondary" data-do-lai>Quét lại</button></div>');
            $1('#fMayDoKq').dataset.ds = JSON.stringify(ds);
        }
        function chonKq(x) {
            if (x.address) { $1('#fMayDc').value = x.address; }
            // Tên chỉ điền khi ô còn trống: người dùng đã gõ tên thì giữ tên của họ.
            if (x.name && !$1('#fMayTen').value.trim()) $1('#fMayTen').value = String(x.name).slice(0, 100);
            xoaLoi(fMay);
        }
        function nutDo(dang) {
            var b = $1('#fMayDo'); b.disabled = dang;
            b.querySelector('span').textContent = dang ? 'Đang tìm máy in…' : 'Dò tìm máy in';
        }
        var thuUsb = false;
        function doTim() {
            var l = $1('#fMay [name=loai]:checked').value;
            if (coApp()) {
                nutDo(true);
                // do_something chạy đồng bộ và có thể mất vài giây: nhường một nhịp để nút kịp đổi chữ.
                setTimeout(function () {
                    try {
                        var raw = window.Android.do_something(0, LOAI_APP[l], '');
                        var res = JSON.parse(raw || '{}') || {};
                        veKetQua(Object.keys(res).map(function (k) { return { address: String(res[k].address || ''), name: String(res[k].name || '') }; }), l);
                    } catch (e) {
                        hienKq('<p>Lỗi khi dò tìm máy in: ' + esc(e.message) + '</p>');
                    }
                    nutDo(false);
                }, 30);
                return;
            }
            if (l === 'lan') {
                hienKq('<p><b>Trình duyệt không được phép quét mạng nội bộ</b>, nên máy LAN phải gõ IP tay. Cách lấy IP:</p><ol>' +
                    '<li>Tắt máy in, giữ nút <b>FEED</b> rồi bật lại — máy in ra trang tự kiểm, có dòng <b>IP Address</b>.</li>' +
                    '<li>Hoặc xem trong trang quản trị Wi-Fi của cửa hàng (danh sách thiết bị đang kết nối).</li></ol>' +
                    '<p class="mt-1">Mở trang này trong ứng dụng in trên máy quầy Android (cầu nối như bản cũ) thì dò tự động được.</p>');
                return;
            }
            if (l === 'usb' && !thuUsb) { hienKq(HUONG_DAN_USB); return; }
            thuUsb = false;
            var hoi = l === 'bt' && navigator.bluetooth ? navigator.bluetooth.requestDevice({ acceptAllDevices: true })
                : l === 'usb' && navigator.usb ? navigator.usb.requestDevice({ filters: [] }) : null;
            if (!hoi) { capNhatDo(); hienKq('<p>' + esc($1('#fMayDoGoi').textContent) + '</p>'); return; }
            nutDo(true);
            hoi.then(function (tb) {
                var ten = l === 'bt' ? (tb.name || '') : [tb.manufacturerName, tb.productName].filter(Boolean).join(' ');
                chonKq({ address: '', name: ten || (l === 'bt' ? 'Máy in Bluetooth' : 'Máy in USB') });
                hienKq('<p>Đã chọn <b>' + esc(ten || 'thiết bị không tên') + '</b>.' + (l === 'bt' ? ' Trình duyệt không cho đọc địa chỉ MAC — cần thì gõ tay (in trên trang tự kiểm của máy).' : '') + '</p>');
            }, function (e) {
                // Người dùng đóng hộp chọn của trình duyệt: không phải lỗi, không báo gì.
                // Hộp chọn USB trống hoặc bị đóng: nhắc lại hướng dẫn, đừng im lặng như
                // thể đã dò mà không có gì. Bluetooth đóng hộp chọn thì thôi.
                if (l === 'usb') hienKq(HUONG_DAN_USB);
                else if (e && e.name !== 'NotFoundError') hienKq('<p>Không mở được hộp chọn thiết bị: ' + esc(e.message || e.name) + '</p>');
            }).then(function () { nutDo(false); });
        }
        $1('#fMayDo').addEventListener('click', doTim);
        $1('#fMayDoKq').addEventListener('click', function (e) {
            var b = e.target.closest('button'); if (!b) return;
            if (b.hasAttribute('data-do-lai')) { doTim(); return; }
            if (b.hasAttribute('data-do-usb')) { thuUsb = true; doTim(); return; }
            if (b.hasAttribute('data-do-chon')) {
                var ds = JSON.parse(this.dataset.ds || '[]');
                chonKq(ds[+b.getAttribute('data-do-chon')] || {});
                $$('[data-do-chon]', this).forEach(function (x) { x.classList.toggle('chon', x === b); });
            }
        });
        $$('#fMay [name=loai]').forEach(function (r) { r.addEventListener('change', function () { $1('#fMayDc').value = ''; xoaLoi(fMay); capNhatDc(); }); });
        $1('#fMayDc').addEventListener('input', function () {
            // IP: chỉ số và dấu chấm, như bản cũ — gõ nhầm chữ là biết ngay.
            if ($1('#fMay [name=loai]:checked').value === 'lan') this.value = this.value.replace(/[^\d.]/g, '');
        });
        function moHopMay(id) {
            dangSuaMay = id;
            var m = id ? may(id) : { name: '', conn_type: 'lan', address: '', paper: '80', dpi: 203, is_active: true };
            $1('#mdMayTieuDe').textContent = id ? 'Sửa máy in' : 'Thêm máy in';
            ($1('#fMay [name=loai][value="' + m.conn_type + '"]') || {}).checked = true;
            ($1('#fMay [name=kho][value="' + m.paper + '"]') || {}).checked = true;
            ($1('#fMay [name=dpi][value="' + m.dpi + '"]') || {}).checked = true;
            capNhatDc(); xoaLoi(fMay);
            $1('#fMayTen').value = m.name; $1('#fMayDc').value = m.address; $1('#fMayBat').checked = m.is_active;
            hopMay.show();
        }
        $1('#mdMay').addEventListener('shown.bs.modal', function () { $1('#fMayTen').focus(); });
        fMay.addEventListener('submit', function (e) {
            e.preventDefault(); xoaLoi(fMay);
            var l = $1('#fMay [name=loai]:checked').value, ten = $1('#fMayTen').value.trim(), dc = $1('#fMayDc').value.trim(), loi = {};
            if (!ten) loi.name = 'Nhập tên máy in.';
            if (l === 'lan' && !dc) loi.address = 'Nhập địa chỉ IP.';
            if (Object.keys(loi).length) { toLoi(fMay, loi); return; }
            var body = { name: ten, conn_type: l, address: dc, paper: $1('#fMay [name=kho]:checked').value, dpi: +$1('#fMay [name=dpi]:checked').value, is_active: $1('#fMayBat').checked };
            ghi(dangSuaMay ? 'PUT' : 'POST', URL.goc + '/printers' + (dangSuaMay ? '/' + dangSuaMay : ''), body, fMay).then(function (ok) { if (ok) hopMay.hide(); });
        });
        function xoaMay(id) {
            var m = may(id);
            var dang = (D.routes || []).filter(function (g) { return g.printer_id === id; }).map(function (g) { return phieu(g.slip_type).ten; }).filter(function (v, i, a) { return a.indexOf(v) === i; });
            if (dang.length) {
                hoiXoa('Chưa xoá được', '<b>' + esc(m.name) + '</b> đang in <b>' + esc(dang.join(', ')) + '</b>. Gỡ ở tab <b>Phiếu in ra máy nào</b> trước, rồi xoá máy.', null);
                return;
            }
            hoiXoa('Xoá máy in ?', 'Xoá <b>' + esc(m.name) + '</b> khỏi chi nhánh? Số lần đã in cũng mất theo.', function () { return ghi('DELETE', URL.goc + '/printers/' + id); });
        }

        // ---------- Hộp: gán phiếu ----------
        var hopPhieu = new bootstrap.Modal('#mdPhieu'), dangSuaGan = null, fPhieu = $1('#fPhieu');
        function veNhomChon(tatCa, chon) {
            $1('#fPhieuNhom').innerHTML = '<label class="tb-tat-ca"><input type="checkbox" value="all"' + (tatCa ? ' checked' : '') + '><span>Tất cả</span></label>' +
                (D.groups || []).map(function (n) { return '<label><input type="checkbox" value="' + n.id + '"' + ((chon || []).indexOf(n.id) >= 0 ? ' checked' : '') + '><span>' + esc(n.name) + '</span></label>'; }).join('') +
                ((D.groups || []).length ? '' : '<span class="tb-nhat">Chưa có nhóm hàng nào — chọn Tất cả.</span>');
        }
        $1('#fPhieuNhom').addEventListener('change', function (e) {
            // “Tất cả” và từng nhóm loại trừ nhau, như bản cũ.
            if (e.target.value === 'all' && e.target.checked) $$('#fPhieuNhom input:not([value=all])').forEach(function (i) { i.checked = false; });
            if (e.target.value !== 'all' && e.target.checked) $1('#fPhieuNhom input[value=all]').checked = false;
        });
        function capNhatPhieu() {
            $1('#fPhieuNhomO').hidden = !phieu($1('#fPhieuLoai').value).theo_nhom;
            var m = may(+$1('#fPhieuMay').value);
            $1('#fPhieuMayGoi').textContent = m ? ((KET[m.conn_type] || {}).ten + (m.address ? ' · ' + m.address : '') + ' · khổ ' + (KHO[m.paper] || m.paper) + (m.is_active ? '' : ' · máy đang tắt')) : '';
        }
        $1('#fPhieuLoai').addEventListener('change', capNhatPhieu);
        $1('#fPhieuMay').addEventListener('change', capNhatPhieu);
        function moHopPhieu(id) {
            dangSuaGan = id;
            var g = id ? (D.routes || []).filter(function (x) { return x.id === id; })[0]
                : { slip_type: ((D.slips || []).filter(function (p) { return !(D.routes || []).some(function (r) { return r.slip_type === p.ma; }); })[0] || D.slips[0]).ma,
                    printer_id: ((D.printers || [])[0] || {}).id, all_categories: true, category_ids: [], is_active: true };
            $1('#mdPhieuTieuDe').textContent = id ? 'Sửa dòng gán' : 'Gán phiếu cho máy in';
            $1('#fPhieuLoai').innerHTML = (D.slips || []).map(function (p) { return '<option value="' + p.ma + '">' + esc(p.ten) + '</option>'; }).join('');
            $1('#fPhieuMay').innerHTML = '<option value="">Chọn máy in</option>' + (D.printers || []).map(function (m) { return '<option value="' + m.id + '">' + esc(m.name) + (m.is_active ? '' : ' (đang tắt)') + '</option>'; }).join('');
            $1('#fPhieuLoai').value = g.slip_type; $1('#fPhieuMay').value = g.printer_id || ''; $1('#fPhieuBat').checked = g.is_active;
            veNhomChon(g.all_categories, g.category_ids); xoaLoi(fPhieu); capNhatPhieu();
            hopPhieu.show();
        }
        fPhieu.addEventListener('submit', function (e) {
            e.preventDefault(); xoaLoi(fPhieu);
            var ph = $1('#fPhieuLoai').value, mId = +$1('#fPhieuMay').value, chon = $$('#fPhieuNhom input:checked').map(function (i) { return i.value; });
            var tatCa = chon.indexOf('all') >= 0, nh = chon.filter(function (v) { return v !== 'all'; }).map(Number);
            if (!mId) { toLoi(fPhieu, { printer_id: 'Chọn máy in.' }); return; }
            if (phieu(ph).theo_nhom && !tatCa && !nh.length) { toLoi(fPhieu, { category_ids: 'Chọn ít nhất một nhóm hàng, hoặc Tất cả.' }); return; }
            var body = { slip_type: ph, printer_id: mId, all_categories: tatCa, category_ids: nh, is_active: $1('#fPhieuBat').checked };
            ghi(dangSuaGan ? 'PUT' : 'POST', URL.goc + '/routes' + (dangSuaGan ? '/' + dangSuaGan : ''), body, fPhieu).then(function (ok) { if (ok) hopPhieu.hide(); });
        });

        // ---------- Hộp: nút in ----------
        var hopNut = new bootstrap.Modal('#mdNut'), dangSuaNut = null, fNut = $1('#fNut');
        function moHopNut(ma) {
            dangSuaNut = ma;
            var n = (D.buttons || []).filter(function (x) { return x.ma === ma; })[0];
            $1('#fNutTen').textContent = n.ten + (n.phim ? ' (' + n.phim + ')' : '');
            $1('#fNutPhieu').innerHTML = (D.slips || []).map(function (p) {
                var duoc = n.cho_phep.indexOf(p.ma) >= 0;
                return '<label title="' + (duoc ? '' : esc('Nút ' + n.ten + ' không in ' + p.ten)) + '"><input type="checkbox" value="' + p.ma + '"' + (n.slips.indexOf(p.ma) >= 0 ? ' checked' : '') + (duoc ? '' : ' disabled') + '><span>' + esc(p.ten) + '</span></label>';
            }).join('');
            $1('#fNutGoi').textContent = n.cho_phep.indexOf('hddt') >= 0
                ? 'Hoá đơn điện tử chỉ in được ở nút Thanh toán. Phiếu kết ca / trả hàng in từ màn của chúng.'
                : 'Nút này chỉ in hoá đơn (có chữ TẠM TÍNH, chưa thu tiền).';
            $1('#fNutBat').checked = n.is_active; xoaLoi(fNut);
            hopNut.show();
        }
        fNut.addEventListener('submit', function (e) {
            e.preventDefault(); xoaLoi(fNut);
            var body = { slips: $$('#fNutPhieu input:checked').map(function (i) { return i.value; }), is_active: $1('#fNutBat').checked };
            ghi('PUT', URL.goc + '/buttons/' + dangSuaNut, body, fNut).then(function (ok) { if (ok) hopNut.hide(); });
        });

        // ---------- Máy quẹt thẻ (payment_devices của Phương thức thanh toán) ----------
        var TEN_CT = { mpos_device: 'Quẹt thẻ · mPOS NextPay', physical_device: 'Quẹt thẻ · máy độc lập' };
        function ganVoi(id) {
            var out = '';
            ((PM && PM.methods) || []).forEach(function (m) {
                (m.details || []).forEach(function (d) {
                    if (d.device_id === id) out = (TEN_CT[d.detail_type] || 'Quẹt thẻ') + (d.data && d.data.bank_name ? ' · ' + d.data.bank_name : '');
                });
            });
            return out;
        }
        function veQuet() {
            if (!PM) { $1('#tbBangQuet').innerHTML = trong('fa-regular fa-credit-card', 'Không mở được máy quẹt thẻ', esc(LOI_PM || ''), '', '', false); return; }
            var coSua = !!PM.can_edit;
            veBang('#tbBangQuet',
                [{ t: 'POS ID' }, { t: 'Tên máy' }, { t: 'Loại', c: 'giua' }, { t: 'Đang gắn với' }, { t: 'Mô tả', c: 'mota' }, { t: '', c: 'action' }],
                (PM.devices || []).map(function (x) {
                    var loai = x.is_physical ? '<span class="tb-chip xam">Độc lập</span>' : '<span class="tb-chip">mPOS</span>';
                    var gv = ganVoi(x.id);
                    var g = gv ? '<a href="' + URL.pm + '">' + esc(gv) + '</a>' : '<span class="tb-chip vang">Chưa gắn</span>';
                    var mt = x.description ? esc(x.description) : '<span class="tb-nhat">—</span>';
                    var hd = coSua ? nutSuaXoa('quet', x.id, x.name) : '';
                    return {
                        ten: esc(x.name), hd: hd,
                        o: ['<span class="tb-mono">' + esc(x.code) + '</span>', '<span class="tb-ten-may">' + esc(x.name) + '</span>', loai, g, mt, hd],
                        the: [['POS ID', '<span class="tb-mono">' + esc(x.code) + '</span>'], ['Loại', loai], ['Đang gắn với', g]].concat(x.description ? [['Mô tả', mt]] : [])
                    };
                }),
                trong('fa-regular fa-credit-card', 'Chưa có máy quẹt thẻ', 'Thêm máy rồi chọn nó ở Phương thức thanh toán → Quẹt thẻ để thu ngân nhận thẻ.', 'Thêm máy', 'tbTrongQuet', coSua));
            var n = $1('#tbTrongQuet'); if (n) n.onclick = function () { moHopQuet(null); };
        }
        var hopQuet = new bootstrap.Modal('#mdQuet'), dangSuaQuet = null, fQuet = $1('#fQuet');
        function moHopQuet(id) {
            dangSuaQuet = id;
            var x = id ? (PM.devices || []).filter(function (v) { return v.id === id; })[0] : { code: '', name: '', description: '', is_physical: false };
            $1('#mdQuetTieuDe').textContent = id ? 'Sửa máy quẹt thẻ' : 'Thêm máy quẹt thẻ';
            $$('#fQuet [name=qloai]').forEach(function (r) { r.checked = r.value === (x.is_physical ? '1' : '0'); r.disabled = !!id; });
            $1('#fQuetLoaiGoi').hidden = !id;
            $1('#fQuetMa').value = x.code; $1('#fQuetTen').value = x.name; $1('#fQuetMoTa').value = x.description || '';
            xoaLoi(fQuet);
            hopQuet.show();
        }
        $1('#mdQuet').addEventListener('shown.bs.modal', function () { (dangSuaQuet ? $1('#fQuetTen') : $1('#fQuetMa')).focus(); });
        fQuet.addEventListener('submit', function (e) {
            e.preventDefault(); xoaLoi(fQuet);
            var body = { code: $1('#fQuetMa').value.trim(), name: $1('#fQuetTen').value.trim(), description: $1('#fQuetMoTa').value.trim() }, loi = {};
            if (!body.code) loi.code = 'Nhập POS ID.';
            if (!body.name) loi.name = 'Nhập tên máy.';
            if (Object.keys(loi).length) { toLoi(fQuet, loi); return; }
            if (!dangSuaQuet) body.is_physical = $1('#fQuet [name=qloai]:checked').value === '1';
            ghi(dangSuaQuet ? 'PUT' : 'POST', dangSuaQuet ? URL.mayGoc + '/' + dangSuaQuet : URL.may, body, fQuet).then(function (ok) { if (ok) hopQuet.hide(); });
        });
        function xoaQuet(id) {
            var x = (PM.devices || []).filter(function (v) { return v.id === id; })[0], gv = ganVoi(id);
            if (gv) { hoiXoa('Chưa xoá được', '<b>' + esc(x.name) + '</b> đang gắn với <b>' + esc(gv) + '</b>. Gỡ máy ở <a href="' + URL.pm + '">Phương thức thanh toán</a> trước.', null); return; }
            hoiXoa('Xoá máy quẹt thẻ ?', 'Xoá <b>' + esc(x.name) + '</b> (<span class="tb-mono">' + esc(x.code) + '</span>)?', function () { return ghi('DELETE', URL.mayGoc + '/' + id); });
        }

        // ---------- Hộp xoá dùng chung ----------
        var hopXoa = new bootstrap.Modal('#mdXoa'), lamXoa = null;
        function hoiXoa(tieuDe, noiDung, lam, nhanNut) {
            lamXoa = lam;
            $1('#mdXoaTieuDe').textContent = tieuDe; $1('#mdXoaNoiDung').innerHTML = noiDung;
            $1('#mdXoaNut').hidden = !lam; $1('#mdXoaNut').textContent = nhanNut || 'Xoá';
            $1('#mdXoaDong').textContent = lam ? 'Đóng' : 'Đã hiểu';
            hopXoa.show();
        }
        $1('#mdXoaNut').addEventListener('click', function () {
            var b = this; b.disabled = true;
            Promise.resolve(lamXoa && lamXoa()).then(function () { b.disabled = false; hopXoa.hide(); });
        });

        // ---------- Mẫu in ----------
        function moMau(ma) {
            mauMo = mau(ma) ? ma : 'hd';
            var m = mau(mauMo), st = m.settings, khoa = sua() ? '' : ' disabled';
            $1('#tbMauTabs').innerHTML = (D.templates || []).map(function (t) {
                return '<button type="button" class="tb-mau-tab' + (t.ma === mauMo ? ' is-on' : '') + '" data-mau="' + t.ma + '" role="tab" aria-selected="' + (t.ma === mauMo) + '">' + esc(t.ten) + '</button>';
            }).join('');
            var doan = function (ten, ds, cur) {
                return '<div class="tb-doan">' + ds.map(function (v) { return '<label><input type="radio" name="st-' + ten + '" value="' + v[0] + '"' + (String(cur) === String(v[0]) ? ' checked' : '') + khoa + '><span>' + v[1] + '</span></label>'; }).join('') + '</div>';
            };
            var c = '';
            if (m.cai_dat.kho) c += '<div class="tb-truong"><span>Khổ giấy</span>' + doan('kho', [['58', '58mm'], ['80', '80mm']], st.kho) + '</div>';
            if (m.cai_dat.kho_tem) c += '<label class="tb-truong"><span>Khổ tem (rộng × cao)</span><select class="tsc-input" data-st="kho_tem"' + khoa + '>' +
                (D.label_sizes || []).map(function (v) { return '<option value="' + v + '"' + (v === st.kho_tem ? ' selected' : '') + '>' + v.replace('x', ' × ') + ' mm</option>'; }).join('') + '</select></label>';
            if (m.cai_dat.cot) c += '<div class="tb-truong"><span>Số tem một hàng</span>' + doan('cot', [[1, '1'], [2, '2'], [3, '3']], st.cot) + '</div>';
            if (m.cai_dat.co) c += '<label class="tb-truong"><span>Cỡ chữ</span><input type="number" class="tsc-input" data-st="co" min="5" max="12" value="' + st.co + '" style="width:72px"' + khoa + '></label>';
            // Phiếu quầy in đúng số bản của lệnh bấm (hộp In lại) — không có ô số bản ở mẫu.
            if (m.cai_dat.ban && !XEM_THAT[m.ma]) c += '<label class="tb-truong"><span>Số bản in</span><input type="number" class="tsc-input" data-st="ban" min="1" max="99" value="' + st.ban + '" style="width:72px"' + khoa + '></label>';
            $1('#tbMauChinh').innerHTML = c;
            $1('#tbMauNhom').innerHTML = m.nhom.map(function (g, gi) {
                return '<div class="tb-nhom"><div class="tb-nhom-dau"><span>' + esc(g.ten) + '</span>' + (sua() ? '<button type="button" data-ca-nhom="' + gi + '">Bật cả nhóm</button>' : '') + '</div><div class="tb-o-luoi">' +
                    g.dong.map(function (d) {
                        var an = d.con && !m.options[d.con];
                        var h = '<label class="tb-o' + (d.con ? ' con' : '') + (an ? ' an' : '') + '" data-con-cua="' + (d.con || '') + '"><input type="checkbox" data-dong="' + d.ma + '"' + (m.options[d.ma] ? ' checked' : '') + khoa + '><span>' + esc(d.ten) + '</span></label>';
                        if (d.o) h += '<div class="tb-o-them" data-them-cua="' + d.ma + '"' + (m.options[d.ma] ? '' : ' hidden') + '><input class="tsc-input" data-st="' + d.o + '" maxlength="' + ({ wifi_txt: 80, chinhsach_txt: 200 }[d.o] || m.footer_toi_da || 120) + '" value="' + esc(st[d.o]) + '" aria-label="' + esc(d.ten) + '"' + khoa + '>' + (d.goi ? '<small>' + esc(d.goi) + '</small>' : '') + '</div>';
                        return h;
                    }).join('') + '</div></div>';
            }).join('');
            $1('#tbMacDinh').hidden = !sua() || !m.da_chinh;
            veXem();
        }
        $1('#tbMauTabs').addEventListener('click', function (e) { var b = e.target.closest('[data-mau]'); if (b) { mauMo = b.dataset.mau; moView('mau-in', true); } });

        // Tự lưu: gom các lượt đổi liền nhau thành một lượt gửi.
        var henLuu = null, dongMoi = null;
        function luuMau() {
            var m = mau(mauMo), l = $1('#tbLuu');
            l.className = 'tb-luu dang'; l.textContent = 'Đang lưu…';
            clearTimeout(henLuu);
            henLuu = setTimeout(function () {
                goi('PUT', URL.goc + '/templates/' + m.ma, { options: m.options, settings: m.settings }).then(function (r) {
                    if (r.data) { Object.assign(m, r.data); }
                    l.className = 'tb-luu'; l.innerHTML = '<i class="fa-solid fa-circle-check"></i>Đã lưu lúc ' + new Date().toLocaleTimeString('vi-VN');
                    $1('#tbMacDinh').hidden = !m.da_chinh;
                }, function (x) {
                    var j = x.responseJSON || {};
                    l.className = 'tb-luu dang'; l.textContent = 'Chưa lưu được';
                    bao(false, j.errors ? Object.values(j.errors)[0] : (j.message || 'Không lưu được mẫu in.'));
                    napLai();
                });
            }, 450);
            veXem();
        }
        $1('#tbMauNhom').addEventListener('change', function (e) {
            var m = mau(mauMo), t = e.target;
            if (t.dataset.dong) {
                m.options[t.dataset.dong] = t.checked;
                $$('[data-con-cua="' + t.dataset.dong + '"]').forEach(function (c) { c.classList.toggle('an', !t.checked); });
                var them = $1('[data-them-cua="' + t.dataset.dong + '"]'); if (them) them.hidden = !t.checked;
                dongMoi = t.dataset.dong; luuMau();
            }
        });
        $1('#tbMauNhom').addEventListener('input', function (e) {
            if (e.target.dataset.st) { mau(mauMo).settings[e.target.dataset.st] = e.target.value; dongMoi = 'footer'; luuMau(); }
        });
        $1('#tbMauNhom').addEventListener('click', function (e) {
            var b = e.target.closest('[data-ca-nhom]'); if (!b) return;
            var m = mau(mauMo);
            m.nhom[+b.dataset.caNhom].dong.forEach(function (d) { m.options[d.ma] = true; });
            moMau(mauMo); luuMau();
        });
        $1('#tbMauChinh').addEventListener('change', function (e) {
            var m = mau(mauMo), t = e.target;
            if (t.name && t.name.indexOf('st-') === 0) { var k = t.name.slice(3); m.settings[k] = k === 'cot' ? +t.value : t.value; luuMau(); return; }
            if (!t.dataset.st) return;
            var v = +t.value;
            if (t.dataset.st === 'ban' && (!v || v < 1 || v > 99)) { t.value = m.settings.ban; bao(false, 'Số bản in từ 1 đến 99.'); return; }
            if (t.dataset.st === 'co' && (!v || v < 5 || v > 12)) { t.value = m.settings.co; bao(false, 'Cỡ chữ tem từ 5 đến 12.'); return; }
            m.settings[t.dataset.st] = t.dataset.st === 'kho_tem' ? t.value : v; luuMau();
        });
        $1('#tbMacDinh').addEventListener('click', function () {
            var m = mau(mauMo);
            hoiXoa('Về mặc định ?', 'Đưa <b>' + esc(m.ten) + '</b> về các dòng mặc định. Chỉnh sửa của chi nhánh này mất, phiếu đã in không đổi.',
                function () { return ghi('DELETE', URL.goc + '/templates/' + m.ma); }, 'Về mặc định');
        });

        // --- Vẽ phiếu xem trước. w(khoá, html) bọc dòng để tô sáng dòng vừa bật. ---
        function svgQR() {
            var s = '<svg class="qr" viewBox="0 0 25 25" shape-rendering="crispEdges" aria-hidden="true"><rect width="25" height="25" fill="#fff"/>', seed = 7;
            var goc = function (x, y) { return (x < 8 && y < 8) || (x > 16 && y < 8) || (x < 8 && y > 16); };
            for (var y = 0; y < 25; y++) for (var x = 0; x < 25; x++) {
                seed = (seed * 9301 + 49297) % 233280;
                var on = goc(x, y) ? ((x % 24 === 0 || y % 24 === 0 || x === 6 || y === 6 || x === 18 || y === 18 || (x >= 2 && x <= 4 && y >= 2 && y <= 4) || (x >= 20 && x <= 22 && y >= 2 && y <= 4) || (x >= 2 && x <= 4 && y >= 20 && y <= 22)) && !(x === 7 || y === 7 || x === 17 || y === 17)) : seed / 233280 > .52;
                if (on) s += '<rect x="' + x + '" y="' + y + '" width="1" height="1"/>';
            }
            return s + '</svg>';
        }
        var LOGO = '<div class="g" style="font-weight:700;letter-spacing:1px">[ LOGO ]</div>';
        var TEN = esc(CH.ten || 'Cửa hàng'), DC = esc(CH.diachi || '125 Trần Duy Hưng, Cầu Giấy, Hà Nội'), SDT = esc(CH.sdt || '0243 777 8899');
        var HANG = [
            { ten: 'Sạc nhanh 20W USB-C', bt: 'Trắng', dvt: 'Cái', sl: 2, gia: 290000, bot: 58000, botPt: 10 },
            { ten: 'Tai nghe Bluetooth Air Pro', bt: '', dvt: 'Cái', sl: 1, gia: 1290000, bot: 0 },
            { ten: 'Cáp USB-C 1m', bt: '', dvt: 'Sợi', sl: 1, gia: 0, bot: 0, tang: true },
            { ten: 'Ốp lưng iPhone 15', bt: 'Trong suốt', dvt: 'Cái', sl: 1, gia: 150000, bot: 0, ghichu: 'Gói quà' }
        ];
        // Mẫu có trang in thật: xem trước nạp CHÍNH trang đó (số liệu mẫu, lựa chọn đang
        // sửa) vào khung — giống hệt tờ in ra. Tem / kết ca chưa có trang in nên vẽ bằng JS.
        var XEM_THAT = { hd: 1, a5: 1, a4: 1, tra: 1 }, henXem = null, luotXem = 0;
        function veXem() {
            var m = mau(mauMo);
            $1('#tbInThu').hidden = !XEM_THAT[m.ma];
            if (!XEM_THAT[m.ma]) { veXemJs(); return; }
            $1('#tbXemKho').textContent = m.ma === 'a5' ? 'A5 · 148 × 210mm' : m.ma === 'a4' ? 'A4 · 210 × 297mm' : 'Khổ ' + (m.settings.kho || '80') + 'mm · ' + (m.settings.ban || 1) + ' bản';
            clearTimeout(henXem);
            henXem = setTimeout(function () {
                var luot = ++luotXem;
                var url = URL.goc + '/templates/' + m.ma + '/preview';
                if (window.V2 && V2.themChiNhanh) url = V2.themChiNhanh(url);
                $.ajax({ url: url, method: 'POST', data: JSON.stringify({ options: m.options, settings: m.settings }), contentType: 'application/json', dataType: 'html' })
                    .then(function (html) { if (luot === luotXem) datKhungXem(m.ma, html); }, function () { if (luot === luotXem) veXemJs(); });
            }, 200);
        }
        function datKhungXem(ma, html) {
            var rong = ma === 'a4' ? 794 : ma === 'a5' ? 559 : (mau(ma).settings.kho === '58' ? 219 : 302);
            var hop = $1('#tbXem'), khung = hop.querySelector('iframe');
            if (!khung) { hop.innerHTML = '<div class="tb-xem-that"><iframe title="Xem trước phiếu in" tabindex="-1"></iframe></div>'; khung = hop.querySelector('iframe'); }
            var vo = hop.firstChild, cho = $1('.tb-giay-khung').clientWidth - 20;
            var ti = Math.min(1, cho / rong);
            khung.style.width = rong + 'px';
            khung.onload = function () {
                var cao = khung.contentDocument ? khung.contentDocument.documentElement.scrollHeight : 600;
                khung.style.height = cao + 'px';
                // A5/A4 rộng hơn ô xem trước: thu nhỏ cả tờ, giữ nguyên tỉ lệ như giấy thật.
                khung.style.transform = ti < 1 ? 'scale(' + ti + ')' : '';
                vo.style.width = Math.round(rong * ti) + 'px';
                vo.style.height = Math.round(cao * ti) + 'px';
            };
            khung.srcdoc = html;
        }
        $1('#tbInThu').addEventListener('click', function () {
            var khung = $1('#tbXem iframe');
            if (!khung || !khung.contentWindow) return;
            khung.contentWindow.focus();
            khung.contentWindow.print();
        });
        function veXemJs() {
            var m = mau(mauMo), o = m.options, st = m.settings, h = '', kho = '';
            var w = function (k, html) { return html ? '<div data-k="' + k + '">' + html + '</div>' : ''; };
            var dg = function (a, b, cls) { return '<div class="dg' + (cls ? ' ' + cls : '') + '"><span>' + a + '</span><span>' + b + '</span></div>'; };
            var tenHang = function (it) { return esc(it.ten) + (o.bienthe && it.bt ? ' (' + it.bt + ')' : '') + (it.tang && o.qua ? ' (Tặng)' : ''); };
            var ds = HANG.filter(function (it) { return !it.tang || o.qua; });
            var tienHang = 0, bot = 0; ds.forEach(function (it) { tienHang += it.gia * it.sl; bot += it.bot; });
            var tong = tienHang - bot, d = function (n) { return so(n) + (o.donvitien ? 'đ' : ''); };
            var footer = esc(st.footer_txt || '');

            if (m.ma === 'hd') {
                kho = 'Khổ ' + st.kho + 'mm · ' + st.ban + ' bản';
                h += '<div class="g">' + w('logo', o.logo && LOGO) + w('ten', o.ten && '<p class="tt">' + TEN + '</p>') + w('diachi', o.diachi && '<div class="n">' + DC + '</div>') + w('sdt', o.sdt && '<div class="n">ĐT: ' + SDT + '</div>') + '</div><div class="ke"></div>';
                h += '<div class="g d">HOÁ ĐƠN BÁN HÀNG</div>';
                h += w('sohd', o.sohd && dg('Số: HD000245', o.tg ? '13/10/2026 10:42' : '', 'n'));
                if (!o.sohd) h += w('tg', o.tg && '<div class="n">13/10/2026 10:42</div>');
                h += w('thungan', o.thungan && '<div class="n">Thu ngân: Nguyễn Thu Hà</div>');
                h += w('khach', o.khach && '<div class="n">Khách: Trần Minh Anh · Hạng Bạc · 0912 345 678</div>');
                h += '<div class="ke"></div>';
                ds.forEach(function (it) {
                    h += '<div style="margin-top:3px"><div>' + tenHang(it) + '</div>' + dg(it.sl + (o.dvt ? ' ' + it.dvt : '') + ' × ' + so(it.gia), so(it.gia * it.sl), 'ct');
                    if (it.bot) h += w('chitietgia', o.chitietgia && dg('Bớt ' + it.botPt + '%', '-' + so(it.bot), 'ct n'));
                    if (it.ghichu) h += w('ghichu', o.ghichu && '<div class="ct n">• ' + it.ghichu + '</div>');
                    h += '</div>';
                });
                h += '<div class="ke"></div>' + dg('Tiền hàng', so(tienHang));
                h += o.chitietgia ? w('chitietgia', dg('Bớt theo món', '-' + so(bot))) : dg('Giảm giá', '-' + so(bot));
                h += w('vat', o.vat && dg('Thuế VAT', so(Math.round(tong * 8 / 108))));
                h += dg('TỔNG CỘNG', so(tong), 'to') + dg('Thanh toán', 'Tiền mặt');
                h += w('tienthua', o.tienthua && dg('Khách đưa', so(2000000)) + dg('TIỀN THỐI', so(2000000 - tong), 'd'));
                h += w('diem', o.diem && dg('Điểm tích được', '+19', 'n'));
                h += w('qr', o.qr && '<div class="ke"></div><div class="g">' + svgQR() + '<div class="n">Quét để chuyển khoản ' + so(tong) + 'đ</div></div>');
                h += w('footer', o.footer && footer && '<div class="ke"></div><div class="g n">' + footer + '</div>');
                h += w('sao', o.sao && '<div class="g sao">**********</div>');
                h = '<div class="gi k' + st.kho + '">' + h + '</div>';
            }

            if (m.ma === 'a5' || m.ma === 'a4') {
                var a4 = m.ma === 'a4';
                kho = (a4 ? 'A4 · 210 × 297mm' : 'A5 · 148 × 210mm') + ' · ' + st.ban + ' bản';
                var cot = 4 + (o.dvt ? 1 : 0) + (o.chitietgia ? 1 : 0);
                h += '<div style="display:flex;justify-content:space-between;gap:8px;align-items:flex-start"><div>' + w('logo', o.logo && '<div style="font-weight:700">[ LOGO ]</div>') +
                    w('ten', o.ten && '<div style="font-weight:700;font-size:1.15em">' + TEN + '</div>') + w('diachi', o.diachi && '<div>' + DC + '</div>') + w('sdt', o.sdt && '<div>ĐT: ' + SDT + '</div>') + '</div>' +
                    '<div style="text-align:right">' + w('sohd', o.sohd && '<div>Số: <b>HD000245</b></div>') + w('tg', o.tg && '<div>Ngày 13/10/2026 10:42</div>') + '</div></div>';
                h += '<div style="text-align:center;font-weight:700;font-size:1.35em;margin:10px 0 6px">HOÁ ĐƠN BÁN HÀNG</div>';
                h += w('khach', o.khach && '<div style="margin-bottom:6px">Khách hàng: <b>Trần Minh Anh</b> · Hạng Bạc · 0912 345 678</div>');
                h += '<table><thead><tr><th>#</th><th style="text-align:left">Tên hàng</th>' + (o.dvt ? '<th>ĐVT</th>' : '') + '<th>SL</th><th class="p">Đơn giá</th>' + (o.chitietgia ? '<th class="p">Bớt</th>' : '') + '<th class="p">Thành tiền</th></tr></thead><tbody>' +
                    ds.map(function (it, i) {
                        return '<tr><td class="g">' + (i + 1) + '</td><td>' + tenHang(it) + (o.ghichu && it.ghichu ? '<div style="font-size:.9em;font-style:italic">' + it.ghichu + '</div>' : '') + '</td>' + (o.dvt ? '<td class="g">' + it.dvt + '</td>' : '') +
                            '<td class="g">' + it.sl + '</td><td class="p">' + d(it.gia) + '</td>' + (o.chitietgia ? '<td class="p">' + (it.bot ? '-' + d(it.bot) : '') + '</td>' : '') + '<td class="p">' + d(it.gia * it.sl - (o.chitietgia ? it.bot : 0)) + '</td></tr>';
                    }).join('') +
                    (!o.chitietgia && bot ? '<tr><td colspan="' + (cot - 1) + '" class="p">Giảm giá</td><td class="p">-' + d(bot) + '</td></tr>' : '') +
                    '<tr><td colspan="' + (cot - 1) + '" class="p"><b>Tổng cộng</b></td><td class="p"><b>' + d(tong) + '</b></td></tr></tbody></table>';
                h += w('tienthua', o.tienthua && '<div style="text-align:right;margin-top:4px">Khách đưa ' + d(2000000) + ' · Tiền thối <b>' + d(2000000 - tong) + '</b></div>');
                h += w('qr', o.qr && '<div style="display:flex;align-items:center;gap:8px;margin-top:6px">' + svgQR().replace('class="qr"', 'style="width:64px;height:64px"') + '<div>Quét để chuyển khoản</div></div>');
                var ky = [['ckXuatKho', 'Người xuất kho'], ['ckGiaoHang', 'Người giao hàng'], ['ckCuaHang', 'Cửa hàng'], ['ckKhach', 'Khách hàng']].filter(function (x) { return o[x[0]]; });
                if (ky.length) h += '<div class="ky">' + ky.map(function (x) { return '<div data-k="' + x[0] + '"><b>' + x[1] + '</b><small>(Ký, ghi rõ họ tên)</small></div>'; }).join('') + '</div>';
                h += w('footer', o.footer && footer && '<div style="text-align:center;margin-top:10px;font-style:italic">' + footer + '</div>');
                h += w('sao', o.sao && '<div style="text-align:center;letter-spacing:2px">**********</div>');
                var rong = a4 ? 794 : 559, cao = a4 ? 1123 : 794, ti = 330 / rong;
                h = '<div style="width:' + (rong * ti) + 'px;height:' + (cao * ti) + 'px"><div class="giay-a" style="width:' + rong + 'px;min-height:' + cao + 'px;padding:' + (a4 ? 48 : 34) + 'px;font-size:' + (a4 ? 14 : 13) + 'px;transform:scale(' + ti + ');transform-origin:top left">' + h + '</div></div>';
            }

            if (m.ma === 'tem') {
                var kt = String(st.kho_tem || '40x30').split('x'), mm = 4.2, rw = +kt[0] * mm, rh = +kt[1] * mm, cotT = +st.cot || 1;
                var lon = +kt[0] >= 50 && +kt[1] >= 70;
                var tiT = Math.min(1, 330 / (rw * cotT + 6 * (cotT - 1)));
                kho = 'Tem ' + kt[0] + ' × ' + kt[1] + 'mm · ' + cotT + ' tem một hàng' + (o.footer && !lon ? ' · chân tem bị ẩn (tem nhỏ hơn 50×70)' : '');
                var tem = function (it, i, n) {
                    var x = '<div class="dg n">' + w('ten', o.ten && '<span class="d">' + TEN + '</span>') + w('sl', o.sl && '<span>' + i + '/' + n + '</span>') + '</div>';
                    x += w('sdt', o.sdt && '<div class="n">' + SDT + '</div>');
                    x += '<div class="d" style="margin-top:2px">' + esc(it.ten) + '</div>';
                    x += w('bienthe', o.bienthe && it.bt && '<div class="n">' + it.bt + '</div>');
                    x += '<div class="dg" style="margin-top:2px">' + w('gia', o.gia && '<span class="d">' + so(it.gia) + (o.donvitien ? 'đ' : '') + (o.dvt ? '/' + it.dvt : '') + '</span>') + w('mahd', o.mahd && '<span class="n">HD000245</span>') + '</div>';
                    x += w('ghichu', o.ghichu && it.ghichu && '<div class="n">• ' + it.ghichu + '</div>');
                    x += w('tg', o.tg && '<div class="n">13/10/2026 10:42</div>');
                    // Chân tem chỉ lọt trên tem lớn — đúng ghi chú của bản cũ.
                    x += w('footer', o.footer && footer && lon && '<div class="n" style="border-top:1px dashed #000;margin-top:3px;padding-top:2px">' + footer + '</div>');
                    return '<div class="tem' + (o.nguoc ? ' nguoc' : '') + '" style="width:' + rw + 'px;height:' + rh + 'px;font-size:' + ((+st.co || 9) * 1.15) + 'px">' + x + '</div>';
                };
                h = '<div style="transform:scale(' + tiT + ');transform-origin:top center"><div class="tem-luoi" style="grid-template-columns:repeat(' + cotT + ', ' + rw + 'px)">' + [tem(HANG[0], 1, 3), tem(HANG[0], 2, 3), tem(HANG[3], 3, 3)].join('') + '</div></div>';
            }

            if (m.ma === 'tra') {
                kho = 'Khổ ' + st.kho + 'mm · ' + st.ban + ' bản';
                h += '<div class="g">' + w('ten', o.ten && '<p class="tt">' + TEN + '</p>') + w('diachi', o.diachi && '<div class="n">' + DC + '</div>') + '</div><div class="ke"></div>';
                h += '<div class="g d">PHIẾU TRẢ HÀNG</div>';
                h += w('maphieu', o.maphieu && dg('Mã phiếu', 'TH000012', 'n')) + w('tgtra', o.tgtra && dg('Thời gian trả', '13/10/2026 15:20', 'n'));
                h += w('khachtra', o.khachtra && dg('Người trả', 'Trần Minh Anh', 'n')) + w('sdttra', o.sdttra && dg('SĐT', '0912 345 678', 'n'));
                h += '<div class="ke"></div>';
                h += w('sohd', o.sohd && dg('Hoá đơn gốc', 'HD000231', 'n')) + w('tg', o.tg && dg('Thanh toán lúc', '11/10/2026 18:05', 'n'));
                h += w('thungan', o.thungan && dg('Thu ngân', 'Nguyễn Thu Hà', 'n')) + w('khach', o.khach && dg('Khách', 'Trần Minh Anh · 0912 345 678', 'n')) + w('pttt', o.pttt && dg('Đã trả bằng', 'Quẹt thẻ', 'n'));
                h += '<div class="ke"></div><div>Tai nghe Bluetooth Air Pro' + (o.bienthe ? ' (Đen)' : '') + '</div>' + dg('1' + (o.dvt ? ' Cái' : '') + ' × 1.290.000', '1.290.000', 'ct') + '<div class="ke"></div>';
                h += w('tienhoan', o.tienhoan && dg('HOÀN LẠI', '1.290.000', 'to')) + w('pthoan', o.pthoan && dg('Hoàn bằng', 'Tiền mặt'));
                h += w('lydo', o.lydo && '<div class="n">Lý do: Lỗi kết nối tai phải</div>') + w('noidung', o.noidung && '<div class="n">Nội dung: Khách giữ hộp, đổi mẫu khác sau</div>');
                var kyT = [['kyCuaHang', 'Cửa hàng'], ['kyKhach', 'Khách hàng']].filter(function (x) { return o[x[0]]; });
                if (kyT.length) h += '<div class="ky n">' + kyT.map(function (x) { return '<div data-k="' + x[0] + '"><b>' + x[1] + '</b><br>(ký tên)</div>'; }).join('') + '</div>';
                h = '<div class="gi k' + st.kho + '">' + h + '</div>';
            }

            if (m.ma === 'ca') {
                kho = 'Khổ ' + st.kho + 'mm · ' + st.ban + ' bản';
                h += '<div class="g">' + w('ten', o.ten && '<p class="tt">' + TEN + '</p>') + w('diachi', o.diachi && '<div class="n">' + DC + '</div>') + w('sdt', o.sdt && '<div class="n">ĐT: ' + SDT + '</div>') + '</div><div class="ke"></div>';
                h += '<div class="g d">PHIẾU KẾT CA</div>';
                h += w('calv', o.calv && dg('Ca', 'Ca sáng #126', 'n')) + w('nvdong', o.nvdong && dg('Người đóng', 'Nguyễn Thu Hà', 'n')) + w('tgdong', o.tgdong && dg('Đóng lúc', '13/10/2026 14:02', 'n'));
                h += '<div class="ke"></div>';
                h += w('tongdt', o.tongdt && dg('Tổng doanh thu', '18.460.000', 'd')) + w('dtTienMat', o.dtTienMat && dg('Tiền mặt', '9.120.000', 'ct')) + w('dtCK', o.dtCK && dg('Chuyển khoản', '6.050.000', 'ct')) + w('dtThe', o.dtThe && dg('Quẹt thẻ', '3.290.000', 'ct'));
                h += w('congno', o.congno && dg('Bán nợ', '450.000')) + w('khachTraNo', o.khachTraNo && dg('Khách trả nợ', '1.200.000'));
                h += w('phieuthu', o.phieuthu && dg('Phiếu thu (2)', '+500.000')) + w('phieuchi', o.phieuchi && dg('Phiếu chi (1)', '-180.000'));
                h += '<div class="ke"></div>' + w('sohd', o.sohd && dg('Số hoá đơn', '42')) + w('sohdhuy', o.sohdhuy && dg('Hoá đơn huỷ', '1'));
                h += w('sosp', o.sosp && dg('Sản phẩm bán', o.tachTang ? '84 + 3 tặng' : '87'));
                h += '<div class="ke"></div>' + w('dauca', o.dauca && dg('Tiền mặt đầu ca', '2.000.000')) + w('cuoica', o.cuoica && dg('Tiền mặt cuối ca', '11.440.000', 'd'));
                h += w('ghichu', o.ghichu && '<div class="n">Ghi chú: Đã bàn giao ca chiều.</div>');
                h += w('xacnhan', o.xacnhan && '<div class="ky n"><div><b>Người giao</b><br>(ký tên)</div><div><b>Người nhận</b><br>(ký tên)</div></div>');
                h = '<div class="gi k' + st.kho + '">' + h + '</div>';
            }

            $1('#tbXem').innerHTML = h;
            $1('#tbXemKho').textContent = kho;
            if (dongMoi) {
                // Tô sáng dòng vừa bật để thấy nó nằm đâu trên phiếu.
                $$('#tbXem [data-k="' + dongMoi + '"]').forEach(function (el) { el.classList.add('gi-moi'); el.scrollIntoView({ block: 'nearest' }); setTimeout(function () { el.classList.remove('gi-moi'); }, 900); });
                dongMoi = null;
            }
        }

        // ---------- Khởi động ----------
        if (!sua()) { $1('.tb').classList.add('tb-chi-xem'); $1('#tbChiXem').hidden = false; }
        $1('#tbLuu').innerHTML = '<i class="fa-solid fa-circle-check"></i>Tự lưu khi đổi';
        moView(view, false);
    })();
    </script>
@endpush
@endunless
