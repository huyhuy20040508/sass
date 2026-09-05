    <style>
        .prd {
            /* Phá padding p-4 của <main> để tràn viền như trang Danh mục */
            margin: -1.5rem;
            min-height: calc(100vh - 56px);
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            color: #262626; background: #fff;
        }

        /* Header */
        .prd-head {
            display: flex; align-items: center; justify-content: space-between; gap: 16px;
            padding: 14px 20px;
        }
        .prd-title { margin: 0; font-size: 16px; font-weight: 700; color: #262626; line-height: 34px; }
        .prd-btn-primary {
            height: 34px; display: inline-flex; align-items: center; gap: 6px; border: 0; border-radius: 4px;
            background: #1890ff; color: #fff; padding: 0 16px; font-size: 13px; font-weight: 500; cursor: pointer;
            transition: background .15s;
        }
        .prd-btn-primary:hover { background: #40a9ff; }

        /* Bộ lọc */
        .prd-filter { padding: 0 20px 12px; margin-bottom: 12px; border-bottom: 1px solid #eee; }
        .prd-toolbar { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; }
        .prd-toolbar-adv { display: none; flex-wrap: wrap; align-items: center; gap: 12px; margin-top: 12px; }
        .prd-toolbar-adv.is-open { display: flex; }

        /* Nút "Nâng cao" */
        .prd-adv-btn {
            height: 34px; display: inline-flex; align-items: center; gap: 6px; border: 1px solid #d9d9d9;
            border-radius: 4px; background: #fff; padding: 0 12px; font-size: 13px; color: #595959;
            cursor: pointer; transition: border-color .15s, color .15s;
        }
        .prd-adv-btn:hover, .prd-adv-btn.is-open { border-color: #1890ff; color: #1890ff; }
        .prd-adv-caret { transition: transform .2s; }
        .prd-adv-btn.is-open .prd-adv-caret { transform: rotate(180deg); }
        .prd-adv-count {
            display: inline-flex; align-items: center; justify-content: center; min-width: 18px; height: 18px;
            padding: 0 5px; border-radius: 9999px; background: #1890ff; color: #fff; font-size: 11px; font-weight: 600;
        }

        /* Nhóm hành động (Tạo mới + Nâng cao) — đẩy sang phải toolbar */
        .prd-toolbar-actions { margin-left: auto; display: flex; align-items: center; gap: 8px; }
        .prd-btn-primary svg { flex-shrink: 0; }

        /* Dropdown Nâng cao */
        .prd-util { position: relative; }
        .prd-util-btn {
            height: 34px; display: inline-flex; align-items: center; gap: 6px; border: 1px solid #d9d9d9;
            border-radius: 4px; background: #fff; padding: 0 12px; font-size: 13px; color: #595959;
            cursor: pointer; transition: border-color .15s, color .15s;
        }
        .prd-util-btn:hover, .prd-util.open .prd-util-btn { border-color: #1890ff; color: #1890ff; }
        .prd-util-caret { transition: transform .2s; }
        .prd-util.open .prd-util-caret { transform: rotate(180deg); }
        .prd-util-menu {
            position: absolute; right: 0; top: calc(100% + 4px); min-width: 190px; z-index: 1050;
            background: #fff; border: 1px solid #e6e6e6; border-radius: 6px; padding: 4px;
            box-shadow: 0 6px 24px rgba(0,0,0,.12); display: none;
        }
        .prd-util.open .prd-util-menu { display: block; }
        .prd-util-item {
            display: flex; align-items: center; gap: 8px; width: 100%; padding: 8px 10px; border: 0;
            background: none; border-radius: 4px; font-size: 13px; color: #262626; text-decoration: none;
            cursor: pointer; text-align: left; transition: background .15s, color .15s;
        }
        .prd-util-item:hover { background: #f5f7fa; color: #1890ff; }
        .prd-util-item svg { color: #8c8c8c; flex-shrink: 0; }
        .prd-util-item:hover svg { color: #1890ff; }

        /* Chọn cột hiển thị — dùng lại vỏ dropdown .prd-util cho giống "Nâng cao" */
        /* Nút chỉ có icon: vuông, cùng chiều cao 34px với các nút chữ bên cạnh */
        .prd-util-icon {
            position: relative; width: 34px; padding: 0; justify-content: center; flex-shrink: 0;
        }
        .prd-cols-menu { min-width: 210px; max-height: 60vh; overflow-y: auto; }
        .prd-cols-head {
            padding: 8px 10px 6px; font-size: 11px; font-weight: 600; letter-spacing: .3px;
            text-transform: uppercase; color: #8c8c8c;
        }
        .prd-cols-item {
            display: flex; align-items: center; gap: 9px; padding: 7px 10px; border-radius: 4px;
            font-size: 13px; color: #262626; cursor: pointer; user-select: none; transition: background .15s;
        }
        .prd-cols-item:hover { background: #f5f7fa; }
        .prd-cols-item input { width: 15px; height: 15px; accent-color: #1890ff; cursor: pointer; flex-shrink: 0; }
        .prd-cols-foot { margin-top: 4px; border-top: 1px solid #f0f0f0; padding-top: 4px; }
        .prd-cols-foot .prd-util-item:disabled { color: #bfbfbf; cursor: default; background: none; }
        .prd-cols-foot .prd-util-item:disabled svg { color: #d9d9d9; }
        .prd-cols-hint { padding: 2px 10px 6px; font-size: 11px; color: #8c8c8c; line-height: 1.45; }
        /* Số cột đang ẩn — đậu ở góc nút icon, không có chỗ đặt inline như nút chữ */
        .prd-cols-count {
            position: absolute; top: -6px; right: -6px;
            min-width: 16px; height: 16px; padding: 0 4px; border-radius: 8px; background: #1890ff;
            color: #fff; font-size: 11px; font-weight: 600; line-height: 16px; text-align: center;
            box-shadow: 0 0 0 2px #fff;
        }

        /* Ẩn cột: class đặt trên <table>, ăn cho cả <th> lẫn <td> vì hai bên dùng chung lớp */
        .prd-table.hide-sku    .prd-c-sku,
        .prd-table.hide-name   .prd-c-name,
        .prd-table.hide-cat    .prd-c-cat,
        .prd-table.hide-vat    .prd-c-vat,
        .prd-table.hide-unit   .prd-c-unit,
        .prd-table.hide-price  .prd-c-price,
        .prd-table.hide-branch .prd-c-branch,
        .prd-table.hide-status .prd-c-status { display: none; }
        .prd-searchbox { display: flex; border-radius: 4px; }
        .prd-searchbox:focus-within { box-shadow: 0 0 0 4px rgba(13,110,253,.25); }
        .prd-search-input {
            height: 34px; width: 240px; border: 1px solid #d9d9d9; border-right: 0; border-radius: 4px 0 0 4px;
            background: #fff; padding: 0 12px; font-size: 13px; outline: none; transition: border-color .15s;
        }
        .prd-search-input::placeholder { color: #bfbfbf; }
        .prd-searchbox:focus-within .prd-search-input,
        .prd-searchbox:focus-within .prd-search-btn { border-color: #86b7fe; }
        .prd-search-btn {
            height: 34px; width: 40px; display: inline-flex; align-items: center; justify-content: center;
            border: 1px solid #d9d9d9; border-radius: 0 4px 4px 0; background: #fafafa; color: #595959; cursor: pointer; transition: color .15s;
        }
        .prd-search-btn:hover { color: #1890ff; }

        .prd-select {
            height: 34px; border: 1px solid #d9d9d9; border-radius: 4px; background: #fff;
            padding: 0 30px 0 12px; font-size: 13px; color: #262626; cursor: pointer; outline: none;
            transition: border-color .15s; max-width: 220px;
            appearance: none; -webkit-appearance: none;
            background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23595959' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><path d='m6 9 6 6 6-6'/></svg>");
            background-repeat: no-repeat; background-position: right 8px center;
        }
        .prd-select:focus { border-color: #86b7fe; box-shadow: 0 0 0 .25rem rgba(13,110,253,.25); }

        /* Ô lọc chọn NHIỀU — bản cũ dùng select2 multiple; đây là bản không cần
           thư viện ngoài: một nút xổ danh sách ô tick. */
        .prd-multi { position: relative; }
        .prd-multi-btn {
            height: 34px; display: inline-flex; align-items: center; gap: 8px; border: 1px solid #d9d9d9;
            border-radius: 4px; background: #fff; padding: 0 10px 0 12px; font-size: 13px; color: #262626;
            cursor: pointer; max-width: 220px; transition: border-color .15s;
        }
        .prd-multi-btn span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .prd-multi-btn:hover, .prd-multi.open .prd-multi-btn { border-color: #1890ff; }
        .prd-multi-menu {
            position: absolute; left: 0; top: calc(100% + 4px); min-width: 220px; max-height: 320px; overflow-y: auto;
            z-index: 1050; background: #fff; border: 1px solid #e6e6e6; border-radius: 6px; padding: 4px;
            box-shadow: 0 6px 24px rgba(0,0,0,.12); display: none;
        }
        .prd-multi.open .prd-multi-menu { display: block; }
        .prd-multi-item {
            display: flex; align-items: center; gap: 9px; padding: 7px 10px; border-radius: 4px;
            font-size: 13px; color: #262626; cursor: pointer; user-select: none; white-space: nowrap;
        }
        .prd-multi-item:hover { background: #f5f7fa; }
        .prd-multi-item input { width: 15px; height: 15px; accent-color: #1890ff; cursor: pointer; flex-shrink: 0; }

        /* Bảng — cùng khuôn với mọi trang danh sách khác: rộng hết khung, mọi ô
           canh giữa, bề rộng khai theo % và cộng đúng 100%. Để một cột không có
           width là cột đó nuốt hết phần dư, các cột còn lại dồn cục. */
        .prd-table-wrap { width: 100%; overflow-x: auto; padding: 0 20px; }
        .prd-table { width: 100%; min-width: 1400px; border-collapse: collapse; font-size: 13px; table-layout: fixed; }
        .prd-table thead tr { background: #f0f0f0; color: #262626; }
        .prd-table th, .prd-table td { padding: 14px 10px; vertical-align: middle; white-space: nowrap; text-align: center; }
        .prd-table th { font-weight: 700; }
        .prd-table tbody tr { border-bottom: 1px solid #f0f0f0; }
        .prd-table tbody tr:hover { background: #fafafa; }
        .prd-table tbody tr.is-selected, .prd-table tbody tr.is-selected:hover { background: #e6f7ff; }

        .prd-table th.prd-c-check,  .prd-table td.prd-c-check  { width: 3%; }
        .prd-table th.prd-c-stt,    .prd-table td.prd-c-stt    { width: 3%; }
        .prd-table th.prd-c-sku,    .prd-table td.prd-c-sku    { width: 11%; overflow: hidden; text-overflow: ellipsis; }
        .prd-table th.prd-c-name,   .prd-table td.prd-c-name   { width: 20%; overflow: hidden; text-overflow: ellipsis; }
        .prd-table th.prd-c-cat,    .prd-table td.prd-c-cat    { width: 11%; overflow: hidden; text-overflow: ellipsis; }
        .prd-table th.prd-c-vat,    .prd-table td.prd-c-vat    { width: 5%; font-variant-numeric: tabular-nums; }
        .prd-table th.prd-c-unit,   .prd-table td.prd-c-unit   { width: 6%; }
        .prd-table th.prd-c-price,  .prd-table td.prd-c-price  { width: 9%; }
        .prd-table th.prd-c-branch, .prd-table td.prd-c-branch { width: 10%; overflow: hidden; text-overflow: ellipsis; }
        .prd-table th.prd-c-status, .prd-table td.prd-c-status { width: 8%; }
        .prd-table th.prd-c-act,    .prd-table td.prd-c-act    { width: 14%; }

        /* Tiêu đề cột bấm được để sắp xếp */
        .prd-th-sort {
            display: inline-flex; align-items: center; gap: 4px; color: inherit; text-decoration: none;
            font-weight: 700; transition: color .15s;
        }
        .prd-th-sort svg { color: #bfbfbf; flex-shrink: 0; transition: color .15s; }
        .prd-th-sort:hover, .prd-th-sort:hover svg { color: #1890ff; }
        .prd-th-sort.is-on svg { color: #1890ff; }

        .prd-check { width: 16px; height: 16px; cursor: pointer; accent-color: #1890ff; }
        .prd-name { display: block; font-weight: 500; color: #262626; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .prd-c-price { font-variant-numeric: tabular-nums; }
        .prd-sub { display: block; margin-top: 2px; font-size: 12px; color: #8c8c8c; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .prd-code { font-variant-numeric: tabular-nums; letter-spacing: .2px; color: #595959; }
        .prd-muted { color: #bfbfbf; }

        .prd-price-sale { display: block; font-weight: 600; color: #cf1322; }
        .prd-price-base { display: block; font-size: 12px; color: #bfbfbf; text-decoration: line-through; }
        /* Khi không có giá KM, giá gốc dùng màu thường (không đỏ) */
        .prd-c-price .prd-price-sale:only-child { color: #262626; font-weight: 600; }

        .prd-badge {
            display: inline-block; border-radius: 4px; background: #f0f5ff; color: #1d39c4;
            padding: 2px 8px; font-size: 12px; font-weight: 500; white-space: nowrap;
        }
        /* Switch trạng thái */
        .prd-switch {
            position: relative; display: inline-flex; align-items: center; height: 22px; width: 42px; border: 0;
            border-radius: 9999px; background: #ddd; cursor: pointer; padding: 0; transition: background .15s;
        }
        .prd-switch.on { background: #7083b6; }
        .prd-switch-knob {
            display: inline-block; height: 16px; width: 16px; border-radius: 50%; background: #fff;
            box-shadow: 0 0 3px rgba(0,0,0,.3); transform: translateX(3px); transition: transform .15s;
        }
        .prd-switch.on .prd-switch-knob { transform: translateX(23px); }

        .prd-rowacts { display: inline-flex; align-items: center; gap: 6px; }
        .prd-rowbtn {
            width: 30px; height: 30px; border: 0; background: none; border-radius: 4px; padding: 0;
            cursor: pointer; color: #595959; display: inline-flex; align-items: center; justify-content: center;
            transition: background .15s, color .15s;
        }
        .prd-rowbtn.prd-move { color: #8c8c8c; width: 24px; }
        .prd-rowbtn.prd-move:hover { background: #f0f5ff; color: #1890ff; }
        .prd-rowbtn.prd-move.is-off { color: #d9d9d9; cursor: default; }
        .prd-rowbtn.prd-move.is-off:hover { background: none; color: #d9d9d9; }
        .prd-rowbtn.prd-copy { color: #52c41a; }
        .prd-rowbtn.prd-copy:hover { background: #f6ffed; }
        .prd-rowbtn.prd-edit { color: #1890ff; }
        .prd-rowbtn.prd-edit:hover { background: #e6f7ff; }
        .prd-rowbtn.prd-del { color: #ff4d4f; }
        .prd-rowbtn.prd-del:hover { background: #fff1f0; }

        .prd-empty { padding: 48px 12px; text-align: center; color: #8c8c8c; }

        /* Vòng focus bàn phím đồng bộ cho các nút (cùng màu xanh hệ thống) */
        .prd-btn-primary:focus-visible, .prd-btn-ghost:focus-visible,
        .prd-search-btn:focus-visible { outline: none; box-shadow: 0 0 0 .25rem rgba(13,110,253,.25); }
        /* Mã hàng & tên hàng bấm được để xem chi tiết */
        .prd-c-name[data-view], .prd-c-sku[data-view] { cursor: pointer; }
        .prd-c-name[data-view]:hover .prd-name,
        .prd-c-sku[data-view]:hover .prd-code {
            color: #1890ff;
            text-decoration: underline;
        }

        /* Thanh bulk nổi (căn giữa vùng nội dung, bù sidebar như trang Danh mục) */
        .prd-bulk {
            position: fixed; bottom: 24px; left: 230px; right: 0; margin-inline: auto; z-index: 1070;
            width: max-content; max-width: calc(100vw - 260px);
            display: flex; align-items: center; gap: 16px; border: 1px solid #e6e6e6; border-radius: 9999px;
            background: #fff; padding: 12px 20px; box-shadow: 0 6px 24px rgba(0,0,0,.15);
        }
        body:has(.jh-sidebar.collapsed) .prd-bulk { left: 48px; }
        @media (max-width: 820px) { .prd-bulk { left: 0; } }
        .prd-bulk-text { font-size: 13px; font-weight: 500; color: #262626; }
        .prd-bulk-clear { border: 0; background: none; font-size: 13px; color: #8c8c8c; cursor: pointer; transition: color .15s; }
        .prd-bulk-clear:hover { color: #262626; }
        .prd-bulk-del {
            display: inline-flex; align-items: center; gap: 6px; border: 0; border-radius: 9999px; background: #ff4d4f;
            padding: 6px 16px; font-size: 13px; font-weight: 500; color: #fff; cursor: pointer; transition: background .15s;
        }
        .prd-bulk-del:hover { background: #ff7875; }

        /* ---- Modal thêm/sửa ---- */
        .prd-overlay { position: fixed; inset: 0; z-index: 1080; display: flex; align-items: center; justify-content: center; padding: 16px; background: rgba(0,0,0,.4); }
        .prd-dialog {
            /* v2 để hộp thoại rộng 95% màn hình vì tab Chi tiết chia hai cột. */
            max-height: 92vh; width: 100%; max-width: min(1180px, 95vw); overflow-y: auto; border-radius: 6px;
            background: #fff; box-shadow: 0 10px 40px rgba(0,0,0,.2);
            scrollbar-width: thin; scrollbar-color: #d0d0d0 transparent;
        }
        /* Thanh cuộn đẹp — modal & bảng (WebKit + Firefox) */
        .prd-dialog::-webkit-scrollbar { width: 11px; }
        .prd-dialog::-webkit-scrollbar-track { background: transparent; }
        .prd-dialog::-webkit-scrollbar-thumb { background-color: #dcdcdc; border-radius: 8px; border: 3px solid #fff; }
        .prd-dialog::-webkit-scrollbar-thumb:hover { background-color: #b3b3b3; }
        .prd-table-wrap { scrollbar-width: thin; scrollbar-color: #d0d0d0 transparent; }
        .prd-table-wrap::-webkit-scrollbar { height: 11px; }
        .prd-table-wrap::-webkit-scrollbar-track { background: transparent; }
        .prd-table-wrap::-webkit-scrollbar-thumb { background-color: #dcdcdc; border-radius: 8px; border: 3px solid #fff; }
        .prd-table-wrap::-webkit-scrollbar-thumb:hover { background-color: #b3b3b3; }
        .prd-modal-head {
            position: sticky; top: 0; z-index: 2; display: flex; align-items: center; justify-content: space-between;
            border-bottom: 1px solid #f0f0f0; background: #fff; padding: 12px 20px;
        }
        .prd-modal-title { margin: 0; font-size: 15px; font-weight: 700; color: #262626; }
        .prd-modal-x { border: 0; background: none; padding: 0; color: #8c8c8c; cursor: pointer; display: inline-flex; transition: color .15s; }
        .prd-modal-x:hover { color: #262626; }
        .prd-modal-body { padding: 16px 20px; display: flex; flex-direction: column; gap: 14px; }
        /* Tab Chi tiết chia hai cột như bản cũ: cột trái ảnh + công tắc, cột phải
           lưới BỐN ô mỗi hàng. */
        .prd-two { display: grid; grid-template-columns: 250px minmax(0, 1fr); gap: 20px; align-items: start; }
        .prd-side { display: flex; flex-direction: column; gap: 10px; }
        .prd-side-title { margin: 0; text-align: center; font-size: 13px; font-weight: 500; color: #262626; }
        .prd-side .prd-img-preview { width: 100%; height: 190px; border-radius: 4px; }
        /* Nút "Tải lên" chạy hết bề ngang ô ảnh, đúng như bản cũ. */
        .prd-upload-btn {
            width: 100%; height: 34px; border: 1px solid #d9d9d9; border-radius: 4px; background: #fff;
            font-size: 13px; color: #595959; cursor: pointer; transition: border-color .15s, color .15s;
        }
        .prd-upload-btn:hover { border-color: #1890ff; color: #1890ff; }
        /* Khung có viền bao các công tắc — bản cũ gom chúng vào một hộp riêng. */
        .prd-side-box {
            border: 1px solid #d9d9d9; border-radius: 6px; padding: 10px 12px;
            display: flex; flex-direction: column; gap: 12px;
        }
        .prd-side-row { display: flex; align-items: center; justify-content: space-between; gap: 10px; }
        .prd-body { min-width: 0; display: flex; flex-direction: column; gap: 14px; }
        .prd-grid4 { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; }
        /* Định mức tồn: v2 gộp min và max vào một ô, giữ nguyên. */
        .prd-col-4 { grid-column: span 4; }

        /* Ô chọn nhiều trong hộp thoại (chi nhánh, thẻ). Cùng cách bày với ô lọc
           nhiều mục ngoài thanh lọc, chỉ rộng bằng ô nhập cạnh nó. */
        .prd-pick { position: relative; }
        .prd-pick-btn {
            width: 100%; height: 34px; display: flex; align-items: center; justify-content: space-between; gap: 8px;
            border: 1px solid #d9d9d9; border-radius: 4px; background: #fff; padding: 0 10px 0 12px;
            font-size: 13px; color: #262626; cursor: pointer; transition: border-color .15s;
        }
        .prd-pick-btn:hover, .prd-pick.open .prd-pick-btn { border-color: #1890ff; }
        .prd-pick-btn[disabled] { background: #fafafa; color: #8c8c8c; cursor: default; }
        /* Chưa chọn gì thì chữ xám như placeholder — cùng quy ước với ô chọn. */
        .prd-pick-text { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .prd-pick.is-empty .prd-pick-text { color: #9ca3af; }
        .prd-pick-pop {
            position: absolute; left: 0; right: 0; top: calc(100% + 4px); max-height: 260px; overflow-y: auto;
            z-index: 1060; background: #fff; border: 1px solid #e6e6e6; border-radius: 6px; padding: 4px;
            box-shadow: 0 6px 24px rgba(0,0,0,.12); display: none;
        }
        .prd-pick.open .prd-pick-pop { display: block; }
        .prd-pick-item {
            display: flex; align-items: center; gap: 9px; padding: 7px 10px; border-radius: 4px;
            font-size: 13px; color: #262626; cursor: pointer; user-select: none;
        }
        .prd-pick-item:hover { background: #f5f7fa; }
        .prd-pick-item input { width: 15px; height: 15px; accent-color: #1890ff; cursor: pointer; flex-shrink: 0; }
        .prd-pick-empty { padding: 8px 10px; font-size: 12px; color: #8c8c8c; }
        .prd-pick-new { display: flex; align-items: center; gap: 6px; padding: 6px; border-top: 1px solid #f0f0f0; }
        /* Ô nhập ăn hết phần thừa, nút Thêm CAO BẰNG ô — hai mực cao khác nhau
           thì nút trông như bị lọt thỏm vào giữa hàng. */
        .prd-pick-new .prd-input-sm { flex: 1; min-width: 0; height: 30px; font-size: 12px; }
        .prd-pick-new .prd-btn-xs { height: 30px; flex-shrink: 0; }

        /* Khối xếp gọn ở chân cột phải — chỗ v2 để accordion "Quy đổi đơn vị". */
        .prd-acc { border: 1px solid #f0f0f0; border-radius: 6px; }
        .prd-acc > summary {
            cursor: pointer; list-style: none; padding: 9px 12px; font-size: 13px; font-weight: 600;
            color: #595959; user-select: none;
        }
        .prd-acc > summary::-webkit-details-marker { display: none; }
        .prd-acc > summary::before { content: '▸ '; color: #bfbfbf; }
        .prd-acc[open] > summary::before { content: '▾ '; }
        .prd-acc > summary:hover { color: #1890ff; }
        .prd-acc-body { padding: 0 12px 12px; }
        /* Nút Thêm / xoá hết nằm ngay trên thanh tiêu đề khối, như bản cũ. */
        .prd-acc-tools { float: right; display: inline-flex; align-items: center; gap: 6px; }
        .prd-btn-xs { height: 24px; padding: 0 10px; font-size: 12px; border-radius: 4px; }
        .prd-btn-x {
            width: 24px; height: 24px; border: 1px solid #ffccc7; border-radius: 4px; background: #fff;
            color: #ff4d4f; font-size: 16px; line-height: 1; cursor: pointer;
        }
        .prd-btn-x:hover { background: #fff1f0; }

        /* Bảng quy đổi: 1 <ĐV quy đổi> = <SL> <ĐVT chính> */
        .prd-conv-head, .prd-conv-row {
            display: grid; grid-template-columns: 90px 1fr 20px 1fr 110px 34px; gap: 8px; align-items: center;
        }
        .prd-conv-head { font-size: 12px; font-weight: 600; color: #8c8c8c; padding: 0 2px 4px; }
        .prd-conv-row { margin-bottom: 8px; }
        .prd-conv-row .prd-input, .prd-conv-row .prd-msel { height: 34px; }
        .prd-conv-eq { text-align: center; color: #8c8c8c; }
        .prd-conv-main { font-size: 13px; color: #595959; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .prd-conv-del {
            height: 34px; width: 34px; border: 1px solid #ffccc7; border-radius: 4px; background: #fff;
            color: #ff4d4f; font-size: 18px; line-height: 1; cursor: pointer;
        }
        .prd-conv-del:hover { background: #fff1f0; }
        .prd-conv-empty { margin: 4px 0 0; font-size: 12px; color: #bfbfbf; }

        .prd-grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .prd-field-label { display: block; margin-bottom: 4px; font-size: 13px; font-weight: 500; color: #262626; }
        .prd-req { color: #ff4d4f; }
        .prd-input, .prd-textarea, .prd-msel {
            width: 100%; border: 1px solid #d9d9d9; border-radius: 4px; padding: 0 12px; font-size: 13px; outline: none; transition: border-color .15s;
            font-family: inherit; color: #262626; background: #fff;
        }
        .prd-input { height: 36px; }
        .prd-textarea { padding: 8px 12px; min-height: 64px; resize: vertical; line-height: 1.5; }
        .prd-input::placeholder, .prd-textarea::placeholder { color: #bfbfbf; }
        .prd-input:focus, .prd-textarea:focus, .prd-msel:focus { border-color: #86b7fe; box-shadow: 0 0 0 .25rem rgba(13,110,253,.25); }
        .prd-input:disabled { background: #f5f5f5; color: #8c8c8c; cursor: not-allowed; }
        .prd-msel {
            height: 36px; cursor: pointer; padding-right: 32px;
            appearance: none; -webkit-appearance: none;
            background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23595959' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><path d='m6 9 6 6 6-6'/></svg>");
            background-repeat: no-repeat; background-position: right 10px center;
        }
        .prd-dialog-sm { max-width: 420px; }

        .prd-input-prefix { position: relative; }
        .prd-input-prefix .prd-input { padding-right: 34px; }
        .prd-input-suffix { position: absolute; right: 12px; top: 0; height: 36px; display: inline-flex; align-items: center; font-size: 13px; color: #8c8c8c; pointer-events: none; }
        .prd-hint { margin: 4px 0 0; font-size: 11px; color: #8c8c8c; }

        .prd-img-preview {
            width: 84px; height: 84px; flex-shrink: 0; border: 1px solid #d9d9d9; border-radius: 8px;
            overflow: hidden; display: flex; align-items: center; justify-content: center;
            background: #fafafa; color: #bfbfbf; position: relative; transition: opacity .15s;
        }
        .prd-img-preview img { width: 100%; height: 100%; object-fit: cover; }
        .prd-img-preview.is-loading { opacity: .5; }
        .prd-img-preview.is-loading::after {
            content: ''; position: absolute; width: 22px; height: 22px; border-radius: 50%;
            border: 2px solid #d9d9d9; border-top-color: #1890ff; animation: prdspin .7s linear infinite;
        }
        @keyframes prdspin { to { transform: rotate(360deg); } }
        .prd-img-remove { color: #ff4d4f; }
        .prd-img-remove:hover { border-color: #ffa39e; }

        .prd-switch-label { font-size: 13px; color: #595959; }

        /* Biến thể — tổ hợp thuộc tính */
        .prd-var { display: flex; flex-direction: column; gap: 10px; }
        #mVariants { display: flex; flex-direction: column; gap: 10px; }

        /* Khối chọn thuộc tính: mỗi thuộc tính một hàng, tick giá trị nào thì
           giá trị đó vào tổ hợp. Nguồn là màn Hàng hóa → Thuộc tính. */
        .prd-attrs {
            display: flex; flex-direction: column; gap: 10px;
            padding: 12px; background: #f7f9fc; border: 1px solid #eef0f4; border-radius: 6px;
        }
        .prd-attr-row { display: flex; align-items: flex-start; gap: 12px; }
        .prd-attr-name {
            flex: 0 0 130px; padding-top: 4px; font-size: 13px; font-weight: 600; color: #262626;
            overflow: hidden; text-overflow: ellipsis;
        }
        .prd-attr-vals { display: flex; flex-wrap: wrap; gap: 6px; flex: 1; }
        .prd-attr-val {
            display: inline-flex; align-items: center; gap: 6px; border: 1px solid #d9d9d9; border-radius: 14px;
            background: #fff; padding: 3px 11px 3px 8px; font-size: 12px; color: #595959; cursor: pointer;
            user-select: none; transition: border-color .15s, background .15s, color .15s;
        }
        .prd-attr-val:hover { border-color: #91caff; }
        .prd-attr-val input { width: 14px; height: 14px; accent-color: #1890ff; cursor: pointer; }
        .prd-attr-val.is-on { border-color: #1890ff; background: #e6f7ff; color: #0958d9; }
        .prd-attrs-foot { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
        .prd-attrs-empty { margin: 0; font-size: 12px; color: #8c8c8c; line-height: 1.6; }

        .prd-var-head, .prd-var-row {
            display: grid; grid-template-columns: 1.2fr .9fr 1fr .9fr .9fr .6fr 36px; gap: 10px; align-items: center;
        }
        /* Tên biến thể do tổ hợp thuộc tính quyết định — hiện để đối chiếu, không gõ tay. */
        .prd-var-name {
            height: 36px; display: inline-flex; align-items: center; padding: 0 10px;
            border: 1px solid #eef0f4; border-radius: 4px; background: #f7f9fc; color: #262626;
            font-size: 13px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
        }
        .prd-var-name.is-plain { color: #8c8c8c; font-style: italic; }
        /* Tồn kho chỉ để xem — nghiệp vụ kho mới được đổi, nên không phải ô nhập */
        .prd-var-stock {
            height: 36px; display: inline-flex; align-items: center; justify-content: flex-end;
            padding: 0 10px; border: 1px solid #eef0f4; border-radius: 4px;
            background: #f7f9fc; color: #8c8c8c; font-variant-numeric: tabular-nums;
        }
        .prd-var-stock.is-zero { color: #ff4d4f; }
        .prd-var-head { font-size: 12px; font-weight: 600; color: #8c8c8c; padding: 0 2px 2px; }
        .prd-var-row .prd-input, .prd-var-row .prd-msel { height: 36px; }
        .prd-var-row .prd-msel { padding-right: 28px; background-position: right 8px center; }
        .prd-var-row .prd-input-prefix .prd-input-suffix { height: 36px; }
        .prd-var-del {
            height: 36px; width: 36px; flex-shrink: 0; border: 1px solid #ffccc7; border-radius: 4px;
            background: #fff; color: #ff4d4f; font-size: 20px; line-height: 1; cursor: pointer;
            display: inline-flex; align-items: center; justify-content: center; transition: background .15s;
        }
        .prd-var-del:hover { background: #fff1f0; }
        #mVariants:empty { display: none; }

        .prd-modal-foot {
            position: sticky; bottom: 0; display: flex; justify-content: center; gap: 8px;
            border-top: 1px solid #f0f0f0; background: #fff; padding: 12px 20px;
        }
        .prd-btn-ghost {
            height: 34px; border: 1px solid #d9d9d9; border-radius: 4px; padding: 0 16px; font-size: 13px; font-weight: 500;
            color: #595959; background: #fff; cursor: pointer; transition: border-color .15s;
        }
        .prd-btn-ghost:hover { border-color: #bfbfbf; }

        /* ===== Trạng thái kinh doanh (3 mức) ===== */
        .prd-status {
            display: inline-flex; align-items: center; gap: 6px; height: 26px; padding: 0 8px;
            border: 1px solid #d9d9d9; border-radius: 13px; background: #fff; cursor: pointer;
            font-size: 12px; font-weight: 500; color: #595959; white-space: nowrap; transition: border-color .15s, background .15s;
        }
        .prd-status.is-static { cursor: default; }
        .prd-status-dot { width: 7px; height: 7px; border-radius: 50%; flex-shrink: 0; background: #bfbfbf; }
        /* Xanh = đang bán, vàng = tạm ẩn, xám = ngừng kinh doanh. Cùng bộ màu với
           ô tồn kho để cả bảng đọc theo một quy ước. */
        .prd-status-active .prd-status-dot       { background: #52c41a; }
        .prd-status-hidden .prd-status-dot       { background: #faad14; }
        .prd-status-discontinued .prd-status-dot { background: #bfbfbf; }
        .prd-status-active { border-color: #d9f7be; background: #f6ffed; color: #389e0d; }
        .prd-status-hidden { border-color: #ffe7ba; background: #fffbe6; color: #d46b08; }
        .prd-status-discontinued { border-color: #e8e8e8; background: #fafafa; color: #8c8c8c; }

        /* Tên chương trình khuyến mãi dưới ô giá */
        .prd-price-promo {
            display: inline-flex; align-items: center; gap: 3px; margin-top: 2px;
            font-size: 11px; color: #d4380d; max-width: 160px;
        }
        .prd-price-promo svg { flex-shrink: 0; }

        /* Dòng đang chờ tải lại dữ liệu trước khi mở modal */
        tr.is-loading { opacity: .55; pointer-events: none; }

        /* ===== Danh sách dòng lỗi của file nhập ===== */
        .prd-import-errors {
            border: 1px solid #ffccc7; border-radius: 8px; background: #fff2f0; padding: 12px 16px; margin-bottom: 14px;
        }
        .prd-import-errors-head {
            display: flex; align-items: center; gap: 6px; margin: 0 0 6px;
            font-size: 13px; font-weight: 600; color: #cf1322;
        }
        .prd-import-errors ul { margin: 0; padding-left: 20px; }
        .prd-import-errors li { font-size: 12px; color: #595959; line-height: 1.7; }
        .prd-import-errors-more { margin: 6px 0 0; font-size: 12px; color: #8c8c8c; }

        /* ===== Modal: đầu, tab, lỗi từng ô, chân ===== */
        .prd-modal-headmain { display: flex; flex-direction: column; gap: 3px; }
        .prd-modal-sub { display: flex; align-items: center; gap: 7px; font-size: 12px; color: #8c8c8c; }
        .prd-modal-sep { color: #d9d9d9; }
        .prd-modal-sub .prd-status { height: 20px; padding: 0 7px; font-size: 11px; }

        .prd-tabs {
            position: sticky; top: 57px; z-index: 2; display: flex; gap: 2px;
            border-bottom: 1px solid #f0f0f0; background: #fff; padding: 0 20px;
        }
        .prd-tab {
            display: inline-flex; align-items: center; gap: 7px; border: 0; border-bottom: 2px solid transparent;
            background: none; padding: 11px 14px; font-size: 13px; font-weight: 500; color: #8c8c8c;
            cursor: pointer; transition: color .15s, border-color .15s;
        }
        .prd-tab:hover { color: #262626; }
        .prd-tab.is-active { color: #1890ff; border-bottom-color: #1890ff; }
        /* Tab có ô sai thì sáng đỏ, không phải mò từng tab để tìm */
        .prd-tab.has-err { color: #cf1322; border-bottom-color: #ffa39e; }

        .prd-panel { display: none; flex-direction: column; gap: 14px; }
        .prd-panel.is-active { display: flex; }

        .prd-err { margin: 4px 0 0; font-size: 11.5px; color: #cf1322; }
        .prd-err:empty { display: none; }
        .prd-input.is-err, .prd-msel.is-err { border-color: #ff4d4f; }
        .prd-input.is-err:focus, .prd-msel.is-err:focus { border-color: #ff4d4f; }

        /* Xem trước giá — con số khách thực trả, khỏi phải tự suy từ 3 ô giá */
        .prd-price-preview { display: flex; flex-wrap: wrap; align-items: center; gap: 22px; }
        .prd-price-preview:empty { display: none; }
        .prd-price-preview {
            border: 1px solid #f0f0f0; border-radius: 6px; background: #fafafa; padding: 10px 14px;
        }
        .prd-pp-item { display: flex; flex-direction: column; gap: 1px; }
        .prd-pp-item small { font-size: 11px; color: #8c8c8c; }
        .prd-pp-item b { font-size: 14px; font-weight: 700; color: #262626; }
        .prd-pp-loss { color: #cf1322 !important; }
        .prd-pp-note { font-size: 11.5px; color: #cf1322; }

        .prd-modal-foot { justify-content: center; align-items: center; gap: 16px; }
        .prd-foot-btns { display: flex; gap: 8px; flex-shrink: 0; }
        .prd-foot-msg { margin: 0; font-size: 12px; color: #8c8c8c; }
        .prd-foot-msg.is-err { color: #cf1322; }
        .prd-foot-msg:empty { display: none; }

        @media (max-width: 1100px) {
            .prd-grid4 { grid-template-columns: 1fr 1fr; }
            .prd-col-4 { grid-column: span 2; }
        }
        @media (max-width: 900px) {
            /* Hết chỗ cho hai cột: cột ảnh lên trên, lưới ô nhập xuống dưới. */
            .prd-two { grid-template-columns: 1fr; }
            .prd-side { flex-direction: row; flex-wrap: wrap; align-items: flex-end; }
            .prd-side .prd-img-preview { width: 140px; height: 140px; }
            .prd-side-box { flex: 1; min-width: 200px; }
        }
        @media (max-width: 560px) {
            .prd-grid4 { grid-template-columns: 1fr; }
            .prd-col-4 { grid-column: span 1; }
        }
        @media (max-width: 720px) {
            .prd-tabs { overflow-x: auto; padding: 0 10px; }
            .prd-tab { padding: 11px 10px; white-space: nowrap; }
        }
        @media (max-width: 560px) {
            .prd-grid2 { grid-template-columns: 1fr; }
        }
    </style>
