{{-- Bộ style .ct-* dùng CHUNG cho hai trang: Yêu cầu của khách
     (contacts/index) và Đăng ký nhận tin (contacts/newsletter).

     Trước đây khối này nằm trong contacts/index, còn trang Đăng ký nhận tin
     chỉ dùng lại tên class — mà style của mỗi trang nằm ngay trong view của
     trang đó, nên trang kia mở ra là mất sạch khung, bảng và thanh lọc. --}}
    <style>
        /* ===== Trang Yêu cầu của khách ===== */
        .ct { padding: 0 0 90px; }

        .ct-head { display: flex; align-items: baseline; gap: 14px; flex-wrap: wrap; margin-bottom: 14px; }
        .ct-title { margin: 0; font-size: 20px; font-weight: 700; color: #262626; }
        .ct-sum { font-size: 13px; color: #8c8c8c; }
        .ct-sum b { color: #262626; }
        .ct-sum .ct-hot { color: #fa8c16; }

        .ct-live {
            display: flex; align-items: center; gap: 10px; width: 100%; margin-bottom: 14px;
            padding: 11px 16px; border: 1px solid #ffe58f; background: #fffbe6; border-radius: 6px;
            font-size: 13px; color: #614700; text-decoration: none; text-align: left;
        }
        .ct-live b { color: #ad6800; }
        .ct-live-dot { width: 8px; height: 8px; border-radius: 50%; background: #fa8c16; flex: 0 0 auto; }
        .ct-live-cta { margin-left: auto; font-weight: 600; color: #1890ff; }

        /* --- Thanh lọc --- */
        .ct-toolbar { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 16px; }
        .ct-searchbox { position: relative; flex: 1 1 320px; min-width: 240px; }
        .ct-search-input {
            width: 100%; height: 36px; padding: 0 38px 0 12px; font-size: 13px; font-family: inherit;
            border: 1px solid #d9d9d9; border-radius: 4px; outline: 0; color: #262626;
        }
        .ct-search-input:focus { border-color: #1890ff; }
        .ct-search-btn {
            position: absolute; right: 1px; top: 1px; width: 34px; height: 34px; border: 0; background: none;
            color: #8c8c8c; cursor: pointer; display: flex; align-items: center; justify-content: center;
        }
        .ct-select {
            height: 36px; padding: 0 30px 0 12px; font-size: 13px; font-family: inherit; color: #262626;
            border: 1px solid #d9d9d9; border-radius: 4px; background: #fff; cursor: pointer;
        }
        .ct-clear { font-size: 13px; color: #1890ff; text-decoration: none; }
        .ct-clear:hover { text-decoration: underline; }
        .ct-toolbar-actions { margin-left: auto; display: flex; gap: 10px; }

        .ct-util { position: relative; }
        .ct-util-btn {
            display: flex; align-items: center; gap: 7px; height: 36px; padding: 0 13px; font-size: 13px;
            font-family: inherit; color: #262626; background: #fff; border: 1px solid #d9d9d9;
            border-radius: 4px; cursor: pointer;
        }
        .ct-util-btn:hover { border-color: #1890ff; color: #1890ff; }
        .ct-util-caret { transition: transform .18s; }
        .ct-util.open .ct-util-caret { transform: rotate(180deg); }
        .ct-util-menu {
            position: absolute; right: 0; top: calc(100% + 6px); min-width: 190px; background: #fff;
            border: 1px solid #e8e8e8; border-radius: 6px; box-shadow: 0 6px 20px rgba(0, 0, 0, .1);
            padding: 5px; display: none; z-index: 30;
        }
        .ct-util.open .ct-util-menu { display: block; }
        .ct-util-item {
            display: flex; align-items: center; gap: 9px; padding: 9px 11px; font-size: 13px;
            color: #262626; text-decoration: none; border-radius: 4px;
        }
        .ct-util-item:hover { background: #f5f5f5; }

        /* --- Bảng --- */
        .ct-table-wrap { border: 1px solid #f0f0f0; border-radius: 6px; overflow-x: auto; background: #fff; }
        .ct-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .ct-table thead th {
            padding: 13px 18px; font-size: 12.5px; font-weight: 600; color: #8c8c8c; white-space: nowrap;
            background: #fafafa; border-bottom: 1px solid #f0f0f0;
        }
        .ct-table tbody td { padding: 16px 18px; border-bottom: 1px solid #f5f5f5; color: #262626; vertical-align: middle; }
        .ct-table tbody tr:last-child td { border-bottom: 0; }
        .ct-table tbody tr:hover { background: #fafafa; }

        /* Mỗi cột width:1% và ĐÚNG MỘT cột co giãn (Nội dung) hút khoảng dư.
           th và td của cùng một cột phải khai CÙNG text-align. */
        .ct-c-stt    { width: 1%; text-align: center; }
        .ct-c-type   { width: 1%; text-align: left; white-space: nowrap; }
        .ct-c-cus    { width: 1%; text-align: left; white-space: nowrap; cursor: pointer; }
        .ct-c-body   { width: 100%; text-align: left; cursor: pointer; }
        .ct-c-img    { width: 1%; text-align: center; }
        .ct-c-status { width: 1%; text-align: center; }
        .ct-c-date   { width: 1%; text-align: right; white-space: nowrap; }
        .ct-c-act    { width: 1%; text-align: right; white-space: nowrap; }

        .ct-name { display: block; font-weight: 600; color: #262626; }
        .ct-sub { display: block; margin-top: 3px; font-size: 12.5px; color: #8c8c8c; }

        .ct-tag {
            display: inline-block; padding: 3px 9px; border-radius: 3px; font-size: 12px; font-weight: 600;
        }
        .ct-tag--lien-he { background: #e6f7ff; color: #096dd9; }
        .ct-tag--thu-mua { background: #f9f0ff; color: #531dab; }

        .ct-imgcount { display: inline-block; min-width: 22px; color: #bfbfbf; font-weight: 600; }
        .ct-imgcount.has { color: #262626; }

        .ct-badge { display: inline-block; padding: 3px 10px; border-radius: 10px; font-size: 12px; font-weight: 600; }
        .ct-badge.tone-warn { background: #fff7e6; color: #ad6800; }
        .ct-badge.tone-info { background: #e6f7ff; color: #096dd9; }
        .ct-badge.tone-ok   { background: #f6ffed; color: #389e0d; }

        .ct-actions { display: flex; align-items: center; justify-content: flex-end; gap: 6px; }
        .ct-rowbtn {
            display: inline-flex; align-items: center; justify-content: center; gap: 5px; height: 30px;
            min-width: 30px; padding: 0 8px; font-size: 12.5px; font-family: inherit; color: #595959;
            background: #fff; border: 1px solid #d9d9d9; border-radius: 4px; cursor: pointer;
        }
        .ct-rowbtn:hover { border-color: #1890ff; color: #1890ff; }
        .ct-rowbtn--go { border-color: #1890ff; color: #1890ff; font-weight: 600; }
        .ct-rowbtn--go:hover { background: #1890ff; color: #fff; }
        .ct-rowbtn--back { color: #8c8c8c; }
        .ct-rowbtn--del:hover { border-color: #ff4d4f; color: #ff4d4f; }

        .ct-empty { padding: 46px 20px; text-align: center; color: #8c8c8c; font-size: 13px; }

        /* --- Modal chi tiết --- */
        .ct-overlay {
            position: fixed; inset: 0; background: rgba(0, 0, 0, .45); z-index: 1050;
            display: flex; align-items: center; justify-content: center; padding: 20px;
        }
        .ct-dialog {
            width: 100%; max-width: 640px; max-height: calc(100vh - 40px); background: #fff;
            border-radius: 8px; display: flex; flex-direction: column; overflow: hidden;
        }
        .ct-modal-head {
            display: flex; align-items: center; justify-content: space-between; gap: 12px;
            padding: 16px 20px; border-bottom: 1px solid #f0f0f0;
        }
        .ct-modal-title { margin: 0; font-size: 16px; font-weight: 600; color: #262626; }
        .ct-modal-x { border: 0; background: none; color: #8c8c8c; cursor: pointer; padding: 2px; display: flex; }
        .ct-modal-x:hover { color: #262626; }
        .ct-modal-body { padding: 18px 20px; overflow-y: auto; }
        .ct-modal-foot {
            display: flex; align-items: center; justify-content: center; gap: 8px;
            padding: 14px 20px; border-top: 1px solid #f0f0f0; background: #fafafa; flex-wrap: wrap;
        }

        .ct-info { display: grid; grid-template-columns: 116px 1fr; gap: 8px 14px; margin: 0 0 18px; font-size: 13px; }
        .ct-info dt { color: #8c8c8c; }
        .ct-info dd { margin: 0; color: #262626; word-break: break-word; }

        .ct-block { margin-bottom: 18px; }
        .ct-block:last-child { margin-bottom: 0; }
        .ct-block-label { display: block; margin-bottom: 7px; font-size: 12.5px; font-weight: 600; color: #8c8c8c; }
        .ct-content {
            margin: 0; padding: 12px 14px; background: #fafafa; border-radius: 5px; font-size: 13px;
            line-height: 1.7; color: #262626; white-space: pre-wrap; word-break: break-word;
        }
        .ct-images { display: flex; gap: 10px; flex-wrap: wrap; }
        .ct-images a { display: block; width: 84px; height: 84px; border-radius: 5px; overflow: hidden; border: 1px solid #f0f0f0; }
        .ct-images img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .ct-textarea {
            width: 100%; padding: 10px 12px; font-size: 13px; font-family: inherit; line-height: 1.6;
            color: #262626; border: 1px solid #d9d9d9; border-radius: 4px; outline: 0; resize: vertical;
        }
        .ct-textarea:focus { border-color: #1890ff; }

        .ct-btn-ghost, .ct-btn-status {
            height: 34px; padding: 0 15px; font-size: 13px; font-family: inherit; border-radius: 4px;
            cursor: pointer; border: 1px solid #d9d9d9; background: #fff; color: #262626;
        }
        .ct-btn-ghost { margin-right: auto; }
        .ct-btn-status.tone-warn:hover { border-color: #fa8c16; color: #ad6800; }
        .ct-btn-status.tone-info:hover { border-color: #1890ff; color: #096dd9; }
        .ct-btn-status.tone-ok:hover   { border-color: #52c41a; color: #389e0d; }
        /* Trạng thái hiện tại: làm mờ cho biết đang ở đó, nhưng KHÔNG disable —
           bấm vào vẫn lưu được phần ghi chú vừa gõ. */
        .ct-btn-status.is-current { opacity: .5; }

        @media (max-width: 900px) {
            .ct-toolbar-actions { margin-left: 0; }
            .ct-info { grid-template-columns: 1fr; gap: 3px; }
            .ct-info dt { margin-top: 8px; }
        }
    </style>
