{{-- Cài đặt → Phương thức thanh toán, dựng theo màn system/payment-method của
     ordertable v2 rồi làm lại bố cục: danh sách 4 phương thức bên trái, chi tiết
     bên phải (bản thiết kế đã duyệt ở design/html/thanh-toan.html).

     Dữ liệu ban đầu do PaymentMethodController::index đẩy vào #pmData; mỗi lượt
     ghi gọi các đường JSON của controller rồi nạp lại từ phương thức data(). Mọi
     luật nằm ở API — trang chỉ chặn sớm ô trống cho đỡ một lượt đi về. --}}
@extends('v2::layouts.master')

@section('title', \App\Http\Controllers\PaymentMethodController::TITLE)

@push('styles')
    <style>
        /* Màu theo khung v2: navy #1a2b58, cam #f29220, đầu bảng #D5E1F3. */
        .index-payment-method-page {
            --pm-navy: #1a2b58; --pm-cam: #f29220; --pm-cam-nhat: #fff4e5; --pm-nen: #f4f6fb;
            --pm-vien: #e2e6ef; --pm-mo: #6b7280; --pm-xanh: #157a34; --pm-xanh-nhat: #e7f5ec;
        }
        label.form-label { font-weight: bold; }
        #modalBankTransfer .modal-header, #modalCrUdSwipeCard .modal-header,
        #modalQR .modal-header, #modalCrudDevice .modal-header { background: #D5E1F3 !important; }
        #modalCrudDevice .dv-xoa:disabled { opacity: .45; cursor: not-allowed; }

        .pm-dau { gap: 12px; padding: 16px 20px !important; border-bottom: 1px solid var(--pm-vien); }
        .tieu-de-trang { margin: 0; font-size: 20px; font-weight: 700; color: var(--pm-navy); }
        .pm-sub { margin: 4px 0 0; color: var(--pm-mo); font-size: 13.5px; }
        .pm-cn { display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; border-radius: 999px;
                 background: var(--pm-nen); color: var(--pm-navy); font-size: 13px; font-weight: 500; white-space: nowrap; }

        .pm-khung { display: grid; grid-template-columns: 340px minmax(0, 1fr); gap: 20px; align-items: start; padding: 20px; }

        /* ---- Cột trái: danh sách phương thức ---- */
        .pm-ds { display: flex; flex-direction: column; gap: 10px; }
        .pm-tile { position: relative; display: flex; align-items: center; gap: 12px; padding: 12px 14px 12px 12px;
                   border: 1px solid var(--pm-vien); border-radius: 10px; background: #fff; cursor: pointer;
                   transition: border-color .15s, box-shadow .15s, background-color .15s; }
        .pm-tile:hover { border-color: #b9c3d9; }
        .pm-tile:focus-visible { outline: 2px solid #1586ff; outline-offset: 2px; }
        .pm-tile.active { border-color: var(--pm-navy); background: var(--pm-nen); box-shadow: inset 4px 0 0 var(--pm-cam); }
        .pm-ic { display: inline-flex; flex: 0 0 48px; align-items: center; justify-content: center; width: 48px; height: 48px;
                 border-radius: 10px; background: var(--pm-nen); }
        .pm-tile.active .pm-ic { background: #fff; }
        .pm-ic img { width: 34px; height: 34px; }
        .pm-ic.nho { flex-basis: 40px; width: 40px; height: 40px; }
        .pm-ic.nho img { width: 28px; height: 28px; }
        .pm-tile.tat .pm-ic img { filter: grayscale(1); opacity: .55; }
        .pm-tile-txt { display: flex; flex: 1 1 auto; min-width: 0; flex-direction: column; gap: 1px; }
        .pm-tile-ten { display: flex; align-items: center; gap: 6px; color: var(--pm-navy); font-size: 15px; font-weight: 700; white-space: nowrap; }
        .pm-tile-ten .pm-chip { padding: 0 6px; font-size: 11px; }
        .pm-tile-noi { color: var(--pm-mo); font-size: 12px; white-space: nowrap; }
        .pm-tile-dem { margin-top: 2px; font-size: 12.5px; font-weight: 500; white-space: nowrap; }
        .pm-tile-dem.ok { color: var(--pm-xanh); }
        .pm-tile-dem.tat { color: var(--pm-mo); font-weight: 400; }
        .pm-tile-dem.canh { color: #8a4b00; }
        .pm-tile-phai { display: flex; flex: 0 0 auto; flex-direction: column; align-items: flex-end; gap: 8px; }
        .pm-luon { color: var(--pm-mo); font-size: 12px; white-space: nowrap; }

        .pm-chip { display: inline-flex; align-items: center; gap: 4px; padding: 2px 8px; border-radius: 999px;
                   font-size: 12px; font-weight: 500; line-height: 18px; white-space: nowrap; }
        .pm-chip.md { background: var(--pm-cam-nhat); color: #8a4b00; }
        .pm-chip.md i { color: var(--pm-cam); font-size: 10px; }
        .pm-chip.dung { margin-left: 6px; background: #eef1f6; color: #4b5563; }
        .pm-chip.mpos { background: #e7f0ff; color: #1a4fb4; }
        .pm-chip.doc-lap { background: #f1f1f1; color: #444; }
        .pm-dat-md { padding: 2px 8px; border: 1px dashed #b9c3d9; border-radius: 999px; background: transparent;
                     color: var(--pm-navy); font-size: 12px; line-height: 18px; white-space: nowrap; }
        .pm-dat-md:hover { border-style: solid; border-color: var(--pm-navy); background: #fff; }
        .pm-dat-md:focus-visible { outline: 2px solid #1586ff; outline-offset: 1px; }

        /* ---- Cột phải: chi tiết ---- */
        .pm-panel { border: 1px solid var(--pm-vien); border-radius: 10px; background: #fff; overflow: hidden; }
        .pm-panel-dau { display: flex; align-items: center; gap: 12px; padding: 14px 16px; border-bottom: 1px solid var(--pm-vien); }
        .pm-panel-tieude { flex: 1 1 auto; min-width: 0; }
        .pm-panel-dau h2 { margin: 0; color: var(--pm-navy); font-size: 17px; font-weight: 700; }
        .pm-panel-dau p { margin: 2px 0 0; color: var(--pm-mo); font-size: 13px; }
        .pm-panel-nut { display: flex; flex: 0 0 auto; align-items: center; gap: 10px; }
        .pm-panel-nut .bt { white-space: nowrap; }
        .pm-panel-dau .pm-dat-md, .pm-panel-dau .pm-chip.md { padding: 4px 10px; font-size: 13px; }
        .pm-the .pm-gach { display: none; }
        .pm-tom { display: flex; align-items: center; gap: 10px; margin: 12px 16px 0; padding: 10px 12px; border-radius: 8px;
                  background: var(--pm-nen); color: var(--pm-navy); font-size: 13.5px; }
        .pm-tom i { color: var(--pm-cam); font-size: 18px; }
        .pm-panel .table-responsive { margin: 12px 0 0; }
        .pm-table { width: 100%; border-collapse: collapse; table-layout: auto; }
        .pm-table th, .pm-table td { padding: 10px 12px; border-bottom: 1px solid #e9ebec; font-size: 14px; white-space: nowrap; vertical-align: middle; }
        .pm-table thead th { background: #D5E1F3; color: #1f2937; font-weight: 700; }
        .pm-table tbody tr:last-child td { border-bottom: 0; }
        .pm-table tr.tat td:not(.giu) { color: #9aa0a6; }
        .pm-table .action a, .pm-the .action a { display: inline-flex; align-items: center; justify-content: center;
                                                 width: 32px; height: 32px; border-radius: 6px; cursor: pointer; }
        .pm-table .action a:hover, .pm-the .action a:hover { background: var(--pm-nen); }
        .pm-o2 { display: flex; flex-direction: column; gap: 2px; }
        .pm-o2 b { color: #1f2937; font-weight: 500; }
        .pm-o2 > span { color: #4b5563; font-size: 13px; }
        .pm-o2 .pm-chip.dung { margin-left: 4px; }
        .so { font-family: "Roboto Mono", ui-monospace, monospace; letter-spacing: .2px; }
        .pm-than { padding: 16px; }
        .pm-y { display: flex; flex-direction: column; gap: 10px; margin: 0; padding: 0; list-style: none; color: #374151; font-size: 14px; }
        .pm-y li { display: flex; gap: 10px; align-items: baseline; }
        .pm-y i { width: 16px; color: var(--pm-cam); text-align: center; }
        .pm-rong { display: flex; flex-direction: column; align-items: center; gap: 10px; padding: 48px 16px; text-align: center; }
        .pm-rong-ic { display: inline-flex; align-items: center; justify-content: center; width: 56px; height: 56px; border-radius: 50%;
                      background: var(--pm-nen); color: #8a96b3; font-size: 22px; }
        .pm-rong p { max-width: 360px; margin: 0; color: var(--pm-mo); }
        .pm-note { margin: 12px 16px 16px; padding: 8px 12px; border-left: 3px solid var(--pm-cam); border-radius: 4px;
                   background: #fff8ef; color: #5b3a00; font-size: 13px; }

        /* Thẻ thay bảng trên điện thoại */
        .pm-the-list { display: flex; flex-direction: column; gap: 10px; padding: 12px; }
        .pm-the { padding: 10px 12px; border: 1px solid var(--pm-vien); border-radius: 8px; }
        .pm-the.tat > div:not(.pm-the-duoi) { color: #9aa0a6; }
        .pm-the-tren { display: flex; align-items: center; justify-content: space-between; gap: 8px; color: var(--pm-navy); }
        .pm-the-phu { margin-top: 2px; color: var(--pm-mo); font-size: 13px; }
        .pm-the-duoi { display: flex; align-items: center; justify-content: space-between; margin-top: 8px; padding-top: 8px; border-top: 1px dashed var(--pm-vien); }

        /* ---- Hộp thoại ---- */
        .pm-hop { --pm-navy: #1a2b58; --pm-cam: #f29220; --pm-nen: #f4f6fb; --pm-vien: #e2e6ef; --pm-mo: #6b7280; }
        .pm-hop .modal-content { border: 0; border-radius: 12px; overflow: hidden; }
        .pm-hop .modal-header { align-items: center; gap: 12px; padding: 14px 20px; background: #D5E1F3 !important; border-bottom: 0; }
        .pm-hop .modal-header .pm-ic { background: #fff; color: var(--pm-navy); font-size: 18px; }
        .pm-hop-tieude { flex: 1 1 auto; min-width: 0; }
        .pm-hop-tieude .modal-title { margin: 0; color: var(--pm-navy); font-size: 17px; font-weight: 700; }
        .pm-hop-tieude p { margin: 2px 0 0; color: #4b5563; font-size: 13px; }
        .pm-hop .modal-header .btn-close { align-self: flex-start; margin: 2px 0 0; }
        .pm-hop .modal-body { padding: 18px 20px 20px; }
        .pm-hop .form-label { margin-bottom: 4px; color: #1f2937; font-size: 13.5px; letter-spacing: normal !important; word-spacing: normal !important; }
        .pm-hop b, .pm-hop small, .pm-hop .modal-title { letter-spacing: normal; word-spacing: normal; }
        .pm-hop .form-control, .pm-hop .form-select { min-height: 40px; }
        .pm-hop .form-text { font-size: 12.5px; }
        .pm-hop .modal-footer { gap: 8px; padding: 12px 20px; border-top: 1px solid var(--pm-vien); background: #fafbfd; }
        .pm-hop .modal-footer .bt { min-width: 96px; }

        .pm-muc { display: flex; flex-wrap: wrap; align-items: baseline; gap: 4px 8px; margin: 20px 0 10px; color: var(--pm-navy); font-size: 14px; font-weight: 700; }
        .pm-hop .modal-body > .pm-muc:first-of-type, .pm-hop .modal-body > input + select + .pm-muc { margin-top: 0; }
        .pm-muc small { flex-basis: 100%; color: var(--pm-mo); font-size: 12.5px; font-weight: 400; }
        .pm-so { display: inline-flex; align-items: center; justify-content: center; width: 20px; height: 20px; border-radius: 50%;
                 background: var(--pm-navy); color: #fff; font-size: 11px; transform: translateY(-1px); }

        .pm-hai-cot { display: grid; grid-template-columns: minmax(0, 1fr) 300px; gap: 20px; }
        /* Xem trước: thẻ VietQR như khách thấy ở quầy / website — mã quét được thật. */
        .pm-qr-xem { align-self: start; padding: 12px; border-radius: 12px; background: var(--pm-nen); }
        .pm-qr-nhan { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 10px;
                      color: var(--pm-mo); font-size: 11.5px; font-weight: 700; letter-spacing: .6px !important; text-transform: uppercase; }
        .pm-qr-nhan .pm-chip { letter-spacing: normal !important; text-transform: none; }
        .vq { position: relative; overflow: hidden; border: 1px solid var(--pm-vien); border-radius: 12px; background: #fff;
              box-shadow: 0 1px 2px rgba(16, 24, 40, .06), 0 4px 12px rgba(16, 24, 40, .05); transition: filter .15s, opacity .15s; }
        .vq-dau { display: flex; align-items: center; justify-content: space-between; padding: 10px 14px; border-bottom: 1px solid #eef1f6; }
        .vq-logo { font-size: 17px; font-weight: 800; letter-spacing: -.3px !important; line-height: 1; }
        .vq-logo b { color: #da251d; font-weight: 800; } .vq-logo span { color: #0a3a8c; }
        .vq-napas { color: #0a3a8c; font-size: 11px; font-weight: 700; letter-spacing: .2px !important; }
        .vq-napas i { color: #da251d; font-style: normal; }
        .vq-ma { position: relative; width: 176px; height: 176px; margin: 14px auto 10px; }
        .vq-ma svg { display: block; width: 100%; height: 100%; }
        .vq-goc { position: absolute; width: 22px; height: 22px; border: 3px solid #da251d; }
        .vq-goc.a { top: -8px; left: -8px; border-right: 0; border-bottom: 0; border-top-left-radius: 6px; }
        .vq-goc.b { top: -8px; right: -8px; border-left: 0; border-bottom: 0; border-top-right-radius: 6px; }
        .vq-goc.c { bottom: -8px; left: -8px; border-right: 0; border-top: 0; border-bottom-left-radius: 6px; }
        .vq-goc.d { bottom: -8px; right: -8px; border-left: 0; border-top: 0; border-bottom-right-radius: 6px; }
        .vq-trong { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 8px;
                    padding: 14px; border-radius: 8px; background: repeating-linear-gradient(45deg, #f6f7fb 0 8px, #fff 8px 16px);
                    color: var(--pm-mo); font-size: 12.5px; line-height: 1.4; text-align: center; }
        .vq-trong i { color: #c3cad9; font-size: 34px; }
        .vq-nh { display: flex; align-items: center; justify-content: center; gap: 8px; padding: 0 14px; }
        .vq-nh-ic { display: inline-flex; align-items: center; justify-content: center; flex: 0 0 auto; min-width: 30px; height: 22px; padding: 0 6px;
                    border-radius: 6px; background: #0a3a8c; color: #fff; font-size: 10.5px; font-weight: 700; letter-spacing: .2px !important; }
        .vq-nh-ic[hidden] { display: none; }
        .vq-nh b { color: #111827; font-size: 13.5px; font-weight: 600; }
        .vq-chu { margin: 6px 14px 0; color: #111827; font-size: 15px; font-weight: 700; text-align: center; overflow-wrap: anywhere; }
        .vq-so { margin: 2px 14px 12px; color: #0a3a8c; font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
                 font-size: 15px; font-weight: 600; letter-spacing: .5px !important; text-align: center; overflow-wrap: anywhere; }
        .vq-chu.mo, .vq-so.mo { color: #b4bccb; font-weight: 500; }
        .vq-ds { display: grid; grid-template-columns: auto minmax(0, 1fr); gap: 6px 10px; margin: 0; padding: 10px 14px 12px;
                 border-top: 1px dashed #dfe4ee; background: #fafbfd; font-size: 12.5px; }
        .vq-ds dt { color: var(--pm-mo); font-weight: 400; white-space: nowrap; }
        .vq-ds dd { margin: 0; color: #111827; font-weight: 600; text-align: right; overflow-wrap: anywhere; }
        .vq-ds dd small { display: block; color: var(--pm-mo); font-size: 11.5px; font-weight: 400; }
        .vq-tat { position: absolute; top: 46px; left: 50%; transform: translateX(-50%); padding: 4px 10px; border-radius: 999px;
                  background: #374151; color: #fff; font-size: 12px; font-weight: 600; white-space: nowrap; }
        .pm-qr-xem.tat .vq > :not(.vq-tat) { filter: grayscale(1); opacity: .45; }
        .vq-goi { display: flex; gap: 6px; margin: 10px 2px 0; color: var(--pm-mo); font-size: 12px; line-height: 1.45; }
        .vq-goi i { margin-top: 2px; color: var(--pm-xanh); }

        /* Ô chọn có tìm (thay giao diện <select> gốc; select gốc vẫn giữ giá trị, kiểm bắt buộc). */
        .pmc { position: relative; min-width: 0; }
        .input-group > .pmc { flex: 1 1 auto; width: 1%; }
        .input-group > .pmc:not(:last-child) .pmc-nut { border-top-right-radius: 0; border-bottom-right-radius: 0; }
        .pmc > select { position: absolute; left: 0; bottom: 0; width: 1px; height: 1px; opacity: 0; pointer-events: none; }
        .pmc-nut { display: flex; align-items: center; gap: 8px; width: 100%; min-height: 40px; padding: 6px 34px 6px 12px; text-align: left;
                   background-color: #fff; cursor: pointer; transition: border-color .15s, box-shadow .15s; }
        .pmc-nut:hover { border-color: #b6c0d4; }
        .pmc-nut:focus-visible, .pmc.mo .pmc-nut { border-color: #6d8ad6; outline: 0; box-shadow: 0 0 0 3px rgba(26, 79, 180, .14); }
        .pmc-nut:disabled { background-color: #f3f4f7; cursor: not-allowed; }
        .pmc-nut .pmc-chu { flex: 1 1 auto; min-width: 0; color: #111827; white-space: nowrap; overflow: hidden; }
        .pmc-nut .pmc-chu.trong { color: #9aa3b2; }
        .co-loi .pmc-nut { border-color: #c62828; }
        .pmc-bang { position: fixed; z-index: 1090; display: flex; flex-direction: column; overflow: hidden; border: 1px solid #d9dfea; border-radius: 10px;
                    background: #fff; box-shadow: 0 12px 32px rgba(16, 24, 40, .16), 0 2px 6px rgba(16, 24, 40, .08); }
        .pmc-bang[hidden] { display: none; }
        .pmc-tim { position: relative; flex: 0 0 auto; padding: 8px; border-bottom: 1px solid #eef1f6; }
        .pmc-tim i { position: absolute; top: 50%; left: 20px; transform: translateY(-50%); color: #9aa3b2; font-size: 13px; }
        .pmc-tim input { width: 100%; height: 36px; padding: 0 30px 0 34px; border: 1px solid #dfe4ee; border-radius: 8px; background: #f7f8fb; font-size: 14px; }
        .pmc-tim input:focus { border-color: #6d8ad6; outline: 0; background: #fff; box-shadow: 0 0 0 3px rgba(26, 79, 180, .12); }
        .pmc-tim .pmc-xoa { position: absolute; top: 50%; right: 14px; display: flex; align-items: center; justify-content: center; width: 22px; height: 22px;
                            min-width: 0; padding: 0; border: 0; border-radius: 50%; background: #e7eaf1; color: #5b6475; font-size: 11px; line-height: 1; transform: translateY(-50%); }
        .pmc-tim .pmc-xoa[hidden] { display: none; }
        .pmc-tim .pmc-xoa i { position: static; transform: none; color: inherit; font-size: 11px; }
        .pmc-ds { flex: 1 1 auto; margin: 0; padding: 4px; overflow-y: auto; list-style: none; overscroll-behavior: contain; }
        .pmc-nhom { padding: 8px 10px 4px; color: #8a93a3; font-size: 11px; font-weight: 700; letter-spacing: .5px !important; text-transform: uppercase; }
        .pmc-mon { display: flex; align-items: center; gap: 10px; min-height: 40px; padding: 6px 10px; border-radius: 7px; color: #111827; font-size: 14px; cursor: pointer; }
        .pmc-mon.dang { background: #eef3ff; }
        .pmc-mon[aria-selected="true"] { font-weight: 600; }
        .pmc-mon .pmc-ten { flex: 1 1 auto; min-width: 0; overflow-wrap: anywhere; }
        .pmc-mon .pmc-phu, .pmc-nut .pmc-phu { flex: 0 0 auto; color: #8a93a3; font-family: ui-monospace, Menlo, Consolas, monospace; font-size: 12px; font-weight: 400; }
        .pmc-mon .pmc-dau { flex: 0 0 16px; color: #1a4fb4; font-size: 13px; visibility: hidden; }
        .pmc-mon[aria-selected="true"] .pmc-dau { visibility: visible; }
        .pmc-mon mark { padding: 0; border-radius: 2px; background: #fff1c2; color: inherit; }
        .pmc-the { display: inline-flex; align-items: center; justify-content: center; flex: 0 0 auto; min-width: 42px; height: 22px; padding: 0 6px;
                   border-radius: 6px; background: #e9effb; color: #0a3a8c; font-size: 10.5px; font-weight: 700; letter-spacing: .2px !important; }
        .pmc-the.may { min-width: 26px; background: #f1f3f8; color: #4b5563; font-size: 12px; }
        .pmc-rong { padding: 18px 12px; color: #6b7280; font-size: 13px; text-align: center; }
        .pmc-rong i { display: block; margin-bottom: 6px; color: #c3cad9; font-size: 20px; }

        .pm-tuy-ds { border: 1px solid var(--pm-vien); border-radius: 10px; }
        .pm-tuy { display: flex; align-items: center; justify-content: space-between; gap: 16px; margin: 0; padding: 12px 14px; cursor: pointer; }
        .pm-tuy + .pm-tuy { border-top: 1px solid var(--pm-vien); }
        .pm-tuy b { display: block; color: #1f2937; font-size: 14px; font-weight: 500; }
        .pm-tuy small { display: block; margin-top: 1px; color: var(--pm-mo); font-size: 12.5px; }
        .pm-tuy input { flex: 0 0 auto; }

        .pm-chon-ds { display: grid; gap: 10px; }
        .pm-chon-ds.hai { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .pm-chon-ds.bon { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .pm-chon { position: relative; display: flex; align-items: flex-start; gap: 10px; margin: 0; padding: 12px 14px;
                   border: 1px solid var(--pm-vien); border-radius: 10px; background: #fff; cursor: pointer; transition: border-color .15s, background-color .15s; }
        .pm-chon:hover { border-color: #b9c3d9; }
        .pm-chon input { position: absolute !important; top: 0; left: 0; width: 1px !important; height: 1px !important; margin: 0 !important;
                         opacity: 0 !important; clip: rect(0 0 0 0); appearance: none; pointer-events: none; }
        .pm-chon:has(input:checked) { border-color: var(--pm-navy); background: var(--pm-nen); box-shadow: inset 0 0 0 1px var(--pm-navy); }
        .pm-chon:has(input:focus-visible) { outline: 2px solid #1586ff; outline-offset: 2px; }
        .pm-chon:has(input:disabled) { cursor: not-allowed; opacity: .55; }
        .pm-chon b { display: block; color: var(--pm-navy); font-size: 14px; }
        .pm-chon small { display: block; color: var(--pm-mo); font-size: 12.5px; }
        .pm-chon-ic { display: inline-flex; flex: 0 0 36px; align-items: center; justify-content: center; width: 36px; height: 36px;
                      border-radius: 8px; background: var(--pm-nen); color: var(--pm-navy); font-size: 16px; }
        .pm-chon:has(input:checked) .pm-chon-ic { background: #fff; }
        .pm-chon .pm-da-co { display: none; position: absolute; top: 8px; right: 10px; padding: 0 6px; border-radius: 999px;
                             background: #eef1f6; color: #4b5563; font-size: 11px; font-style: normal; }
        .pm-chon.da-co .pm-da-co { display: inline-block; }
        .pm-chon-ds .pm-loi { grid-column: 1 / -1; }

        .pm-phi-luoi { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0 24px; border: 1px solid var(--pm-vien); border-radius: 10px; padding: 4px 14px; }
        .pm-phi-luoi label { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin: 0; padding: 8px 0; border-bottom: 1px solid #f0f2f6; }
        .pm-phi-luoi label:nth-last-child(-n+2) { border-bottom: 0; }
        .pm-phi-luoi b { display: block; color: #1f2937; font-size: 13.5px; font-weight: 500; }
        .pm-phi-luoi small { display: block; color: var(--pm-mo); font-size: 12px; }
        .pm-phi-luoi .input-group { flex: 0 0 112px; width: 112px; }
        .pm-phi-luoi .form-control { min-height: 36px; text-align: right; }

        .pm-url { padding: 10px 12px; border: 1px solid #f3d9a4; border-radius: 8px; background: #fffaf0; }
        .pm-url-nhan { display: block; margin-bottom: 6px; color: #8a4b00; font-size: 12.5px; font-weight: 500; }
        .pm-url-dong { display: flex; align-items: center; gap: 8px; }
        .pm-url code { flex: 1 1 auto; min-width: 0; padding: 6px 8px; border-radius: 6px; background: #fff; color: #1f2937; font-size: 12.5px; overflow-wrap: anywhere; }
        .pm-url .pm-chep { flex: 0 0 auto; white-space: nowrap; }

        .pm-hop-xoa .modal-body { padding: 24px 20px 16px; }
        .pm-xoa-ic { display: inline-flex; align-items: center; justify-content: center; width: 52px; height: 52px; border-radius: 50%;
                     background: #fdecec; color: #c62828; font-size: 20px; }
        .pm-hop-xoa h5 { color: #1f2937; font-size: 17px; font-weight: 700; }
        .pm-hop-xoa .cau-hoi { color: #374151; font-weight: 500; overflow-wrap: anywhere; }
        .pm-hop-xoa .modal-footer { justify-content: center; }
        .pm-hop-xoa .modal-footer .bt { flex: 1 1 0; }

        .pm-loi { display: none; margin-top: 4px; color: #c62828; font-size: 12.5px; }
        .co-loi .pm-loi { display: block; }
        .co-loi .form-control, .co-loi .form-select { border-color: #c62828; }
        .co-loi .pm-chon { border-color: #e7a1a1; }
        .pm-phi .form-label small { font-weight: 400; }
        .pm-ghi { display: flex; gap: 8px; align-items: baseline; padding: 8px 12px; border-radius: 8px; background: #f4f6fb; color: #374151; font-size: 12.5px; }
        .pm-ghi i { color: #1a2b58; }
        .pm-buoc { margin: 0; padding: 10px 14px 10px 32px; border-radius: 8px; background: #f4f6fb; color: #374151; font-size: 13px; }
        .pm-buoc li + li { margin-top: 4px; }
        .pm-chip.ok { background: #e7f5ec; color: #157a34; }
        .pm-chip.cho { background: #fff4e5; color: #8a4b00; }
        .pm-chip.chung { background: #eef1f6; color: #4b5563; }
        .pm-kq { display: flex; gap: 10px; align-items: flex-start; padding: 10px 12px; border-radius: 8px; font-size: 13.5px; }
        .pm-kq.ok { background: #e7f5ec; color: #14532d; }
        .pm-kq.loi { background: #fdecec; color: #7f1d1d; }
        .pm-kq.dang { background: #f4f6fb; color: #1a2b58; }
        .pm-kq i { margin-top: 2px; }
        .pm-kq small { display: block; margin-top: 2px; opacity: .8; }
        .bt.btn_blue { background: #1a4fb4; color: #fff; }

        @media (max-width: 767.98px) {
            .pm-hai-cot { grid-template-columns: minmax(0, 1fr); }
            .pm-qr-xem { max-width: 340px; width: 100%; justify-self: center; }
            .pm-phi-luoi { grid-template-columns: minmax(0, 1fr); }
            .pm-phi-luoi label:nth-last-child(2) { border-bottom: 1px solid #f0f2f6; }
        }
        @media (max-width: 575.98px) {
            #modalQR .modal-footer { flex-wrap: wrap; }
            #modalQR .modal-footer .pm-kiem-tra { order: -1; flex: 1 0 100%; }
            .pm-chon-ds.hai, .pm-chon-ds.bon { grid-template-columns: minmax(0, 1fr); }
            .pm-hop .modal-header, .pm-hop .modal-body, .pm-hop .modal-footer { padding-left: 14px; padding-right: 14px; }
            .pm-hop .modal-footer .bt { flex: 1 1 0; }
        }


        @media (max-width: 1439.98px) {
            .pm-khung { grid-template-columns: 320px minmax(0, 1fr); gap: 16px; padding: 16px; }
            .pm-table th, .pm-table td { padding: 8px; font-size: 13px; }
            .pm-o2 > span { font-size: 12.5px; }
        }
        @media (min-width: 576px) and (max-width: 1199.98px) {
            .pm-the-list { display: grid !important; grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        /* Dưới 992px: danh sách lên trên thành lưới 2 cột, chi tiết xuống dưới. */
        @media (max-width: 991.98px) {
            .pm-khung { grid-template-columns: minmax(0, 1fr); }
            .pm-ds { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        @media (max-width: 575.98px) {
            .pm-dau { flex-wrap: wrap; padding: 12px !important; }
            .pm-khung { padding: 12px; gap: 12px; }
            .pm-ds { grid-template-columns: minmax(0, 1fr); gap: 8px; }
            .pm-tile { padding: 10px 12px 10px 10px; }
            .pm-panel-dau { flex-wrap: wrap; }
            .pm-panel-nut { flex: 1 0 100%; flex-wrap: wrap; }
            .pm-panel-nut .bt { flex: 1 1 auto; }
        }
    </style>
@endpush

@section('content')
    <div class="row index-payment-method-page">
        <div class="col-12 mb-2">
            <div class="content_midd">
                <div class="content_midd_title pm-dau">
                    <div>
                        <h1 class="tieu-de-trang">Phương thức thanh toán</h1>
                        <p class="pm-sub">Chọn những hình thức chi nhánh nhận tiền và hình thức mở sẵn khi thanh toán ở quầy.</p>
                    </div>
                    @if ($tenChiNhanh !== '')
                        <span class="pm-cn" title="Mỗi chi nhánh có bộ phương thức riêng. Đổi chi nhánh ở góc trên để cấu hình chi nhánh khác.">
                            <i class="fa-solid fa-store"></i> {{ $tenChiNhanh }}
                            @if (!empty($data['shop_id']) && ($data['shop_id'] ?? 0) === ($data['web_shop_id'] ?? -1))
                                <span class="pm-chip md ms-1" title="Website lấy tài khoản và cổng QR của chi nhánh này">Website</span>
                            @endif
                        </span>
                    @endif
                </div>
                @if ($loiTai)
                    <div class="pm-rong">
                        <span class="pm-rong-ic"><i class="fa-solid fa-triangle-exclamation"></i></span>
                        <p>{{ $loiTai }}</p>
                        <a href="{{ route('admin.phuong-thuc-tt.index') }}" class="bt btn_gray">Tải lại</a>
                    </div>
                @else
                    <div class="pm-khung">
                        <div class="pm-ds" id="pmThe" role="tablist" aria-label="Hình thức thanh toán" aria-orientation="vertical"></div>
                        <section class="pm-panel" id="pmChiTiet" role="tabpanel"></section>
                    </div>
                @endif
            </div>
        </div>
    </div>

    {{-- Chuyển khoản --}}
    <div class="modal fade pm-hop" id="modalBankTransfer" tabindex="-1" aria-hidden="true" aria-labelledby="btTieuDe">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down">
            <div class="modal-content">
                <div class="modal-header">
                    <span class="pm-ic nho"><img src="{{ asset('v2/images/payment-methods') }}/pm-transfer.svg" alt=""></span>
                    <div class="pm-hop-tieude">
                        <h5 class="modal-title tieu-de-hop" id="btTieuDe">Thêm tài khoản chuyển khoản</h5>
                        <p>Khách quét QR hoặc chuyển tay vào tài khoản này.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Đóng"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" class="id">
                    <div class="pm-hai-cot">
                        <div>
                            <div class="mb-3 o-nhap">
                                <label class="form-label" for="btBank">Ngân hàng <span class="text-danger">*</span></label>
                                <select data-k="bank_id" data-bb class="form-select bank_id" id="btBank"></select>
                                <div class="pm-loi">Chọn ngân hàng.</div>
                            </div>
                            <div class="mb-3 o-nhap">
                                <label class="form-label" for="btSo">Số tài khoản <span class="text-danger">*</span></label>
                                <input type="text" data-k="bank_account" data-bb class="form-control bank_account so" id="btSo" maxlength="50" inputmode="numeric" autocomplete="off" placeholder="VD: 0071001234567">
                                <div class="pm-loi">Nhập số tài khoản.</div>
                                <div class="form-text">Chỉ chữ và số, không khoảng trắng.</div>
                            </div>
                            <div class="mb-1 o-nhap">
                                <label class="form-label" for="btChu">Tên chủ tài khoản <span class="text-danger">*</span></label>
                                <input type="text" data-k="bank_account_name" data-bb class="form-control bank_account_name" id="btChu" maxlength="100" autocomplete="off" placeholder="VD: NGUYEN VAN A">
                                <div class="pm-loi">Nhập tên chủ tài khoản.</div>
                                <div class="form-text">Tự viết hoa, bỏ dấu — đúng như trên app ngân hàng.</div>
                            </div>
                        </div>
                        {{-- Thẻ VietQR như khách thấy. Mã là VietQR THẬT (chuẩn NAPAS 247) dựng từ
                             ngân hàng + số tài khoản đang gõ — quét bằng app ngân hàng, app hiện đúng tên
                             chủ tài khoản là khai đúng. Không kèm số tiền: mỗi đơn một số tiền riêng. --}}
                        <div class="pm-qr-xem" id="btXemTruoc">
                            <div class="pm-qr-nhan"><span>Khách sẽ thấy</span><span class="pm-chip ok xt-md" hidden>Mặc định</span></div>
                            <figure class="vq m-0" aria-label="Xem trước mã VietQR">
                                <div class="vq-dau"><span class="vq-logo" aria-label="VietQR"><b>Viet</b><span>QR</span></span><span class="vq-napas">napas <i>247</i></span></div>
                                <div class="vq-ma">
                                    <span class="vq-goc a"></span><span class="vq-goc b"></span><span class="vq-goc c"></span><span class="vq-goc d"></span>
                                    <div class="xt-qr" role="img" aria-label="Mã QR chuyển khoản"></div>
                                    <div class="vq-trong xt-trong"><i class="fa-solid fa-qrcode"></i><span>Chọn ngân hàng và nhập số tài khoản — mã QR hiện ở đây.</span></div>
                                </div>
                                <div class="vq-nh"><span class="vq-nh-ic xt-nh-ic" hidden></span><b class="xt-bank">Chưa chọn ngân hàng</b></div>
                                <div class="vq-chu xt-chu mo">TÊN CHỦ TÀI KHOẢN</div>
                                <div class="vq-so xt-so mo">Số tài khoản</div>
                                <dl class="vq-ds">
                                    <dt>Số tiền</dt><dd class="xt-tien">Theo từng đơn<small>Mã ở quầy kèm sẵn số tiền</small></dd>
                                    <dt>Nội dung</dt><dd>TT &lt;mã đơn&gt;</dd>
                                </dl>
                                <span class="vq-tat xt-tat" hidden><i class="fa-solid fa-eye-slash me-1"></i>Đang tắt — khách không thấy</span>
                            </figure>
                            <p class="vq-goi xt-goi" hidden><i class="fa-solid fa-mobile-screen-button"></i><span>Mã thật — mở app ngân hàng quét thử: app hiện đúng tên chủ tài khoản là đã khai đúng.</span></p>
                        </div>
                    </div>

                    <div class="pm-muc">Tuỳ chọn</div>
                    <div class="pm-tuy-ds">
                        <label class="pm-tuy" for="btTrangThai">
                            <span><b>Đang bật</b><small>Tắt thì tài khoản không hiện ở quầy và website, nhưng vẫn giữ lịch sử đơn.</small></span>
                            <input type="checkbox" class="switch_customer status_method" id="btTrangThai" checked>
                        </label>
                        <label class="pm-tuy" for="btMacDinh">
                            <span><b>Tài khoản mặc định</b><small>Dùng để in QR ở quầy và hiện cho khách khi đặt trên website.</small></span>
                            <input type="checkbox" class="switch_customer bank_default" id="btMacDinh">
                        </label>
                        <label class="pm-tuy" for="btSuaTien">
                            <span><b>Khách tự gõ số tiền</b><small>QR không kèm số tiền — dùng khi khách hay chuyển khác số trên đơn (trả trước, trả góp).</small></span>
                            <input type="checkbox" data-k="edit_amount" class="switch_customer edit_amount" id="btSuaTien">
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="bt btn_gray" data-bs-dismiss="modal">Huỷ</button>
                    <button type="button" class="bt btn_green save-item">Lưu tài khoản</button>
                </div>
            </div>
        </div>
    </div>

    {{-- Quẹt thẻ --}}
    <div class="modal fade pm-hop" id="modalCrUdSwipeCard" tabindex="-1" aria-hidden="true" aria-labelledby="scTieuDe">
        <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable modal-fullscreen-lg-down">
            <div class="modal-content">
                <div class="modal-header">
                    <span class="pm-ic nho"><img src="{{ asset('v2/images/payment-methods') }}/pm-swipe-card.svg" alt=""></span>
                    <div class="pm-hop-tieude">
                        <h5 class="modal-title tieu-de-hop" id="scTieuDe">Thêm máy quẹt thẻ</h5>
                        <p>Gắn một máy với tài khoản nhận tiền và mức phí thẻ.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Đóng"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" class="id">
                    {{-- Câu mở đầu đi THEO LOẠI MÁY: mPOS tự xác nhận qua NextPay, máy độc lập thì thu ngân xác nhận tay. --}}
                    <div class="pm-ghi mb-3 ghi-mpos"><i class="fa-solid fa-circle-info"></i><span>Thu ngân bấm <b>Gửi xuống máy</b> — số tiền hiện sẵn trên máy, khách quẹt xong NextPay báo về là đơn tự hoàn tất, phí tính theo loại thẻ.</span></div>
                    <div class="pm-ghi mb-3 ghi-doc-lap" hidden><i class="fa-solid fa-circle-info"></i><span>Thu ngân chọn máy ở ô <b>Quẹt thẻ</b> rồi xác nhận tay sau khi máy in biên lai.</span></div>
                    <div class="pm-muc"><span class="pm-so">1</span>Loại máy</div>
                    <div class="pm-chon-ds hai" role="radiogroup" aria-label="Loại máy">
                        <label class="pm-chon"><input type="radio" name="scLoai" value="mpos_device">
                            <span class="pm-chon-ic"><i class="fa-solid fa-mobile-screen-button"></i></span>
                            <span><b>mPOS (NextPay)</b><small>Tiền tự xác nhận qua cổng, phí tính theo loại thẻ.</small></span></label>
                        <label class="pm-chon"><input type="radio" name="scLoai" value="physical_device">
                            <span class="pm-chon-ic"><i class="fa-solid fa-cash-register"></i></span>
                            <span><b>Máy quẹt thẻ độc lập</b><small>Máy POS của ngân hàng — thu ngân bấm xác nhận tay.</small></span></label>
                    </div>

                    <div class="pm-muc"><span class="pm-so">2</span>Kết nối máy</div>
                    <div class="row g-3 payment_keys">
                        <div class="col-md-6 o-nhap"><label class="form-label">Merchant ID <span class="text-danger">*</span></label>
                            <input type="text" data-k="mpos_merchant_id" data-bb class="form-control mpos_merchant_id" autocomplete="off"><div class="pm-loi">Bắt buộc.</div></div>
                        <div class="col-md-6 o-nhap"><label class="form-label">Mobile User (muid) <span class="text-danger">*</span></label>
                            <input type="text" data-k="mpos_muid" data-bb class="form-control mpos_muid" autocomplete="off"><div class="pm-loi">Bắt buộc.</div></div>
                        <div class="col-md-6 o-nhap"><label class="form-label">Secret Key <span class="text-danger">*</span></label>
                            <div class="input-group"><input type="password" data-k="mpos_secret_key" data-bb class="form-control mpos_secret_key" autocomplete="off">
                                <button type="button" class="btn btn-outline-secondary pm-mat" aria-label="Hiện khoá"><i class="fa-regular fa-eye"></i></button></div>
                            <div class="pm-loi">Bắt buộc.</div></div>
                        <div class="col-md-6 o-nhap"><label class="form-label">Máy áp dụng <span class="text-danger">*</span></label>
                            <div class="input-group"><select data-bb class="form-select select_device" data-loai="0"></select>
                                <button type="button" class="btn btn-outline-secondary edit_device" title="Sửa / xoá máy đang chọn" aria-label="Sửa máy đang chọn" disabled><i class="fa-solid fa-pen"></i></button>
                                <button type="button" class="btn btn-outline-primary add_device" title="Thêm máy mới"><i class="fa fa-plus me-1"></i>Máy mới</button></div>
                            <div class="pm-loi">Chọn máy (mỗi máy chỉ gắn một cấu hình).</div></div>
                        <div class="col-12 form-text mt-1">Máy áp dụng: <b>POS ID</b> là số serial in sau máy mPOS — lệnh thu tiền được đẩy tới đúng máy này.</div>
                        <div class="col-12"><div class="pm-url"><span class="pm-url-nhan"><i class="fa-solid fa-link me-1"></i>URL webhook riêng của tiệm — gửi NextPay cài làm địa chỉ báo giao dịch</span>
                            <div class="pm-url-dong"><code class="url-webhook" data-cong="mpos_device"></code><button type="button" class="btn btn-sm btn-outline-secondary pm-chep"><i class="fa-regular fa-copy me-1"></i>Chép</button></div></div></div>
                    </div>
                    <div class="row g-3 fee_physical_swipe" hidden>
                        <div class="col-md-6 o-nhap"><label class="form-label">Máy áp dụng <span class="text-danger">*</span></label>
                            <div class="input-group"><select data-bb class="form-select select_device_physical" data-loai="1"></select>
                                <button type="button" class="btn btn-outline-secondary edit_device" title="Sửa / xoá máy đang chọn" aria-label="Sửa máy đang chọn" disabled><i class="fa-solid fa-pen"></i></button>
                                <button type="button" class="btn btn-outline-primary add_device" title="Thêm máy mới"><i class="fa fa-plus me-1"></i>Máy mới</button></div>
                            <div class="pm-loi">Chọn máy (mỗi máy chỉ gắn một cấu hình).</div></div>
                        <div class="col-md-6 o-nhap"><label class="form-label">Phí quẹt thẻ</label>
                            <div class="input-group"><input type="text" data-k="card_fee_physical" class="form-control input-percent card_fee_physical" placeholder="0.00" inputmode="decimal"><span class="input-group-text">%</span></div>
                            <div class="form-text">Một mức cho mọi loại thẻ, tối đa 10%.</div></div>
                    </div>

                    <div class="pm-muc"><span class="pm-so">3</span>Tài khoản nhận tiền quẹt thẻ</div>
                    <div class="row g-3 the-ngan-hang">
                        <div class="col-md-4 o-nhap"><label class="form-label">Ngân hàng <span class="text-danger">*</span></label>
                            <select data-k="bank_id" data-bb class="form-select bank_id"></select><div class="pm-loi">Chọn ngân hàng.</div></div>
                        <div class="col-md-4 o-nhap"><label class="form-label">Số tài khoản <span class="text-danger">*</span></label>
                            <input type="text" data-k="bank_account" data-bb class="form-control bank_account so" maxlength="50" autocomplete="off"><div class="pm-loi">Nhập số tài khoản.</div></div>
                        <div class="col-md-4 o-nhap"><label class="form-label">Tên chủ tài khoản <span class="text-danger">*</span></label>
                            <input type="text" data-k="bank_account_name" data-bb class="form-control bank_account_name" maxlength="100" autocomplete="off"><div class="pm-loi">Nhập tên chủ tài khoản.</div></div>
                    </div>

                    <div class="payment_keys pm-phi">
                        <div class="pm-muc"><span class="pm-so">4</span>Phí quẹt thẻ<small>% trên số tiền quẹt, tối đa 10%. Cổng báo loại thẻ nào thì lấy mức đó.</small></div>
                        <div class="pm-phi-luoi">
                            <label><span><b>Thẻ ATM / NAPAS</b><small>DOMESTIC</small></span><span class="input-group"><input type="text" data-k="card_fee_local" class="form-control input-percent card_fee_local" placeholder="0.55" inputmode="decimal"><span class="input-group-text">%</span></span></label>
                            <label><span><b>Visa/Master/JCB/CUP trong nước</b><small>VISA_LOCAL, MASTER_LOCAL…</small></span><span class="input-group"><input type="text" data-k="card_fee_domestic" class="form-control input-percent card_fee_domestic" placeholder="1.80" inputmode="decimal"><span class="input-group-text">%</span></span></label>
                            <label><span><b>Visa/Master/JCB/CUP nước ngoài</b><small>VISA, MASTER, JCB, CUP</small></span><span class="input-group"><input type="text" data-k="card_fee_foreign" class="form-control input-percent card_fee_foreign" placeholder="2.40" inputmode="decimal"><span class="input-group-text">%</span></span></label>
                            <label><span><b>Thanh toán thẻ từ xa</b><small>Qua link thanh toán</small></span><span class="input-group"><input type="text" data-k="card_fee_remote" class="form-control input-percent card_fee_remote" placeholder="2.20" inputmode="decimal"><span class="input-group-text">%</span></span></label>
                            <label><span><b>QR quốc tế</b><small>mVisa, MasterPass, JCBQR, UPIQR</small></span><span class="input-group"><input type="text" data-k="card_fee_mvisa" class="form-control input-percent card_fee_mvisa" placeholder="1.80" inputmode="decimal"><span class="input-group-text">%</span></span></label>
                            <label><span><b>QR-Pay, ViettelPay, VinID</b><small>ZALOQPAY, VIETTELQRPAY…</small></span><span class="input-group"><input type="text" data-k="card_fee_qrpay" class="form-control input-percent card_fee_qrpay" placeholder="0.88" inputmode="decimal"><span class="input-group-text">%</span></span></label>
                            <label><span><b>Ví VIMO</b><small>DXNEXTPAY</small></span><span class="input-group"><input type="text" data-k="card_fee_vimo" class="form-control input-percent card_fee_vimo" placeholder="0.22" inputmode="decimal"><span class="input-group-text">%</span></span></label>
                            <label><span><b>Ví MoMo</b><small>Theo hệ thống cổng</small></span><span class="input-group"><input type="text" data-k="card_fee_momo" class="form-control input-percent card_fee_momo" placeholder="1.50" inputmode="decimal"><span class="input-group-text">%</span></span></label>
                        </div>
                    </div>

                    <div class="pm-tuy-ds mt-3">
                        <label class="pm-tuy" for="scTrangThai">
                            <span><b>Đang bật</b><small>Tắt thì thu ngân không chọn được máy này khi bấm Quẹt thẻ.</small></span>
                            <input type="checkbox" class="switch_customer status_method" id="scTrangThai" checked>
                        </label>
                    </div>
                    <div class="pm-kq mt-3" role="status" aria-live="polite" hidden></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="bt btn_gray" data-bs-dismiss="modal">Huỷ</button>
                    <button type="button" class="bt btn_blue pm-kiem-tra" hidden><i class="fa-solid fa-plug-circle-check me-1"></i>Kiểm tra kết nối</button>
                    <button type="button" class="bt btn_green save-item">Lưu máy</button>
                </div>
            </div>
        </div>
    </div>

    {{-- QR tự động --}}
    <div class="modal fade pm-hop" id="modalQR" tabindex="-1" aria-hidden="true" aria-labelledby="qrTieuDe">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down">
            <div class="modal-content">
                <div class="modal-header">
                    <span class="pm-ic nho"><img src="{{ asset('v2/images/payment-methods') }}/pm-qr.svg" alt=""></span>
                    <div class="pm-hop-tieude">
                        <h5 class="modal-title tieu-de-hop" id="qrTieuDe">Thêm cổng QR tự động</h5>
                        <p>Tiền về là đơn tự chuyển “đã thanh toán”. Nội dung chuyển khoản luôn là mã đơn.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Đóng"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" class="id">
                    <div class="pm-muc"><span class="pm-so">1</span>Chọn cổng</div>
                    <div class="pm-chon-ds bon o-nhap" role="radiogroup" aria-label="Cổng QR">
                        <label class="pm-chon"><input type="radio" name="qrLoai" value="sepay">
                            <span><b>SePay</b><small>Tiền vào thẳng tài khoản cửa hàng</small></span><em class="pm-da-co">Đã thêm</em></label>
                        <label class="pm-chon"><input type="radio" name="qrLoai" value="payos">
                            <span><b>PayOS</b><small>Cổng trung gian, đối soát tự động</small></span><em class="pm-da-co">Đã thêm</em></label>
                        <label class="pm-chon"><input type="radio" name="qrLoai" value="mpos_qr">
                            <span><b>mPos QR</b><small>QR của NextPay</small></span><em class="pm-da-co">Đã thêm</em></label>
                        <label class="pm-chon"><input type="radio" name="qrLoai" value="momo">
                            <span><b>Ví MoMo</b><small>QR thanh toán MoMo</small></span><em class="pm-da-co">Đã thêm</em></label>
                        <div class="pm-loi w-100">Chi nhánh đã có cổng này — sửa dòng đang có thay vì tạo thêm.</div>
                    </div>

                    <div class="pm-muc"><span class="pm-so">2</span>Thông tin kết nối</div>
                    <div class="row g-3 qr-o" data-qr="sepay">
                        <div class="col-12"><ol class="pm-buoc">
                            <li>Khai tài khoản nhận tiền và một <b>API key</b> (bấm <i>Tạo khoá</i>) rồi bấm <b>Lưu cổng</b>.</li>
                            <li>Vào <b>my.sepay.vn → Webhooks → Thêm webhook</b>: dán URL riêng bên dưới, kiểu chứng thực <b>API Key</b>, dán đúng khoá vừa tạo.</li>
                            <li>Bấm <b>Gửi thử</b> bên SePay, rồi bấm <b>Kiểm tra kết nối</b> ở đây.</li>
                        </ol></div>
                        <div class="col-md-6 o-nhap"><label class="form-label">Ngân hàng <span class="text-danger">*</span></label>
                            <select data-k="bank_id" data-bb class="form-select bank_id"></select><div class="pm-loi">Bắt buộc.</div></div>
                        <div class="col-md-6 o-nhap"><label class="form-label">Số tài khoản nhận <span class="text-danger">*</span></label>
                            <input type="text" data-k="sepay_account" data-bb class="form-control sepay_account so" autocomplete="off"><div class="pm-loi">Bắt buộc.</div></div>
                        <div class="col-md-6 o-nhap"><label class="form-label">Tên chủ tài khoản <span class="text-danger">*</span></label>
                            <input type="text" data-k="bank_account_name" data-bb class="form-control bank_account_name" autocomplete="off"><div class="pm-loi">Bắt buộc.</div></div>
                        <div class="col-md-6 o-nhap"><label class="form-label">API key webhook <span class="text-danger">*</span></label>
                            <div class="input-group"><input type="password" data-k="sepay_api_key" class="form-control sepay_api_key" autocomplete="off">
                                <button type="button" class="btn btn-outline-secondary pm-mat" aria-label="Hiện khoá"><i class="fa-regular fa-eye"></i></button>
                                <button type="button" class="btn btn-outline-primary pm-sinh-khoa">Tạo khoá</button></div>
                            <div class="pm-loi">Bắt buộc.</div>
                            <div class="form-text">Chuỗi bí mật khai GIỐNG HỆT ở hai nơi: đây và ô API Key của webhook bên SePay.</div></div>
                        <div class="col-12 o-nhap"><label class="form-label">API token <small class="text-muted fw-normal">(không bắt buộc)</small></label>
                            <div class="input-group"><input type="password" data-k="sepay_api_token" class="form-control sepay_api_token" autocomplete="off">
                                <button type="button" class="btn btn-outline-secondary pm-mat" aria-label="Hiện khoá"><i class="fa-regular fa-eye"></i></button></div>
                            <div class="pm-loi"></div>
                            <div class="form-text">Lấy ở SePay → Cấu hình → API Access. Có token thì hệ thống tự dò sao kê khi webhook đến chậm.</div></div>
                        <div class="col-12"><div class="pm-url"><span class="pm-url-nhan"><i class="fa-solid fa-link me-1"></i>URL webhook riêng của tiệm — dán vào SePay → Webhooks</span>
                            <div class="pm-url-dong"><code class="url-webhook" data-cong="sepay"></code><button type="button" class="btn btn-sm btn-outline-secondary pm-chep"><i class="fa-regular fa-copy me-1"></i>Chép</button></div></div></div>
                    </div>
                    <div class="row g-3 qr-o" data-qr="payos" hidden>
                        <div class="col-12"><ol class="pm-buoc">
                            <li>Vào <b>my.payos.vn → Kênh thanh toán</b> của tiệm, chép <b>Client ID, API Key, Checksum Key</b> dán vào đây, bấm <b>Lưu cổng</b>.</li>
                            <li>Bấm <b>Kiểm tra kết nối</b> — PayOS kiểm khoá và <b>tự đăng ký URL webhook</b> bên dưới, không phải dán tay.</li>
                        </ol>
                        <div class="pm-ghi mt-2"><i class="fa-solid fa-circle-info"></i><span>Để trống cả ba khoá thì cổng dùng tài khoản PayOS chung của máy chủ (nếu có).</span></div></div>
                        <div class="col-md-6 o-nhap"><label class="form-label">Client ID</label>
                            <input type="text" data-k="payos_client_id" class="form-control payos_client_id" autocomplete="off"><div class="pm-loi">Bắt buộc.</div></div>
                        <div class="col-md-6 o-nhap"><label class="form-label">API Key</label>
                            <div class="input-group"><input type="password" data-k="payos_api_key" class="form-control payos_api_key" autocomplete="off">
                                <button type="button" class="btn btn-outline-secondary pm-mat" aria-label="Hiện khoá"><i class="fa-regular fa-eye"></i></button></div>
                            <div class="pm-loi">Bắt buộc.</div></div>
                        <div class="col-12 o-nhap"><label class="form-label">Checksum Key</label>
                            <div class="input-group"><input type="password" data-k="payos_checksum_key" class="form-control payos_checksum_key" autocomplete="off">
                                <button type="button" class="btn btn-outline-secondary pm-mat" aria-label="Hiện khoá"><i class="fa-regular fa-eye"></i></button></div>
                            <div class="pm-loi">Bắt buộc.</div></div>
                        <div class="col-12"><div class="pm-url"><span class="pm-url-nhan"><i class="fa-solid fa-link me-1"></i>URL webhook riêng của tiệm — PayOS tự đăng ký khi bấm Kiểm tra kết nối</span>
                            <div class="pm-url-dong"><code class="url-webhook" data-cong="payos"></code><button type="button" class="btn btn-sm btn-outline-secondary pm-chep"><i class="fa-regular fa-copy me-1"></i>Chép</button></div></div></div>
                    </div>
                    <div class="row g-3 qr-o" data-qr="mpos_qr" hidden>
                        <div class="col-12"><ol class="pm-buoc">
                            <li>Lấy <b>Merchant ID, Mobile User (muid), Secret Key</b> NextPay cấp cho tiệm, dán vào đây, bấm <b>Lưu cổng</b>.</li>
                            <li>Gửi <b>URL webhook</b> bên dưới cho NextPay cài, rồi bấm <b>Kiểm tra kết nối</b> — hệ thống tạo thử rồi huỷ ngay một mã QR 3.000đ.</li>
                        </ol>
                        <div class="pm-ghi mt-2"><i class="fa-solid fa-circle-info"></i><span>Cùng một Merchant thì máy mPOS và mPos QR báo về chung một URL — chỉ cần cài một lần.</span></div></div>
                        <div class="col-md-6 o-nhap"><label class="form-label">Merchant ID <span class="text-danger">*</span></label>
                            <input type="text" data-k="mpos_merchant_id" data-bb class="form-control mpos_merchant_id" autocomplete="off"><div class="pm-loi">Bắt buộc.</div></div>
                        <div class="col-md-6 o-nhap"><label class="form-label">Mobile User (muid) <span class="text-danger">*</span></label>
                            <input type="text" data-k="mpos_muid" data-bb class="form-control mpos_muid" autocomplete="off"><div class="pm-loi">Bắt buộc.</div></div>
                        <div class="col-md-6 o-nhap"><label class="form-label">Secret Key <span class="text-danger">*</span></label>
                            <div class="input-group"><input type="password" data-k="mpos_secret_key" data-bb class="form-control mpos_secret_key" autocomplete="off">
                                <button type="button" class="btn btn-outline-secondary pm-mat" aria-label="Hiện khoá"><i class="fa-regular fa-eye"></i></button></div>
                            <div class="pm-loi">Bắt buộc.</div></div>
                        <div class="col-md-6 o-nhap"><label class="form-label">Kiểu nhận tiền <span class="text-danger">*</span></label>
                            <select data-k="mpos_type" data-bb class="form-select mpos_type"><option value="VAQR">Nhận gián tiếp — VAQR</option><option value="VAMC">Nhận trực tiếp — VAMC</option></select></div>
                        <div class="col-12"><div class="pm-url"><span class="pm-url-nhan"><i class="fa-solid fa-link me-1"></i>URL webhook riêng của tiệm — gửi NextPay cài làm địa chỉ báo giao dịch</span>
                            <div class="pm-url-dong"><code class="url-webhook" data-cong="mpos_qr"></code><button type="button" class="btn btn-sm btn-outline-secondary pm-chep"><i class="fa-regular fa-copy me-1"></i>Chép</button></div></div></div>
                    </div>
                    <div class="row g-3 qr-o" data-qr="momo" hidden>
                        <div class="col-12"><div class="pm-ghi"><i class="fa-solid fa-circle-info"></i><span>Chưa nối cổng MoMo — cấu hình được lưu (khoá đã mã hoá) để dùng khi tích hợp, quầy chưa tạo được QR từ cổng này.</span></div></div>
                        <div class="col-md-6 o-nhap"><label class="form-label">Partner Code <span class="text-danger">*</span></label>
                            <input type="text" data-k="momo_partner_code" data-bb class="form-control momo_partner_code" autocomplete="off"><div class="pm-loi">Bắt buộc.</div></div>
                        <div class="col-md-6 o-nhap"><label class="form-label">Access Key <span class="text-danger">*</span></label>
                            <input type="text" data-k="momo_access_key" data-bb class="form-control momo_access_key" autocomplete="off"><div class="pm-loi">Bắt buộc.</div></div>
                        <div class="col-12 o-nhap"><label class="form-label">Secret Key <span class="text-danger">*</span></label>
                            <div class="input-group"><input type="password" data-k="momo_secret_key" data-bb class="form-control momo_secret_key" autocomplete="off">
                                <button type="button" class="btn btn-outline-secondary pm-mat" aria-label="Hiện khoá"><i class="fa-regular fa-eye"></i></button></div>
                            <div class="pm-loi">Bắt buộc.</div></div>
                    </div>

                    <div class="pm-tuy-ds mt-3">
                        <label class="pm-tuy" for="qrTrangThai">
                            <span><b>Đang bật</b><small>Tắt thì cổng này không tạo QR ở quầy và website.</small></span>
                            <input type="checkbox" class="switch_customer status_method" id="qrTrangThai" checked>
                        </label>
                    </div>
                    <div class="pm-kq mt-3" role="status" aria-live="polite" hidden></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="bt btn_gray" data-bs-dismiss="modal">Đóng</button>
                    <button type="button" class="bt btn_blue pm-kiem-tra" hidden><i class="fa-solid fa-plug-circle-check me-1"></i>Kiểm tra kết nối</button>
                    <button type="button" class="bt btn_green save-item">Lưu cổng</button>
                </div>
            </div>
        </div>
    </div>

    {{-- Thêm máy (mở chồng lên hộp quẹt thẻ) --}}
    <div class="modal fade pm-hop" id="modalCrudDevice" tabindex="-1" aria-hidden="true" aria-labelledby="dvTieuDe" style="z-index: 1065">
        <div class="modal-dialog modal-dialog-centered modal-fullscreen-sm-down">
            <div class="modal-content">
                <div class="modal-header">
                    <span class="pm-ic nho"><i class="fa-solid fa-cash-register"></i></span>
                    <div class="pm-hop-tieude">
                        <h5 class="modal-title" id="dvTieuDe">Thêm máy</h5>
                        <p>Máy thuộc <b>{{ $tenChiNhanh ?: 'chi nhánh đang làm' }}</b>.</p>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Đóng"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" class="id">
                    <div class="mb-3 o-nhap"><label class="form-label">POS ID <span class="text-danger">*</span></label>
                        <input type="text" data-bb class="form-control code so" autocomplete="off" placeholder="Số serial in dưới đáy máy"><div class="pm-loi">Bắt buộc.</div></div>
                    <div class="mb-3 o-nhap"><label class="form-label">Tên máy <span class="text-danger">*</span></label>
                        <input type="text" data-bb class="form-control name" autocomplete="off" placeholder="VD: Máy quầy 1"><div class="pm-loi">Bắt buộc.</div></div>
                    <div><label class="form-label">Ghi chú</label>
                        <textarea class="form-control description" rows="2" placeholder="Không bắt buộc"></textarea></div>
                </div>
                <div class="pm-ghi mx-3 mb-2 dv-dang-gan" hidden><i class="fa-solid fa-link"></i><span>Máy đang gắn với một cấu hình quẹt thẻ nên không xoá được — sửa POS ID, tên vẫn được.</span></div>
                <div class="modal-footer dv-chan">
                    <button type="button" class="bt btn_red me-auto dv-xoa" hidden><i class="fa-solid fa-trash-can me-1"></i>Xoá máy</button>
                    <button type="button" class="bt btn_gray" data-bs-dismiss="modal">Huỷ</button>
                    <button type="button" class="bt btn_green save-item">Thêm máy</button>
                </div>
                <div class="modal-footer dv-hoi-xoa" hidden>
                    <span class="me-auto">Xoá máy <b class="dv-ten-xoa"></b>? Không hoàn tác được.</span>
                    <button type="button" class="bt btn_gray dv-thoi">Thôi</button>
                    <button type="button" class="bt btn_red dv-xoa-that">Xoá</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade pm-hop pm-hop-xoa" id="deleteItem" tabindex="-1" aria-hidden="true" aria-labelledby="xoaTieuDe">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content">
                <div class="modal-body text-center">
                    <span class="pm-xoa-ic"><i class="fa-solid fa-trash-can"></i></span>
                    <h5 class="mt-3 mb-1" id="xoaTieuDe">Xoá?</h5>
                    <p class="cau-hoi mb-1"></p>
                    <small class="text-muted">Không hoàn tác được.</small>
                </div>
                <div class="modal-footer">
                    <button type="button" class="bt btn_gray" data-bs-dismiss="modal">Huỷ</button>
                    <button type="button" class="bt btn_red delete-value">Xoá</button>
                </div>
            </div>
        </div>
    </div>

    <script type="application/json" id="pmData">@json($data)</script>
@endsection

@push('scripts')
    <script src="https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.min.js"></script>
    <script>
    (function () {
        var D = JSON.parse(document.getElementById('pmData').textContent || 'null');
        var URL = {
            data: @json(route('admin.phuong-thuc-tt.data')),
            goc: @json(url('/admin/payment-methods')),
            may: @json(route('admin.phuong-thuc-tt.taoMay')),
            mayGoc: @json(url('/admin/payment-devices')),
            webhook: @json(config('api.public_url')),
        };
        var ANH = @json(asset('v2/images/payment-methods')) + '/';
        var LOAI = {
            1: { ten: 'Tiền mặt', anh: 'pm-cash.svg', noi: 'Chỉ ở quầy', don: '' },
            4: { ten: 'Chuyển khoản', anh: 'pm-transfer.svg', noi: 'Quầy và website', don: 'tài khoản' },
            5: { ten: 'Quẹt thẻ', anh: 'pm-swipe-card.svg', noi: 'Chỉ ở quầy', don: 'máy' },
            6: { ten: 'QR tự động', anh: 'pm-qr.svg', noi: 'Quầy và website', don: 'cổng' },
        };
        var TEN_QR = { sepay: 'SePay', payos: 'PayOS', mpos_qr: 'mPos QR', momo: 'Ví MoMo' };
        // Cổng có URL webhook riêng + nút Kiểm tra (MoMo chưa nối).
        function coWebhook(loai) { return ['sepay', 'payos', 'mpos_qr', 'mpos_device'].indexOf(loai) >= 0; }
        var dangMo = 4;

        var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
        var bao = function (ok, msg) { if (msg) toastr[ok ? 'success' : 'error'](msg); };
        var sua = function () { return !!(D && D.can_edit); };
        var pt = function (type) { return (D.methods || []).filter(function (m) { return m.type === type; })[0]; };
        var cua = function (type) { var m = pt(type); return m ? m.details : []; };
        var dangBat = function (type) { return cua(type).filter(function (x) { return x.status; }); };
        var tenNH = function (x) { return (x.data && x.data.bank_name) || 'Không xác định'; };
        var tenMay = function (x) { var d = (D.devices || []).filter(function (v) { return v.id === x.device_id; })[0]; return d ? d.code + ' · ' + d.name : '—'; };
        var laWeb = function () { return D.shop_id && D.shop_id === D.web_shop_id; };

        var lucDoc = function (iso) {
            var d = new Date(iso);
            if (isNaN(d)) return iso;
            var h = function (n) { return (n < 10 ? '0' : '') + n; };
            return h(d.getHours()) + ':' + h(d.getMinutes()) + ' ' + h(d.getDate()) + '/' + h(d.getMonth() + 1);
        };

        // ---------- Gọi máy chủ ----------
        function goi(method, url, body) {
            // Gắn chi nhánh CỦA TAB vào từng lượt gọi: header X-Chi-Nhanh-Tab của
            // khung v2 được dựng trước khi tab biết mình ở chi nhánh nào, nên dựa
            // vào nó là ghi nhầm sang chi nhánh trong phiên (đã gặp khi chạy thử).
            if (window.V2 && V2.themChiNhanh) url = V2.themChiNhanh(url);
            return $.ajax({ url: url, method: method, data: body ? JSON.stringify(body) : undefined, contentType: 'application/json', dataType: 'json' });
        }
        function napLai() {
            return goi('GET', URL.data).then(function (r) { D = r.data; ve(); });
        }
        // Lượt ghi: thành công thì báo + nạp lại; hỏng thì báo câu của API và vẽ lại
        // từ dữ liệu cũ (công tắc vừa bấm tự về chỗ cũ).
        function ghi(method, url, body, $hop) {
            return goi(method, url, body).then(function (r) {
                bao(true, r.message);
                return napLai().then(function () { return true; });
            }, function (x) {
                var j = x.responseJSON || {};
                if ($hop && j.errors) toLoi($hop, j.errors);
                bao(false, j.message || 'Không lưu được. Vui lòng thử lại.');
                ve();
                return false;
            });
        }

        // ---------- Cột trái ----------
        function dongDem(m) {
            var bat = dangBat(m.type).length, tong = cua(m.type).length, don = LOAI[m.type].don;
            if (m.type === 1) return { cau: 'Luôn nhận', lop: 'ok' };
            if (!tong) return { cau: 'Chưa có ' + don + ' nào', lop: 'canh' };
            if (!m.status) return { cau: 'Đang tắt · ' + tong + ' ' + don, lop: 'tat' };
            return { cau: bat + '/' + tong + ' ' + don + ' đang bật', lop: 'ok' };
        }
        function veThe() {
            $('#pmThe').html((D.methods || []).map(function (m) {
                var L = LOAI[m.type], dem = dongDem(m), chon = m.type === dangMo;
                var sw = m.type === 1
                    ? '<span class="pm-luon" title="Tiền mặt luôn nhận ở quầy">Luôn bật</span>'
                    : '<input type="checkbox" class="switch_customer status_method" data-id="' + m.id + '"' + (m.status ? ' checked' : '') + (sua() ? '' : ' disabled') + ' aria-label="Nhận ' + L.ten + '">';
                var md = m.is_default ? '<span class="pm-chip md" title="Mở sẵn khi thanh toán ở quầy"><i class="fa-solid fa-star"></i>Mặc định</span>' : '';
                return '<div class="pm-tile' + (chon ? ' active' : '') + (m.type !== 1 && !m.status ? ' tat' : '') + '" role="tab" tabindex="0" aria-selected="' + chon + '" data-type="' + m.type + '">' +
                    '<span class="pm-ic"><img src="' + ANH + L.anh + '" alt=""></span>' +
                    '<span class="pm-tile-txt"><span class="pm-tile-ten">' + L.ten + md + '</span>' +
                    '<span class="pm-tile-noi">' + L.noi + '</span>' +
                    '<span class="pm-tile-dem ' + dem.lop + '">' + dem.cau + '</span></span>' +
                    '<span class="pm-tile-phai">' + sw + '</span></div>';
            }).join(''));
        }

        // ---------- Cột phải ----------
        function nutTao(type, nhan) {
            return sua() ? '<button type="button" class="bt btn_green btn-tao" data-type="' + type + '"><i class="fa fa-plus me-1"></i>' + nhan + '</button>' : '';
        }
        function nutSuaXoa(x) {
            if (!sua()) return '';
            return '<a class="edit_bt edit-item" data-id="' + x.id + '" title="Sửa" aria-label="Sửa" role="button"><i class="fa fa-edit"></i></a>' +
                '<a class="dele_bt delete-item" data-id="' + x.id + '" title="Xoá" aria-label="Xoá" role="button"><i class="fa fa-times"></i></a>';
        }
        function congDong(x) {
            return '<input type="checkbox" class="switch_customer item-status" data-id="' + x.id + '"' + (x.status ? ' checked' : '') + (sua() ? '' : ' disabled') + ' aria-label="Trạng thái">';
        }
        function oMacDinh(x) {
            if (x.is_default) return '<span class="pm-chip md"><i class="fa-solid fa-star"></i>Mặc định</span>';
            if (!x.status || !sua()) return '<span class="text-muted pm-gach">—</span>';
            return '<button type="button" class="pm-dat-md dat-md-dong" data-id="' + x.id + '">Đặt mặc định</button>';
        }
        function rong(icon, cau, type, nhan) {
            return '<div class="pm-rong"><span class="pm-rong-ic"><i class="fa-solid ' + icon + '"></i></span><p>' + cau + '</p>' + nutTao(type, nhan) + '</div>';
        }
        function dauPanel(m, mo, nut) {
            var L = LOAI[m.type], md = '';
            if (m.is_default) md = '<span class="pm-chip md"><i class="fa-solid fa-star"></i>Mặc định ở quầy</span>';
            else if (m.status && sua()) md = '<button type="button" class="pm-dat-md dat-md-pt" data-id="' + m.id + '"><i class="fa-regular fa-star me-1"></i>Đặt mặc định ở quầy</button>';
            return '<div class="pm-panel-dau"><span class="pm-ic nho"><img src="' + ANH + L.anh + '" alt=""></span>' +
                '<div class="pm-panel-tieude"><h2>' + L.ten + '</h2><p>' + mo + '</p></div><div class="pm-panel-nut">' + md + (nut || '') + '</div></div>';
        }
        function bang(cot, dong, the) {
            return '<div class="table-responsive d-none d-xl-block"><table class="pm-table"><thead><tr>' +
                cot.map(function (c) { return '<th' + (c[1] ? ' class="' + c[1] + '"' : '') + '>' + c[0] + '</th>'; }).join('') +
                '</tr></thead><tbody>' + dong + '</tbody></table></div><div class="pm-the-list d-xl-none">' + the + '</div>';
        }
        function hang(x, o) { return '<tr class="' + (x.status ? '' : 'tat') + '" data-id="' + x.id + '">' + o + '</tr>'; }
        function the(x, tren, giua) {
            return '<div class="pm-the' + (x.status ? '' : ' tat') + '" data-id="' + x.id + '"><div class="pm-the-tren"><b>' + tren + '</b>' + (dangMo !== 6 ? oMacDinh(x) : '') + '</div>' + giua +
                '<div class="pm-the-duoi">' + congDong(x) + '<span class="action">' + nutSuaXoa(x) + '</span></div></div>';
        }

        function veChiTiet() {
            var t = dangMo, m = pt(t), ds = cua(t), html = '';
            if (!m) return;
            if (t === 1) {
                html = dauPanel(m, 'Không có gì cần cấu hình — quầy luôn nhận tiền mặt.') +
                    '<div class="pm-than"><ul class="pm-y">' +
                    '<li><i class="fa-solid fa-star"></i><span><b>Đặt mặc định</b> thì hộp Thanh toán ở quầy mở sẵn vào Tiền mặt.</span></li>' +
                    '<li><i class="fa-solid fa-rotate-left"></i><span>Tắt hình thức đang là mặc định thì mặc định tự quay về Tiền mặt.</span></li></ul></div>';
            } else if (t === 4) {
                var macDinh = ds.filter(function (x) { return x.is_default; })[0];
                html = dauPanel(m, 'Nội dung chuyển khoản luôn là mã đơn để đối chiếu.', ds.length ? nutTao(4, 'Thêm tài khoản') : '');
                if (macDinh) {
                    html += '<div class="pm-tom"><i class="fa-solid fa-qrcode"></i><span>' + (laWeb() ? 'QR ở quầy và trên website dùng ' : 'QR ở quầy chi nhánh này dùng ') +
                        '<b>' + esc(tenNH(macDinh)) + ' · <span class="so">' + esc(macDinh.data.bank_account) + '</span> · ' + esc(macDinh.data.bank_account_name) + '</b>' +
                        (laWeb() || !m.status ? '' : '. Website dùng tài khoản của chi nhánh trung tâm.') + '</span></div>';
                }
                if (ds.some(function (x) { return !x.data.bank_id; })) {
                    html += '<div class="pm-note">Có tài khoản chuyển từ trang Cài đặt cũ chưa nhận ra ngân hàng — bấm <i class="fa fa-edit"></i> để chọn lại, nếu không QR sẽ không dựng được.</div>';
                }
                html += ds.length ? bang(
                    [['Tài khoản'], ['Chủ tài khoản'], ['Số tiền trên QR'], ['Trạng thái', 'text-center'], ['Mặc định', 'text-center'], ['', 'text-end']],
                    ds.map(function (x) {
                        return hang(x, '<td><div class="pm-o2"><b>' + esc(tenNH(x)) + '</b><span class="so">' + esc(x.data.bank_account) + '</span></div></td>' +
                            '<td>' + esc(x.data.bank_account_name) + '</td><td>' + (x.data.edit_amount === '1' ? 'Khách tự gõ' : 'Kèm sẵn') + '</td>' +
                            '<td class="text-center giu">' + congDong(x) + '</td><td class="text-center giu">' + oMacDinh(x) + '</td><td class="text-end action giu">' + nutSuaXoa(x) + '</td>');
                    }).join(''),
                    ds.map(function (x) { return the(x, esc(tenNH(x)), '<div class="so">' + esc(x.data.bank_account) + '</div><div class="pm-the-phu">' + esc(x.data.bank_account_name) + '</div>'); }).join('')
                ) : rong('fa-building-columns', 'Chưa có tài khoản nào. Thêm một tài khoản rồi bật Chuyển khoản ở cột bên trái.', 4, 'Thêm tài khoản');
            } else if (t === 5) {
                var phi = function (x) { return x.detail_type === 'mpos_device' ? '8 mức theo loại thẻ' : (x.data.card_fee_physical || '0') + '%'; };
                var loai = function (x) { return '<span class="pm-chip ' + (x.detail_type === 'mpos_device' ? 'mpos">mPOS' : 'doc-lap">Độc lập') + '</span>'; };
                html = dauPanel(m, 'Máy mặc định được chọn sẵn khi thu ngân bấm Quẹt thẻ.', ds.length ? nutTao(5, 'Thêm máy') : '');
                html += ds.length ? bang(
                    [['Máy'], ['Tài khoản nhận'], ['Phí thẻ'], ['Trạng thái', 'text-center'], ['Mặc định', 'text-center'], ['', 'text-end']],
                    ds.map(function (x) {
                        return hang(x, '<td><div class="pm-o2"><b>' + esc(tenMay(x)) + '</b><span>' + loai(x) + '</span></div></td>' +
                            '<td><div class="pm-o2"><span>' + esc(tenNH(x)) + '</span><span class="so">' + esc(x.data.bank_account) + '</span></div></td><td>' + phi(x) + '</td>' +
                            '<td class="text-center giu">' + congDong(x) + '</td><td class="text-center giu">' + oMacDinh(x) + '</td><td class="text-end action giu">' + nutSuaXoa(x) + '</td>');
                    }).join(''),
                    ds.map(function (x) { return the(x, esc(tenMay(x)), '<div class="pm-the-phu">' + loai(x) + ' Phí ' + phi(x) + '</div>'); }).join('')
                ) : rong('fa-credit-card', 'Chưa có máy quẹt thẻ nào.', 5, 'Thêm máy');
            } else if (t === 6) {
                var ketNoi = function (x) {
                    if (!coWebhook(x.detail_type)) return '<span class="pm-chip chung">Chưa nối cổng</span>';
                    if (x.data.last_webhook_at) return '<span class="pm-chip ok" title="Lần cổng báo tiền gần nhất"><i class="fa-solid fa-circle-check"></i>Đã thông · ' + esc(lucDoc(x.data.last_webhook_at)) + '</span>';
                    var k = x.saved_secrets || {};
                    if (x.detail_type === 'mpos_qr') return '<span class="pm-chip cho">Chưa nhận báo tiền</span>';
                    var rieng = x.detail_type === 'payos' ? k.payos_api_key : (k.sepay_api_key || k.sepay_api_token);
                    return rieng ? '<span class="pm-chip cho">Chưa nhận báo tiền</span>' : '<span class="pm-chip chung">Khoá chung của máy chủ</span>';
                };
                var tk = function (x) {
                    if (x.detail_type === 'sepay') return x.data.sepay_account ? tenNH(x) + ' · ' + x.data.sepay_account : 'Khoá chung của máy chủ';
                    if (x.detail_type === 'payos') return x.data.payos_client_id ? 'Client ' + x.data.payos_client_id : 'Khoá chung của máy chủ';
                    return x.data.mpos_merchant_id || x.data.momo_partner_code || '';
                };
                html = dauPanel(m, 'Khách quét QR, tiền về là đơn tự chuyển “đã thanh toán” — không phải soi sao kê.', ds.length ? nutTao(6, 'Thêm cổng') : '');
                html += ds.length ? bang(
                    [['Cổng'], ['Tài khoản / Merchant'], ['Kết nối'], ['Trạng thái', 'text-center'], ['', 'text-end']],
                    ds.map(function (x) {
                        return hang(x, '<td><b>' + TEN_QR[x.detail_type] + '</b></td><td class="so">' + esc(tk(x)) + '</td><td class="giu">' + ketNoi(x) + '</td><td class="text-center giu">' + congDong(x) + '</td><td class="text-end action giu">' + nutSuaXoa(x) + '</td>');
                    }).join(''),
                    ds.map(function (x) { return the(x, TEN_QR[x.detail_type], '<div class="so">' + esc(tk(x)) + '</div><div class="mt-1">' + ketNoi(x) + '</div>'); }).join('')
                ) : rong('fa-qrcode', 'Chưa khai cổng QR nào.', 6, 'Thêm cổng');
            }
            if (!sua()) html += '<div class="pm-note"><i class="fa-solid fa-lock me-1"></i>Bạn chỉ có quyền xem. Cần quyền <b>Sửa phương thức thanh toán</b> để thay đổi.</div>';
            $('#pmChiTiet').html(html).attr('aria-label', LOAI[t].ten);
        }
        function ve() { if (D) { veThe(); veChiTiet(); } }

        // ---------- Chọn phương thức ----------
        function mo(type) { dangMo = type; ve(); if (window.innerWidth < 992) document.getElementById('pmChiTiet').scrollIntoView({ behavior: 'smooth', block: 'start' }); }
        $(document).on('click', '.pm-tile', function (e) {
            if ($(e.target).is('input, button, label')) return;
            mo(+$(this).data('type'));
        }).on('keydown', '.pm-tile', function (e) {
            if ((e.key === 'Enter' || e.key === ' ') && !$(e.target).is('input, button')) { e.preventDefault(); mo(+$(this).data('type')); $('.pm-tile.active').trigger('focus'); }
        });

        // ---------- Bật tắt / mặc định ----------
        $(document).on('change', '.pm-tile .status_method', function () {
            var id = +$(this).data('id'), on = this.checked, m = (D.methods || []).filter(function (x) { return x.id === id; })[0];
            if (m && on && !cua(m.type).length) dangMo = m.type;
            ghi('PUT', URL.goc + '/' + id + '/status', { status: on });
        });
        $(document).on('click', '.dat-md-pt', function () { ghi('PUT', URL.goc + '/' + $(this).data('id') + '/default'); });
        var duongDong = function (x, sau) { return URL.goc + '/' + pt(dangMo).id + '/details/' + x + (sau || ''); };
        $(document).on('change', '.item-status', function () { ghi('PUT', duongDong($(this).data('id'), '/status'), { status: this.checked }); });
        $(document).on('click', '.dat-md-dong', function () { ghi('PUT', duongDong($(this).data('id'), '/default')); });

        // ---------- Xoá ----------
        var xoaId = 0;
        $(document).on('click', '.delete-item', function () {
            var id = +$(this).data('id'), x = cua(dangMo).filter(function (d) { return d.id === id; })[0];
            if (!x) return;
            xoaId = id;
            $('#xoaTieuDe').text(dangMo === 4 ? 'Xoá tài khoản?' : dangMo === 5 ? 'Xoá máy quẹt thẻ?' : 'Xoá cổng QR?');
            $('#deleteItem .cau-hoi').text(dangMo === 4 ? tenNH(x) + ' · ' + x.data.bank_account : dangMo === 5 ? tenMay(x) : TEN_QR[x.detail_type]);
            bootstrap.Modal.getOrCreateInstance('#deleteItem').show();
        });
        $('#deleteItem .delete-value').on('click', function () {
            var $n = $(this).prop('disabled', true);
            ghi('DELETE', duongDong(xoaId)).always(function () { $n.prop('disabled', false); bootstrap.Modal.getOrCreateInstance('#deleteItem').hide(); });
        });

        // ---------- Ô nhập dùng chung ----------
        function boDau(s) { return s.normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/đ/g, 'd').replace(/Đ/g, 'D'); }
        function chuanTen(v) { return boDau(v).replace(/[^a-zA-Z0-9 ]/g, '').toUpperCase(); }
        $(document).on('input', '.bank_account_name', function () { this.value = chuanTen(this.value); });
        $(document).on('input', '.bank_account, .sepay_account', function () { this.value = this.value.replace(/[^a-zA-Z0-9]/g, ''); });
        $(document).on('input', '.input-percent', function () {
            var v = this.value.replace(',', '.').replace(/[^0-9.]/g, '').replace(/(\..*?)\..*/g, '$1');
            if (v.indexOf('.') >= 0) { var p = v.split('.'); v = p[0] + '.' + p[1].slice(0, 2); }
            if (parseFloat(v) > 10) { v = '10.00'; bao(false, 'Phí không được lớn hơn 10%.'); }
            this.value = v;
        });
        $(document).on('input change', '.o-nhap input, .o-nhap select', function () { $(this).closest('.o-nhap').removeClass('co-loi'); });
        $(document).on('click', '.pm-mat', function () {
            var $i = $(this).siblings('input'), hien = $i.attr('type') === 'password';
            $i.attr('type', hien ? 'text' : 'password');
            $(this).attr('aria-label', hien ? 'Ẩn khoá' : 'Hiện khoá').find('i').attr('class', hien ? 'fa-regular fa-eye-slash' : 'fa-regular fa-eye');
        });
        $(document).on('click', '.pm-chep', function () {
            var t = $(this).siblings('code').text(), nut = this;
            var xong = function () { nut.innerHTML = '<i class="fa-solid fa-check me-1"></i>Đã chép'; setTimeout(function () { nut.innerHTML = '<i class="fa-regular fa-copy me-1"></i>Chép'; }, 1500); };
            if (navigator.clipboard) navigator.clipboard.writeText(t).then(xong, xong); else xong();
        });
        function boTrang($hop) {
            $hop.find('.co-loi').removeClass('co-loi');
            $hop.find('input:not([type=checkbox]):not([type=radio]):not([type=hidden]), textarea').val('').attr('placeholder', function () { return this.getAttribute('data-goi-y') || this.getAttribute('placeholder'); });
            $hop.find('.pm-mat').each(function () { $(this).siblings('input').attr('type', 'password'); $(this).find('i').attr('class', 'fa-regular fa-eye'); });
        }
        // Ô bắt buộc trống thì báo ngay; khoá bí mật đã lưu thì bỏ trống được (giữ khoá cũ).
        function batBuoc($vung) {
            var ok = true;
            $vung.find('[data-bb]').filter(':visible').each(function () {
                if (this.getAttribute('data-da-luu') === '1') return;
                if (!String($(this).val() || '').trim()) { $(this).closest('.o-nhap').addClass('co-loi'); ok = false; }
            });
            if (!ok) $vung.find('.co-loi :input').filter(':visible').first().trigger('focus');
            return ok;
        }
        // Lỗi theo từng ô từ API → tô đỏ đúng ô trong hộp.
        function toLoi($hop, loi) {
            Object.keys(loi).forEach(function (k) {
                var $o = k === 'device_id' ? $hop.find('.select_device, .select_device_physical').filter(':visible')
                    : k === 'detail_type' ? $hop.find('.pm-chon-ds') : $hop.find('.' + k).filter(':visible');
                var $bao = $o.hasClass('pm-chon-ds') ? $o : $o.closest('.o-nhap');
                $bao.addClass('co-loi').find('.pm-loi').first().text(loi[k]);
            });
        }
        function danhKhoa($hop, x) {
            $hop.find('input[type=password]').each(function () {
                var k = this.classList[1], co = !!(x && x.saved_secrets && x.saved_secrets[k]);
                this.setAttribute('data-da-luu', co ? '1' : '0');
                if (!this.getAttribute('data-goi-y')) this.setAttribute('data-goi-y', this.getAttribute('placeholder') || '');
                this.placeholder = co ? '•••••• đã lưu' : (this.getAttribute('data-goi-y') || '');
                this.title = co ? 'Khoá đã lưu — để trống là giữ nguyên, gõ khoá mới để thay.' : '';
            });
        }
        function veNganHang() {
            var opt = '<option value="">Chọn ngân hàng</option>' + (D.banks || []).map(function (b) { return '<option value="' + b.bin + '">' + esc(b.name) + ' (' + b.bin + ')</option>'; }).join('');
            $('select.bank_id').html(opt);
            $('select.bank_id').each(function () { lamMoiChon(this); });
        }
        function thuData($vung) {
            var o = {};
            $vung.find('[data-k]').filter(function () { return $(this).closest('[hidden]').length === 0; }).each(function () {
                o[this.getAttribute('data-k')] = this.type === 'checkbox' ? (this.checked ? '1' : '0') : String($(this).val() || '').trim();
            });
            return o;
        }
        function luu($hop, $nut, url, method, body) {
            $nut.prop('disabled', true);
            ghi(method, url, body, $hop).then(function (ok) {
                $nut.prop('disabled', false);
                if (ok) bootstrap.Modal.getOrCreateInstance($hop[0]).hide();
            });
        }

        // ---------- Hộp chuyển khoản ----------
        var $bt = $('#modalBankTransfer');
        // VietQR (chuẩn EMVCo của NAPAS): mỗi trường = mã 2 số + độ dài 2 số + giá trị,
        // kết thúc bằng CRC16-CCITT. Mã tĩnh (11) — không kèm số tiền.
        function truongQR(id, v) { return id + ('0' + v.length).slice(-2) + v; }
        function crc16(s) {
            var c = 0xFFFF;
            for (var i = 0; i < s.length; i++) {
                c ^= s.charCodeAt(i) << 8;
                for (var j = 0; j < 8; j++) c = c & 0x8000 ? (c << 1) ^ 0x1021 : c << 1;
                c &= 0xFFFF;
            }
            return ('000' + c.toString(16).toUpperCase()).slice(-4);
        }
        function chuoiVietQR(bin, so) {
            var tk = truongQR('00', 'A000000727') + truongQR('01', truongQR('00', bin) + truongQR('01', so)) + truongQR('02', 'QRIBFTTA');
            var s = truongQR('00', '01') + truongQR('01', '11') + truongQR('38', tk) + truongQR('53', '704') + truongQR('58', 'VN') + '6304';
            return s + crc16(s);
        }
        function svgQR(chuoi) {
            if (typeof window.qrcode !== 'function') return '';
            var q = window.qrcode(0, 'M'); q.addData(chuoi); q.make();
            var n = q.getModuleCount(), le = 2, d = '';
            for (var r = 0; r < n; r++) for (var c = 0; c < n; c++) if (q.isDark(r, c)) d += 'M' + (c + le) + ' ' + (r + le) + 'h1v1h-1z';
            return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' + (n + le * 2) + ' ' + (n + le * 2) + '" shape-rendering="crispEdges">' +
                '<rect width="100%" height="100%" fill="#fff"/><path fill="#0b1324" d="' + d + '"/></svg>';
        }
        // Tên viết tắt quen mặt trên app ngân hàng; ngân hàng khác lấy chữ hoa trong tên.
        var VIET_TAT = { '970436': 'VCB', '970415': 'VTB', '970405': 'AGR', '970407': 'TCB', '970422': 'MB', '970432': 'VPB',
            '970423': 'TPB', '970403': 'STB', '970437': 'HDB', '970431': 'EIB', '970440': 'SSB', '970449': 'LPB', '970428': 'NAB',
            '970409': 'BAB', '970412': 'PVB', '970427': 'VAB', '970400': 'SGB', '970438': 'BVB', '970454': 'BVBank', '970452': 'KLB' };
        function vietTat(bin, ten) {
            if (VIET_TAT[bin]) return VIET_TAT[bin];
            var hoa = (ten || '').replace(/[^A-Z]/g, '');
            return hoa.length >= 2 ? hoa.slice(0, 5) : (ten || '').slice(0, 3).toUpperCase();
        }
        function xemTruoc() {
            var $x = $('#btXemTruoc'), bin = $bt.find('.bank_id').val() || '';
            var nh = ((D.banks || []).filter(function (b) { return b.bin === bin; })[0] || {}).name || '';
            var so = ($bt.find('.bank_account').val() || '').trim(), chu = chuanTen($bt.find('.bank_account_name').val() || '').trim();
            var du = !!(nh && /^[A-Za-z0-9]{4,19}$/.test(so));
            $x.find('.xt-bank').text(nh || 'Chưa chọn ngân hàng');
            $x.find('.xt-nh-ic').text(nh ? vietTat(bin, nh) : '').prop('hidden', !nh);
            $x.find('.xt-chu').text(chu || 'TÊN CHỦ TÀI KHOẢN').toggleClass('mo', !chu);
            $x.find('.xt-so').text(so ? so.replace(/(.{4})(?=.)/g, '$1 ') : 'Số tài khoản').toggleClass('mo', !so);
            $x.find('.xt-tien').html($bt.find('.edit_amount').is(':checked')
                ? 'Khách tự nhập<small>Mã không kèm số tiền</small>' : 'Theo từng đơn<small>Mã ở quầy kèm sẵn số tiền</small>');
            var svg = du ? svgQR(chuoiVietQR(bin, so)) : '';
            $x.find('.xt-qr').html(svg).attr('data-chuoi', svg ? chuoiVietQR(bin, so) : '');
            $x.find('.xt-trong').prop('hidden', !!svg).find('span').text(
                !nh ? 'Chọn ngân hàng và nhập số tài khoản — mã QR hiện ở đây.'
                    : !so ? 'Nhập số tài khoản — mã QR hiện ở đây.' : !du ? 'Số tài khoản 4–19 ký tự, chỉ chữ và số.'
                    : 'Chưa tải được bộ vẽ mã QR — kiểm tra mạng rồi mở lại hộp.');
            $x.find('.xt-goi').prop('hidden', !svg);
            var bat = $bt.find('.status_method').is(':checked');
            $x.toggleClass('tat', !bat).find('.xt-tat').prop('hidden', bat);
            $x.find('.xt-md').prop('hidden', !$bt.find('.bank_default').is(':checked') || !bat);
        }
        $bt.on('input change', 'input, select', xemTruoc);
        function moBank(x) {
            boTrang($bt);
            $bt.find('.id').val(x ? x.id : '');
            $bt.find('.tieu-de-hop').text(x ? 'Sửa tài khoản chuyển khoản' : 'Thêm tài khoản chuyển khoản');
            $bt.find('.bank_id').val(x ? x.data.bank_id || '' : '');
            $bt.find('.bank_account').val(x ? x.data.bank_account : '');
            $bt.find('.bank_account_name').val(x ? x.data.bank_account_name : '');
            $bt.find('.status_method').prop('checked', x ? x.status : true);
            $bt.find('.bank_default').prop('checked', x ? x.is_default : !dangBat(4).length);
            $bt.find('.edit_amount').prop('checked', x ? x.data.edit_amount === '1' : false);
            xemTruoc();
            veLaiChon($bt);
            bootstrap.Modal.getOrCreateInstance($bt[0]).show();
        }
        $bt.find('.save-item').on('click', function () {
            if (!batBuoc($bt)) return;
            var id = +$bt.find('.id').val(), on = $bt.find('.status_method').is(':checked'), md = $bt.find('.bank_default').is(':checked');
            if (md && !on) return bao(false, 'Không thể đặt làm mặc định khi trạng thái đang tắt.');
            var body = { detail_type: 'bank_transfer', status: on, is_default: md, data: thuData($bt) };
            luu($bt, $(this), id ? duongDong(id) : URL.goc + '/' + pt(4).id + '/details', id ? 'PUT' : 'POST', body);
        });

        // ---------- Hộp quẹt thẻ ----------
        var $sc = $('#modalCrUdSwipeCard');
        function veMay() {
            [['.select_device', false], ['.select_device_physical', true]].forEach(function (p) {
                var v = $sc.find(p[0]).val();
                $sc.find(p[0]).html('<option value="">Chọn máy</option>' + (D.devices || []).filter(function (d) { return !!d.is_physical === p[1]; })
                    .map(function (d) { return '<option value="' + d.id + '">' + esc(d.code + ' - ' + d.name) + '</option>'; }).join('')).val(v);
                lamMoiChon($sc.find(p[0])[0]);
            });
            nutSuaMay();
        }
        function nutSuaMay() {
            $sc.find('.select_device, .select_device_physical').each(function () {
                $(this).closest('.input-group').find('.edit_device').prop('disabled', !this.value);
            });
        }
        $sc.on('change', '.select_device, .select_device_physical', nutSuaMay);
        function loaiThe() { return $sc.find('input[name=scLoai]:checked').val() || 'mpos_device'; }
        function doiLoaiThe() {
            var mpos = loaiThe() === 'mpos_device';
            $sc.find('.payment_keys').prop('hidden', !mpos);
            $sc.find('.fee_physical_swipe').prop('hidden', mpos);
            // Câu mở đầu cũng đổi theo loại máy, không phải một câu cho cả hai.
            $sc.find('.ghi-mpos').prop('hidden', !mpos);
            $sc.find('.ghi-doc-lap').prop('hidden', mpos);
        }
        $sc.on('change', 'input[name=scLoai]', doiLoaiThe);
        function moThe(x) {
            boTrang($sc);
            veMay();
            danhKhoa($sc, x);
            $sc.find('.id').val(x ? x.id : '');
            $sc.find('.tieu-de-hop').text(x ? 'Sửa máy quẹt thẻ' : 'Thêm máy quẹt thẻ');
            var loai = x ? x.detail_type : 'mpos_device';
            $sc.find('input[name=scLoai]').each(function () { this.checked = this.value === loai; this.disabled = !!x && !this.checked; });
            $sc.find('.status_method').prop('checked', x ? x.status : true);
            $sc.find('.bank_id').val(x ? x.data.bank_id || '' : '');
            if (x) {
                Object.keys(x.data).forEach(function (k) { $sc.find('[data-k="' + k + '"]').not('select.bank_id').val(x.data[k]); });
                $sc.find(loai === 'mpos_device' ? '.select_device' : '.select_device_physical').val(x.device_id || '');
                nutSuaMay();
            } else {
                $sc.find('.input-percent').val('0');
            }
            doiLoaiThe();
            gioiThieuCong($sc, x);
            veLaiChon($sc);
            bootstrap.Modal.getOrCreateInstance($sc[0]).show();
        }
        $sc.find('.save-item').on('click', function () {
            if (!batBuoc($sc)) return;
            var id = +$sc.find('.id').val(), loai = loaiThe();
            var body = {
                detail_type: loai, status: $sc.find('.status_method').is(':checked'),
                device_id: +$sc.find(loai === 'mpos_device' ? '.select_device' : '.select_device_physical').val() || 0,
                data: thuData($sc),
            };
            if (id || loai !== 'mpos_device') {
                luu($sc, $(this), id ? duongDong(id) : URL.goc + '/' + pt(5).id + '/details', id ? 'PUT' : 'POST', body);
                return;
            }
            // Máy mPOS mới: giữ hộp mở để có ngay URL webhook gửi NextPay và nút Kiểm tra.
            var $n = $(this).prop('disabled', true);
            goi('POST', URL.goc + '/' + pt(5).id + '/details', body).then(function (r) {
                bao(true, 'Đã lưu máy mPOS — gửi URL webhook cho NextPay rồi bấm Kiểm tra kết nối.');
                var moi = r.data && r.data.id;
                return napLai().then(function () {
                    var x = cua(5).filter(function (d) { return d.id === moi; })[0];
                    if (x) { moThe(x); } else { bootstrap.Modal.getOrCreateInstance($sc[0]).hide(); }
                });
            }, function (xhr) {
                var j = xhr.responseJSON || {};
                if (j.errors) toLoi($sc, j.errors);
                bao(false, j.message || 'Không lưu được. Vui lòng thử lại.');
            }).always(function () { $n.prop('disabled', false); });
        });

        // ---------- Hộp máy (thêm / sửa / xoá) ----------
        var $dv = $('#modalCrudDevice');
        var mayDangGan = function (id) {
            return cua(5).some(function (x) { return x.device_id === id; });
        };
        function moMay(may) {
            boTrang($dv);
            $dv.find('.pm-loi').text('Bắt buộc.');
            $dv.find('.id').val(may ? may.id : '');
            $dv.find('#dvTieuDe').text(may ? 'Sửa máy' : 'Thêm máy');
            $dv.find('.save-item').text(may ? 'Lưu máy' : 'Thêm máy');
            if (may) {
                $dv.find('.code').val(may.code);
                $dv.find('.name').val(may.name);
                $dv.find('.description').val(may.description || '');
            }
            var gan = !!may && mayDangGan(may.id);
            $dv.find('.dv-xoa').prop('hidden', !may).prop('disabled', gan);
            $dv.find('.dv-dang-gan').prop('hidden', !gan);
            hoiXoa(false);
            bootstrap.Modal.getOrCreateInstance($dv[0]).show();
        }
        function hoiXoa(bat) {
            $dv.find('.dv-chan').prop('hidden', bat);
            $dv.find('.dv-hoi-xoa').prop('hidden', !bat);
        }
        $(document).on('click', '.add_device', function () { moMay(null); });
        $(document).on('click', '.edit_device', function () {
            var id = +$(this).closest('.input-group').find('select').val();
            var may = (D.devices || []).filter(function (d) { return d.id === id; })[0];
            if (may) moMay(may);
        });
        $dv.find('.save-item').on('click', function () {
            if (!batBuoc($dv)) return;
            var id = +$dv.find('.id').val(), vatLy = loaiThe() !== 'mpos_device', $n = $(this).prop('disabled', true);
            var body = { code: $dv.find('.code').val().trim(), name: $dv.find('.name').val().trim(), description: $dv.find('.description').val().trim() };
            if (!id) body.is_physical = vatLy;
            goi(id ? 'PUT' : 'POST', id ? URL.mayGoc + '/' + id : URL.may, body)
                .then(function (r) {
                    bao(true, r.message);
                    return napLai().then(function () {
                        veMay();
                        if (!id) $sc.find(vatLy ? '.select_device_physical' : '.select_device').val(r.data.id).trigger('change');
                        bootstrap.Modal.getOrCreateInstance($dv[0]).hide();
                    });
                }, function (x) {
                    var j = x.responseJSON || {};
                    if (j.errors) toLoi($dv, j.errors);
                    bao(false, j.message || (id ? 'Không lưu được máy.' : 'Không thêm được máy.'));
                }).always(function () { $n.prop('disabled', false); });
        });
        $dv.find('.dv-xoa').on('click', function () {
            $dv.find('.dv-ten-xoa').text($dv.find('.code').val().trim() + ' · ' + $dv.find('.name').val().trim());
            hoiXoa(true);
        });
        $dv.find('.dv-thoi').on('click', function () { hoiXoa(false); });
        $dv.find('.dv-xoa-that').on('click', function () {
            var id = +$dv.find('.id').val(), $n = $(this).prop('disabled', true);
            goi('DELETE', URL.mayGoc + '/' + id)
                .then(function (r) {
                    bao(true, r.message);
                    return napLai().then(function () {
                        veMay();
                        bootstrap.Modal.getOrCreateInstance($dv[0]).hide();
                    });
                }, function (x) {
                    hoiXoa(false);
                    bao(false, (x.responseJSON || {}).message || 'Không xoá được máy.');
                }).always(function () { $n.prop('disabled', false); });
        });

        // ---------- Hộp QR ----------
        var $qr = $('#modalQR'), dangSuaQR = null;
        // NextPay báo cả QR lẫn quẹt thẻ về một đường /payments/mpos/… của merchant.
        function urlWebhook(x) { return URL.webhook + '/payments/' + (x.detail_type.indexOf('mpos') === 0 ? 'mpos' : x.detail_type) + '/webhook/' + x.webhook_code; }
        function hienKetQua(kq, dang, $hop) {
            $hop = $hop || $qr;
            var $k = $hop.find('.pm-kq');
            if (!kq && !dang) { $k.prop('hidden', true).empty(); return; }
            if (dang) { $k.attr('class', 'pm-kq mt-3 dang').prop('hidden', false).html('<i class="fa-solid fa-spinner fa-spin"></i><span>Đang kiểm tra kết nối…</span>'); return; }
            $k.attr('class', 'pm-kq mt-3 ' + (kq.ok ? 'ok' : 'loi')).prop('hidden', false)
                .html('<i class="fa-solid ' + (kq.ok ? 'fa-circle-check' : 'fa-triangle-exclamation') + '"></i><span>' + esc(kq.message) +
                    (kq.last_webhook_at ? '<small>Lần báo tiền gần nhất: ' + esc(lucDoc(kq.last_webhook_at)) + '</small>' : '') + '</span>');
            if ($hop.hasClass('show')) $k[0].scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        }
        // Hộp đang sửa cổng nào (QR hoặc máy mPOS) — nút Kiểm tra của hộp đó dùng dòng ấy.
        function gioiThieuCong($hop, x) {
            $hop.data('x', x);
            $hop.find('.url-webhook').each(function () {
                var co = x && x.webhook_code && x.detail_type === this.getAttribute('data-cong');
                this.textContent = co ? urlWebhook(x) : 'URL riêng hiện ở đây ngay sau khi bấm Lưu.';
                $(this).siblings('.pm-chep').prop('hidden', !co);
            });
            $hop.find('.pm-kiem-tra').prop('hidden', !(x && coWebhook(x.detail_type)) || !sua());
            hienKetQua(x && x.data.last_webhook_at ? { ok: true, message: 'Cổng đã báo tiền về.', last_webhook_at: x.data.last_webhook_at } : null, false, $hop);
        }
        $('#modalQR, #modalCrUdSwipeCard').on('click', '.pm-kiem-tra', function () {
            var $hop = $(this).closest('.modal'), x = $hop.data('x'), $n = $(this).prop('disabled', true);
            if (!x) return;
            hienKetQua(null, true, $hop);
            goi('POST', duongDong(x.id, '/test'), { webhook_url: urlWebhook(x) }).then(function (r) {
                hienKetQua(r.data || { ok: false, message: 'Không đọc được kết quả.' }, false, $hop);
                return napLai();
            }, function (xhr) {
                hienKetQua({ ok: false, message: (xhr.responseJSON && xhr.responseJSON.message) || 'Không kiểm tra được. Vui lòng thử lại.' }, false, $hop);
            }).always(function () { $n.prop('disabled', false); });
        });
        $qr.on('click', '.pm-sinh-khoa', function () {
            var b = new Uint8Array(18); (window.crypto || window.msCrypto).getRandomValues(b);
            var k = Array.from(b, function (v) { return ('0' + v.toString(16)).slice(-2); }).join('');
            var $i = $(this).siblings('input');
            $i.val(k).attr('type', 'text').closest('.o-nhap').removeClass('co-loi');
            $(this).siblings('.pm-mat').find('i').attr('class', 'fa-regular fa-eye-slash');
        });
        function loaiQR() { return $qr.find('input[name=qrLoai]:checked').val(); }
        function doiLoaiQR() {
            var v = loaiQR();
            $qr.find('.qr-o').each(function () { this.hidden = this.getAttribute('data-qr') !== v; });
            $qr.find('.pm-chon-ds').removeClass('co-loi');
        }
        $qr.on('change', 'input[name=qrLoai]', doiLoaiQR);
        function moQR(x) {
            boTrang($qr);
            danhKhoa($qr, x);
            $qr.find('.id').val(x ? x.id : '');
            $qr.find('.tieu-de-hop').text(x ? 'Sửa cổng ' + TEN_QR[x.detail_type] : 'Thêm cổng QR tự động');
            // Mỗi cổng một dòng mỗi chi nhánh: cổng đã có thì khoá thẻ chọn ngay từ đầu.
            var daCo = cua(6).filter(function (d) { return !x || d.id !== x.id; }).map(function (d) { return d.detail_type; });
            var chon = x ? x.detail_type : (Object.keys(TEN_QR).filter(function (k) { return daCo.indexOf(k) < 0; })[0] || '');
            $qr.find('input[name=qrLoai]').each(function () {
                var co = daCo.indexOf(this.value) >= 0;
                $(this).closest('.pm-chon').toggleClass('da-co', co);
                this.disabled = co || (!!x && this.value !== x.detail_type);
                this.checked = this.value === chon;
            });
            $qr.find('.status_method').prop('checked', x ? x.status : true);
            $qr.find('.bank_id').val(x ? x.data.bank_id || '' : '');
            if (x) Object.keys(x.data).forEach(function (k) { $qr.find('.qr-o[data-qr="' + x.detail_type + '"] [data-k="' + k + '"]').not('select.bank_id').val(x.data[k]); });
            dangSuaQR = x;
            gioiThieuCong($qr, x);
            doiLoaiQR();
            veLaiChon($qr);
            bootstrap.Modal.getOrCreateInstance($qr[0]).show();
        }
        $qr.find('.save-item').on('click', function () {
            var loai = loaiQR(), id = +$qr.find('.id').val();
            if (!loai) { $qr.find('.pm-chon-ds').addClass('co-loi'); return; }
            var $o = $qr.find('.qr-o[data-qr="' + loai + '"]');
            if (!batBuoc($o)) return;
            var body = { detail_type: loai, status: $qr.find('.status_method').is(':checked'), data: thuData($o) };
            if (id || !coWebhook(loai)) {
                luu($qr, $(this), id ? duongDong(id) : URL.goc + '/' + pt(6).id + '/details', id ? 'PUT' : 'POST', body);
                return;
            }
            // Cổng mới: giữ hộp mở, chuyển sang chế độ sửa để có ngay URL webhook
            // riêng và nút Kiểm tra — bước 2 của việc tự kết nối.
            var $n = $(this).prop('disabled', true);
            goi('POST', URL.goc + '/' + pt(6).id + '/details', body).then(function (r) {
                bao(true, 'Đã lưu ' + TEN_QR[loai] + ' — làm tiếp bước dán URL webhook.');
                var moi = r.data && r.data.id;
                return napLai().then(function () {
                    var x = cua(6).filter(function (d) { return d.id === moi; })[0];
                    if (x) { moQR(x); } else { bootstrap.Modal.getOrCreateInstance($qr[0]).hide(); }
                });
            }, function (xhr) {
                var j = xhr.responseJSON || {};
                if (j.errors) toLoi($qr, j.errors);
                bao(false, j.message || 'Không lưu được. Vui lòng thử lại.');
            }).always(function () { $n.prop('disabled', false); });
        });

        $(document).on('click', '.btn-tao', function () { var t = +$(this).data('type'); (t === 4 ? moBank : t === 5 ? moThe : moQR)(null); });
        $(document).on('click', '.edit-item', function () {
            var id = +$(this).data('id'), x = cua(dangMo).filter(function (d) { return d.id === id; })[0];
            if (x) (dangMo === 4 ? moBank : dangMo === 5 ? moThe : moQR)(x);
        });

        // ---------- Ô chọn có tìm ----------
        // Thay phần nhìn của <select> trong các hộp: nút + bảng thả có ô tìm. Select gốc vẫn
        // là nơi giữ giá trị (thuData, batBuoc, .val() ở các hàm mở hộp không phải đổi).
        // Ngân hàng gõ được không dấu, tên viết tắt, mã BIN hoặc tên đầy đủ.
        var BIET_DANH = { '970436': 'ngoai thuong', '970415': 'cong thuong ctg', '970418': 'dau tu phat trien', '970405': 'nong nghiep phat trien nong thon',
            '970407': 'ky thuong', '970422': 'quan doi', '970416': 'a chau', '970432': 'viet nam thinh vuong', '970423': 'tien phong',
            '970403': 'sai gon thuong tin', '970437': 'phat trien thanh pho', '970441': 'quoc te', '970443': 'sai gon ha noi', '970431': 'xuat nhap khau',
            '970426': 'hang hai', '970448': 'phuong dong', '970440': 'dong nam a', '970449': 'loc phat buu dien lienviet', '970428': 'nam a',
            '970425': 'an binh', '970409': 'bac a', '970412': 'dai chung', '970427': 'viet a', '970400': 'sai gon cong thuong', '970438': 'bao viet' };
        var PHO_BIEN = 12;
        function khongDau(v) { return boDau(String(v || '')).toLowerCase(); }
        function moTaChon(sel, o) {
            if (!o || !o.value) return null;
            if ($(sel).hasClass('bank_id')) {
                var b = (D.banks || []).filter(function (x) { return x.bin === o.value; })[0] || { name: o.text, bin: o.value };
                return { the: vietTat(b.bin, b.name), ten: b.name, phu: b.bin, tim: khongDau(b.name + ' ' + vietTat(b.bin, b.name) + ' ' + b.bin + ' ' + (BIET_DANH[b.bin] || '')) };
            }
            if ($(sel).is('.select_device, .select_device_physical')) {
                var d = (D.devices || []).filter(function (x) { return String(x.id) === o.value; })[0] || { code: o.text, name: '' };
                return { icon: d.is_physical ? 'fa-cash-register' : 'fa-mobile-screen-button', ten: d.name || d.code, phu: d.code, tim: khongDau(d.code + ' ' + d.name) };
            }
            return { ten: o.text, tim: khongDau(o.text) };
        }
        function nhanChon(m) {
            return (m.the ? '<span class="pmc-the">' + esc(m.the) + '</span>' : m.icon ? '<span class="pmc-the may"><i class="fa-solid ' + m.icon + '"></i></span>' : '');
        }
        function lamMoiChon(sel) {
            if (!sel || !sel._pmc) return;
            var o = sel.options[sel.selectedIndex], m = moTaChon(sel, o), $n = $(sel._pmc.nut);
            $n.html(m ? nhanChon(m) + '<span class="pmc-chu">' + esc(m.ten) + '</span>' + (m.phu && m.phu !== m.ten ? '<span class="pmc-phu">' + esc(m.phu) + '</span>' : '')
                : '<span class="pmc-chu trong">' + esc((sel.options[0] && !sel.options[0].value && sel.options[0].text) || 'Chọn') + '</span>');
            $n.prop('disabled', sel.disabled);
        }
        var chonMo = null;
        function dongChon(traFocus) {
            if (!chonMo) return;
            var c = chonMo; chonMo = null;
            c.bang.hidden = true; $(c.vo).removeClass('mo'); c.nut.setAttribute('aria-expanded', 'false');
            $(window).off('.pmc'); $(document).off('.pmc'); $(c.nut).closest('.modal-body').off('.pmc');
            if (traFocus) c.nut.focus();
        }
        function datViTri(c) {
            var r = c.nut.getBoundingClientRect(), duoi = innerHeight - r.bottom - 12, tren = r.top - 12;
            var cao = Math.min(c.coTim ? 360 : 280, Math.max(duoi, tren) - 6), len = duoi < 220 && tren > duoi;
            var rong = Math.max(r.width, Math.min(320, innerWidth - 24)), trai = Math.min(r.left, innerWidth - rong - 12);
            $(c.bang).css({ left: Math.max(12, trai), width: rong, maxHeight: cao, top: len ? 'auto' : r.bottom + 4, bottom: len ? innerHeight - r.top + 4 : 'auto' });
        }
        function veDanhSach(c) {
            var q = khongDau(c.tim ? c.tim.value.trim() : ''), sel = c.sel, html = '', so = 0, i = 0;
            c.mon = [];
            [].forEach.call(sel.options, function (o) {
                if (!o.value) return;
                var m = moTaChon(sel, o);
                if (q && q.split(/\s+/).some(function (t) { return m.tim.indexOf(t) < 0; })) return;
                if (!q && sel.classList.contains('bank_id') && (i === 0 || i === PHO_BIEN)) html += '<li class="pmc-nhom" role="presentation">' + (i === 0 ? 'Phổ biến' : 'Ngân hàng khác') + '</li>';
                i++;
                var ten = esc(m.ten);
                if (q) {
                    var k = khongDau(m.ten).indexOf(q);
                    if (k >= 0 && m.ten.length === khongDau(m.ten).length) ten = esc(m.ten.slice(0, k)) + '<mark>' + esc(m.ten.slice(k, k + q.length)) + '</mark>' + esc(m.ten.slice(k + q.length));
                }
                html += '<li class="pmc-mon" role="option" id="' + c.id + '-' + so + '" data-v="' + esc(o.value) + '" aria-selected="' + (o.value === sel.value) + '">' +
                    '<i class="fa-solid fa-check pmc-dau"></i>' + nhanChon(m) + '<span class="pmc-ten">' + ten + '</span>' + (m.phu && m.phu !== m.ten ? '<span class="pmc-phu">' + esc(m.phu) + '</span>' : '') + '</li>';
                c.mon.push(o.value); so++;
            });
            c.ds.innerHTML = html || '<li class="pmc-rong" role="presentation"><i class="fa-solid fa-magnifying-glass"></i>Không thấy ' + (sel.classList.contains('bank_id') ? 'ngân hàng' : 'mục') + ' nào khớp “' + esc(c.tim.value.trim()) + '”</li>';
            var dang = c.mon.indexOf(sel.value);
            danhDau(c, q ? 0 : Math.max(0, dang));
        }
        function danhDau(c, k) {
            c.dang = Math.max(0, Math.min(k, c.mon.length - 1));
            $(c.ds).find('.pmc-mon').removeClass('dang');
            var el = c.mon.length ? document.getElementById(c.id + '-' + c.dang) : null;
            if (el) { el.classList.add('dang'); el.scrollIntoView({ block: 'nearest' }); }
            (c.tim || c.nut).setAttribute('aria-activedescendant', el ? el.id : '');
        }
        function chonGiaTri(c, v) {
            if (v != null && c.sel.value !== v) { $(c.sel).val(v).trigger('change'); }
            lamMoiChon(c.sel); dongChon(true);
        }
        function moChon(c) {
            if (chonMo === c) return dongChon(true);
            dongChon(false);
            chonMo = c; $(c.vo).addClass('mo'); c.nut.setAttribute('aria-expanded', 'true');
            c.bang.hidden = false;
            if (c.tim) c.tim.value = '';
            $(c.bang).find('.pmc-tim').prop('hidden', !c.coTim);
            veDanhSach(c); datViTri(c);
            if (c.coTim) setTimeout(function () { c.tim.focus(); }, 0);
            $(window).on('resize.pmc', function () { datViTri(c); });
            // Cuộn hộp: bảng bám theo ô; ô đã cuộn khuất thì đóng.
            $(c.nut).closest('.modal-body').on('scroll.pmc', function () {
                var n = c.nut.getBoundingClientRect(), v = this.getBoundingClientRect();
                if (n.bottom < v.top || n.top > v.bottom) dongChon(false); else datViTri(c);
            });
            $(document).on('mousedown.pmc touchstart.pmc', function (e) { if (!$(e.target).closest(c.bang).length && !$(e.target).closest(c.vo).length) dongChon(false); });
        }
        function phimChon(c, e) {
            var k = e.key;
            if (k === 'ArrowDown' || k === 'ArrowUp') { e.preventDefault(); if (chonMo !== c) return moChon(c); danhDau(c, c.dang + (k === 'ArrowDown' ? 1 : -1)); }
            else if (k === 'Enter' && chonMo === c) { e.preventDefault(); if (c.mon.length) chonGiaTri(c, c.mon[c.dang]); }
            else if (k === 'Escape' && chonMo === c) { e.preventDefault(); e.stopPropagation(); dongChon(true); }
            else if (k === 'Tab' && chonMo === c) dongChon(false);
            else if (k === 'Home' && chonMo === c && !c.coTim) { e.preventDefault(); danhDau(c, 0); }
            else if (k === 'End' && chonMo === c && !c.coTim) { e.preventDefault(); danhDau(c, c.mon.length - 1); }
        }
        var soChon = 0;
        function lamDepChon(sel) {
            if (sel._pmc) return;
            var id = 'pmc' + (++soChon), $vo = $('<div class="pmc"></div>');
            $(sel).before($vo); $vo.append(sel);
            var nut = $('<button type="button" class="form-select pmc-nut" aria-haspopup="listbox" aria-expanded="false"></button>')
                .attr({ 'aria-controls': id + '-ds' }).appendTo($vo)[0];
            var nhan = sel.id ? $('label[for="' + sel.id + '"]') : $(sel).closest('.o-nhap').find('.form-label').first();
            if (nhan.length) { if (!nhan.attr('id')) nhan.attr('id', id + '-nhan'); nut.setAttribute('aria-labelledby', nhan.attr('id') + ' ' + id + '-nut'); if (sel.id) nhan.attr('for', id + '-nut'); }
            nut.id = id + '-nut';
            var bang = $('<div class="pmc-bang" hidden><div class="pmc-tim"><i class="fa-solid fa-magnifying-glass"></i>' +
                '<input type="text" autocomplete="off" spellcheck="false" role="combobox" aria-autocomplete="list" aria-expanded="true">' +
                '<button type="button" class="pmc-xoa" aria-label="Xoá ô tìm" hidden><i class="fa-solid fa-xmark"></i></button></div>' +
                '<ul class="pmc-ds" role="listbox" id="' + id + '-ds"></ul></div>').appendTo($(sel).closest('.modal').length ? $(sel).closest('.modal') : document.body)[0];
            var c = { id: id, sel: sel, vo: $vo[0], nut: nut, bang: bang, ds: $(bang).find('.pmc-ds')[0], tim: $(bang).find('input')[0], mon: [], dang: 0 };
            Object.defineProperty(c, 'coTim', { get: function () { return sel.options.length > 6; } });
            c.tim.setAttribute('aria-controls', id + '-ds');
            c.tim.placeholder = sel.classList.contains('bank_id') ? 'Tìm tên, viết tắt hoặc mã BIN…' : 'Tìm…';
            sel._pmc = c;
            $(nut).on('click', function () { moChon(c); }).on('keydown', function (e) { phimChon(c, e); });
            $(c.tim).on('keydown', function (e) { phimChon(c, e); }).on('input', function () {
                $(bang).find('.pmc-xoa').prop('hidden', !this.value); veDanhSach(c);
            });
            $(bang).find('.pmc-xoa').on('click', function () { c.tim.value = ''; this.hidden = true; veDanhSach(c); c.tim.focus(); });
            $(c.ds).on('mousemove', '.pmc-mon', function () { var k = c.mon.indexOf(this.getAttribute('data-v')); if (k !== c.dang) danhDau(c, k); })
                .on('click', '.pmc-mon', function () { chonGiaTri(c, this.getAttribute('data-v')); });
            // Ô gốc bị gọi focus (batBuoc tô đỏ ô trống) → đưa sang nút.
            $(sel).on('focus', function () { nut.focus(); }).on('change', function () { lamMoiChon(sel); });
            lamMoiChon(sel);
        }
        $('.pm-hop select.form-select').each(function () { lamDepChon(this); });
        // Các hàm mở hộp đặt giá trị bằng .val() (không bắn change) — kể cả lúc hộp đang mở
        // (thêm cổng / máy mới xong chuyển sang sửa), nên vẽ lại nhãn ngay trước khi show().
        function veLaiChon($hop) { $hop.find('select.form-select').each(function () { lamMoiChon(this); }); }
        $('.pm-hop').on('hide.bs.modal', function () { dongChon(false); });

        if (D) { veNganHang(); ve(); }
    })();
    </script>
@endpush
