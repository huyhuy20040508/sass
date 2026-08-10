@extends('layouts.app')

@section('title', 'Danh mục sản phẩm')

@section('content')
    {{--
        Màn "Nhóm hàng hóa" — port 1:1 GroupsSection của dự án OrderTable:
        [ cây loại lớn/nhóm bên trái ] + [ header (tiêu đề + tìm kiếm + Tạo nhóm) + bảng nhóm con + phân trang ]
        Modal tạo/sửa kèm bảng "Nhóm con"; thanh thao tác nổi (bulk) khi chọn nhiều dòng.
        Dữ liệu danh mục nạp phẳng, JS tự dựng cây theo parent_id và thao tác giống hệt bản React.
    --}}
    <div class="grp">
        {{-- ===== Cột trái: cây loại lớn -> nhóm -> nhóm con ===== --}}
        <aside class="grp-side">
            <div class="grp-side-head">
                <span class="grp-side-title">Nhóm danh mục</span>
                <div class="grp-side-tools" id="grpSideTools"></div>
            </div>
            <div class="grp-tree" id="grpTree"></div>
        </aside>

        {{-- ===== Cột phải: header + bảng ===== --}}
        <div class="grp-main">
            <div class="grp-head">
                <h1 class="grp-title" id="grpTitle">Danh mục sản phẩm</h1>
            </div>

            {{-- Thanh công cụ: tìm kiếm + lọc trạng thái + nút thêm (cùng một hàng) --}}
            <div class="grp-toolbar">
                <div class="grp-searchbox" id="grpSearchBox">
                    <input type="text" id="grpSearch" class="grp-search-input" placeholder="Tìm theo tên hoặc mã danh mục" autocomplete="off">
                    <button type="button" id="grpSearchBtn" class="grp-search-btn" title="Tìm kiếm">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                    </button>
                </div>

                <select id="grpStatusFilter" class="grp-status-filter" title="Lọc theo trạng thái">
                    <option value="all">Tất cả trạng thái</option>
                    <option value="active">Đang hoạt động</option>
                    <option value="inactive">Ngừng hoạt động</option>
                </select>

                <div class="grp-head-actions">
                    <button type="button" id="grpAddBtn" class="grp-btn-primary">Thêm danh mục</button>
                </div>
            </div>

            <div class="grp-table-wrap" id="grpTableWrap"></div>
            <div id="grpFooter"></div>
        </div>
    </div>

    <div id="grpModalMount"></div>
    <div id="grpBulkMount"></div>

    <style>
        .grp {
            display: flex; min-height: calc(100vh - 56px);
            /* Phá padding p-4 của <main> để module tràn viền (full-bleed) như một trang bình thường */
            margin: -1.5rem;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            color: #262626; background: #fff; overflow: hidden;
        }

        /* ---- Cột trái (cây) ---- */
        .grp-side { width: 260px; flex-shrink: 0; border-right: 1px solid #f0f0f0; background: #fff; }
        .grp-side-head {
            display: flex; align-items: center; justify-content: space-between; gap: 8px;
            border-bottom: 1px solid #f0f0f0; padding: 8px 12px;
        }
        .grp-side-title { font-size: 13px; font-weight: 700; color: #262626; }
        .grp-side-tools { display: flex; align-items: center; gap: 4px; }
        .grp-side-tools .grp-iconbtn { border: 0; background: none; border-radius: 4px; padding: 4px; cursor: pointer; display: inline-flex; transition: background .15s; }
        .grp-side-tools .grp-edit { color: #3f67d0; }
        .grp-side-tools .grp-edit:hover { background: #f0f5ff; }
        .grp-side-tools .grp-del { color: #ff0000; }
        .grp-side-tools .grp-del:hover { background: #fff1f0; }

        .grp-tree { max-height: calc(100vh - 220px); overflow-y: auto; padding: 4px 0; }
        .grp-node {
            display: flex; align-items: center; gap: 4px; width: 100%; border: 0; background: none;
            padding: 7px 8px 7px 8px; text-align: left; font-size: 13px; color: #595959; cursor: pointer; transition: background .12s, color .12s;
        }
        .grp-node:hover { background: #fafafa; }
        .grp-node.is-parent { font-weight: 500; color: #262626; }
        .grp-node.is-selected { background: #e6f7ff; font-weight: 700; color: #1890ff; }
        .grp-node.is-selected:hover { background: #e6f7ff; }
        .grp-caret { display: inline-flex; align-items: center; justify-content: center; width: 16px; height: 16px; flex-shrink: 0; color: #8c8c8c; }
        .grp-caret-spacer { display: inline-block; width: 16px; height: 16px; flex-shrink: 0; }
        .grp-node-name { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .grp-tree-empty { padding: 24px 12px; text-align: center; font-size: 12px; color: #bfbfbf; }

        /* ---- Cột phải ---- */
        .grp-main { min-width: 0; flex: 1; }
        .grp-head {
            display: flex; flex-wrap: nowrap; align-items: center; justify-content: space-between; gap: 16px;
            padding: 14px 20px;
        }
        .grp-title { margin: 0; font-size: 16px; font-weight: 700; color: #262626; line-height: 34px; display: flex; align-items: center; }

        /* Thanh công cụ dưới tiêu đề: tìm kiếm + lọc trạng thái */
        .grp-toolbar {
            display: flex; flex-wrap: wrap; align-items: center; gap: 12px;
            padding: 0 20px 12px; margin-bottom: 12px; border-bottom: 1px solid #eee;
        }
        .grp-status-filter {
            height: 34px; border: 1px solid #d9d9d9; border-radius: 4px; background: #fff;
            padding: 0 32px 0 12px; font-size: 13px; color: #262626; cursor: pointer; outline: none;
            transition: border-color .15s;
            appearance: none;
            background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23595959' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><path d='m6 9 6 6 6-6'/></svg>");
            background-repeat: no-repeat; background-position: right 8px center;
        }
        .grp-status-filter:focus { border-color: #1890ff; }

        .grp-searchbox { display: flex; border-radius: 4px; }
        .grp-searchbox:focus-within { box-shadow: 0 0 0 4px rgba(13,110,253,.25); }
        .grp-search-input {
            height: 34px; width: 190px; border: 1px solid #d9d9d9; border-right: 0; border-radius: 4px 0 0 4px;
            background: #fff; padding: 0 12px; font-size: 13px; outline: none; transition: border-color .15s;
        }
        .grp-search-input::placeholder { color: #bfbfbf; }
        .grp-searchbox:focus-within .grp-search-input,
        .grp-searchbox:focus-within .grp-search-btn { border-color: #86b7fe; }
        .grp-search-btn {
            height: 34px; width: 40px; display: inline-flex; align-items: center; justify-content: center;
            border: 1px solid #d9d9d9; border-radius: 0 4px 4px 0; background: #fafafa; color: #595959; cursor: pointer; transition: color .15s;
        }
        .grp-search-btn:hover { color: #1890ff; }

        .grp-head-actions { margin-left: auto; display: flex; align-items: center; gap: 8px; }
        .grp-btn-primary {
            height: 34px; border: 0; border-radius: 4px; background: #1890ff; color: #fff; padding: 0 16px;
            font-size: 13px; font-weight: 500; cursor: pointer; transition: background .15s;
        }
        .grp-btn-primary:hover { background: #40a9ff; }
        .grp-btn-primary:disabled { opacity: .5; cursor: default; }

        /* ---- Bảng ---- */
        .grp-table-wrap { position: relative; max-height: 661px; width: 100%; overflow: auto; border-left: 20px solid #fff; }
        .grp-table { width: 100%; border-collapse: collapse; font-size: 14px; table-layout: fixed; }
        .grp-table thead tr { background: #f0f0f0; color: #262626; }
        /* Padding + căn giữa theo chiều dọc áp CHUNG cho th & td -> mọi cột thẳng hàng */
        .grp-table th, .grp-table td { padding: 11px 8px; vertical-align: middle; white-space: nowrap; }
        .grp-table th { font-weight: 700; text-align: left; }
        /* Bề rộng + canh lề áp cả th lẫn td của cùng cột để header khớp thân bảng */
        .grp-table th.grp-c-check,  .grp-table td.grp-c-check  { width: 44px;  padding-left: 16px; padding-right: 16px; }
        .grp-table th.grp-c-stt,    .grp-table td.grp-c-stt    { width: 56px;  text-align: center; }
        .grp-table th.grp-c-code,   .grp-table td.grp-c-code   { width: 120px; }
        .grp-table th.grp-c-img,    .grp-table td.grp-c-img    { width: 72px;  text-align: center; }
        .grp-table th.grp-c-name,   .grp-table td.grp-c-name   { text-align: left; overflow: hidden; text-overflow: ellipsis; }
        .grp-table th.grp-c-status, .grp-table td.grp-c-status { width: 116px; text-align: center; }
        .grp-table th.grp-c-act,    .grp-table td.grp-c-act    { width: 120px; text-align: center; }
        .grp-table tbody tr { border-bottom: 1px solid #f0f0f0; }
        .grp-table tbody tr:hover { background: #fafafa; }
        .grp-table tbody tr.is-selected, .grp-table tbody tr.is-selected:hover { background: #e6f7ff; }
        .grp-code-badge { font-variant-numeric: tabular-nums; letter-spacing: .3px; color: #595959; }
        .grp-check { width: 16px; height: 16px; cursor: pointer; accent-color: #1890ff; }
        .grp-empty { padding: 48px 12px; text-align: center; color: #8c8c8c; }

        /* Thumbnail ảnh — cột riêng, căn giữa */
        .grp-thumb { width: 40px; height: 40px; border-radius: 6px; object-fit: cover; border: 1px solid #f0f0f0; vertical-align: middle; }
        .grp-thumb-empty { display: inline-flex; align-items: center; justify-content: center; width: 40px; height: 40px; border-radius: 6px; background: #f5f5f5; color: #bfbfbf; vertical-align: middle; }

        /* Switch trạng thái */
        .grp-switch {
            position: relative; display: inline-flex; align-items: center; height: 22px; width: 42px; border: 0;
            border-radius: 9999px; background: #ddd; cursor: pointer; padding: 0; transition: background .15s; vertical-align: middle;
        }
        .grp-switch.on { background: #7083b6; }
        .grp-switch-knob {
            display: inline-block; height: 16px; width: 16px; border-radius: 50%; background: #fff;
            box-shadow: 0 0 3px rgba(0,0,0,.3); transform: translateX(3px); transition: transform .15s;
        }
        .grp-switch.on .grp-switch-knob { transform: translateX(23px); }

        /* Nút thao tác trên bảng */
        .grp-rowacts { display: flex; align-items: center; gap: 6px; }
        .grp-rowbtn {
            width: 30px; height: 30px; border: 0; background: none; border-radius: 4px; padding: 0;
            cursor: pointer; color: #595959; display: inline-flex; align-items: center; justify-content: center;
            transition: background .15s, color .15s;
        }
        .grp-rowbtn.grp-edit { color: #1890ff; }
        .grp-rowbtn.grp-edit:hover { background: #e6f7ff; }
        .grp-rowbtn.grp-del { color: #ff4d4f; }
        .grp-rowbtn.grp-del:hover { background: #fff1f0; }

        /* Phân trang: dùng component chung .pg-* (khai báo ở layout) */

        /* ---- Modal ---- */
        .grp-overlay { position: fixed; inset: 0; z-index: 1080; display: flex; align-items: center; justify-content: center; padding: 16px; background: rgba(0,0,0,.4); }
        .grp-dialog { max-height: 90vh; width: 100%; max-width: 640px; overflow-y: auto; border-radius: 6px; background: #fff; box-shadow: 0 10px 40px rgba(0,0,0,.2); }
        .grp-modal-head {
            position: sticky; top: 0; display: flex; align-items: center; justify-content: space-between;
            border-bottom: 1px solid #f0f0f0; background: #fff; padding: 12px 20px;
        }
        .grp-modal-title { margin: 0; font-size: 15px; font-weight: 700; color: #262626; }
        .grp-modal-x { border: 0; background: none; padding: 0; color: #8c8c8c; cursor: pointer; display: inline-flex; transition: color .15s; }
        .grp-modal-x:hover { color: #262626; }
        .grp-modal-body { padding: 16px 20px; display: flex; flex-direction: column; gap: 16px; }
        .grp-banner { border-radius: 4px; background: #f0f5ff; padding: 8px 12px; font-size: 12px; color: #1890ff; }
        .grp-grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .grp-field-label { display: block; margin-bottom: 4px; font-size: 13px; font-weight: 500; color: #262626; }
        .grp-req { color: #ff4d4f; }
        .grp-input {
            height: 36px; width: 100%; border: 1px solid #d9d9d9; border-radius: 4px; padding: 0 12px; font-size: 13px; outline: none; transition: border-color .15s;
        }
        .grp-input::placeholder { color: #bfbfbf; }
        .grp-input:focus { border-color: #1890ff; }
        .grp-input:disabled { background: #f5f5f5; color: #8c8c8c; }
        /* Ô chọn danh mục cha — mũi tên tuỳ biến, đồng bộ chiều cao với .grp-input */
        .grp-select {
            height: 36px; width: 100%; border: 1px solid #d9d9d9; border-radius: 4px;
            padding: 0 34px 0 12px; font-size: 13px; color: #262626; background-color: #fff;
            cursor: pointer; outline: none; transition: border-color .15s;
            appearance: none; -webkit-appearance: none; -moz-appearance: none;
            text-overflow: ellipsis; white-space: nowrap; overflow: hidden;
            background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23595959' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><path d='m6 9 6 6 6-6'/></svg>");
            background-repeat: no-repeat; background-position: right 10px center;
        }
        .grp-select:hover { border-color: #40a9ff; }
        .grp-select:focus { border-color: #1890ff; box-shadow: 0 0 0 3px rgba(24,144,255,.12); }

        /* Ô ảnh danh mục trong modal */
        .grp-img-field { display: flex; align-items: center; gap: 14px; }
        .grp-img-preview {
            width: 72px; height: 72px; flex-shrink: 0; border: 1px solid #d9d9d9; border-radius: 8px;
            overflow: hidden; display: flex; align-items: center; justify-content: center;
            background: #fafafa; color: #bfbfbf; position: relative; transition: opacity .15s;
        }
        .grp-img-preview img { width: 100%; height: 100%; object-fit: cover; }
        .grp-img-preview.grp-img-loading { opacity: .5; }
        .grp-img-preview.grp-img-loading::after {
            content: ''; position: absolute; width: 20px; height: 20px; border-radius: 50%;
            border: 2px solid #d9d9d9; border-top-color: #1890ff; animation: grpspin .7s linear infinite;
        }
        @keyframes grpspin { to { transform: rotate(360deg); } }
        .grp-img-actions { display: flex; flex-direction: column; gap: 8px; align-items: flex-start; }
        .grp-img-btns { display: flex; gap: 8px; }
        .grp-img-btns .grp-btn-ghost { height: 32px; padding: 0 12px; }
        .grp-img-remove { color: #ff4d4f; }
        .grp-img-remove:hover { border-color: #ffa39e; }
        .grp-img-hint { margin: 0; font-size: 11px; color: #8c8c8c; }
        .grp-modal-foot {
            position: sticky; bottom: 0; display: flex; justify-content: center; gap: 8px;
            border-top: 1px solid #f0f0f0; background: #fff; padding: 12px 20px;
        }
        .grp-btn-ghost {
            height: 34px; border: 1px solid #d9d9d9; border-radius: 4px; padding: 0 16px; font-size: 13px; font-weight: 500;
            color: #595959; background: #fff; cursor: pointer; transition: border-color .15s;
        }
        .grp-btn-ghost:hover { border-color: #bfbfbf; }

        /* ---- Thanh bulk nổi ---- */
        /* Căn giữa theo VÙNG NỘI DUNG (bù trừ chiều rộng sidebar 230px / 48px khi thu gọn),
           không lệ thuộc viewport nên không bị lệch trái vì sidebar. */
        .grp-bulk {
            position: fixed; bottom: 24px; left: 230px; right: 0; margin-inline: auto; z-index: 1070;
            width: max-content; max-width: calc(100vw - 260px);
            display: flex; align-items: center; gap: 16px; border: 1px solid #e6e6e6; border-radius: 9999px;
            background: #fff; padding: 12px 20px; box-shadow: 0 6px 24px rgba(0,0,0,.15);
        }
        /* Sidebar thu gọn -> mép trái vùng nội dung còn 48px. */
        body:has(.jh-sidebar.collapsed) .grp-bulk { left: 48px; }
        @media (max-width: 820px) { .grp-bulk { left: 0; } }
        .grp-bulk-text { font-size: 13px; font-weight: 500; color: #262626; }
        .grp-bulk-clear { border: 0; background: none; font-size: 13px; color: #8c8c8c; cursor: pointer; transition: color .15s; }
        .grp-bulk-clear:hover { color: #262626; }
        .grp-bulk-del {
            display: inline-flex; align-items: center; gap: 6px; border: 0; border-radius: 9999px; background: #ff4d4f;
            padding: 6px 16px; font-size: 13px; font-weight: 500; color: #fff; cursor: pointer; transition: background .15s;
        }
        .grp-bulk-del:hover { background: #ff7875; }

        @media (max-width: 820px) {
            .grp { flex-direction: column; }
            .grp-side { width: 100%; }
        }
    </style>

    <script>
        (function () {
            const CATEGORIES = @json($categories);
            const CSRF = @json(csrf_token());

        // Trần phía trình duyệt, khớp với ImageStore::MAX_UPLOAD_KB bên PHP. Chặn ở
        // đây chỉ để khỏi tải một file khổng lồ lên rồi mới bị từ chối; ảnh vẫn được
        // máy chủ thu nhỏ lại sau khi nhận.
        const MAX_IMG_BYTES = 10 * 1024 * 1024;
            const URL_STORE = @json(route('admin.categories.store'));
            const URL_BULK = @json(route('admin.categories.bulkDestroy'));
            const URL_BASE = @json(url('admin/categories'));
            const URL_UPLOAD = @json(route('admin.categories.uploadImage'));

            // Số cấp danh mục tối đa (loại lớn -> nhóm -> nhóm con). Đồng bộ với CategoryController::MAX_DEPTH.
            const MAX_DEPTH = 3;

            // Sau khi tạo/sửa, controller flash id danh mục cha để tự trỏ tới (mở cây + chọn).
            const FOCUS_PARENT = @json(session('focus_parent'));

            // ---------- Icons (lucide) ----------
            const ic = {
                chevronRight: (s = 14) => `<svg viewBox="0 0 24 24" width="${s}" height="${s}" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>`,
                chevronDown: (s = 14) => `<svg viewBox="0 0 24 24" width="${s}" height="${s}" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>`,
                pencil: (s = 16) => `<svg viewBox="0 0 24 24" width="${s}" height="${s}" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>`,
                trash: (s = 16) => `<svg viewBox="0 0 24 24" width="${s}" height="${s}" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/></svg>`,
                x: (s = 16, sw = 2.5) => `<svg viewBox="0 0 24 24" width="${s}" height="${s}" fill="none" stroke="currentColor" stroke-width="${sw}" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>`,
                plus: (s = 14) => `<svg viewBox="0 0 24 24" width="${s}" height="${s}" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M12 5v14"/></svg>`,
                image: (s = 18) => `<svg viewBox="0 0 24 24" width="${s}" height="${s}" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/></svg>`,
            };

            // ---------- Dựng cây từ danh sách phẳng ----------
            function buildTree() {
                const byParent = new Map();
                for (const c of CATEGORIES) {
                    const p = c.parent_id == null ? 0 : c.parent_id;
                    if (!byParent.has(p)) byParent.set(p, []);
                    byParent.get(p).push(c);
                }
                const make = (c, level) => ({
                    id: c.id,
                    name: c.name,
                    code: c.slug || '',
                    status: !!c.is_active,
                    rule: c.protected ? 'parent' : 'children',
                    parent_id: c.parent_id == null ? null : c.parent_id,
                    sort_order: c.sort_order || 0,
                    description: c.description || '',
                    image: c.image || '',
                    level: level,
                    children: (byParent.get(c.id) || []).map((ch) => make(ch, level + 1)),
                });
                return (byParent.get(0) || []).map((c) => make(c, 1));
            }
            const TREE = buildTree();

            function findNode(key) {
                if (!key) return null;
                let found = null;
                const walk = (list) => {
                    for (const n of list) {
                        if (`${n.rule}:${n.id}` === key) found = n;
                        else if (n.children.length) walk(n.children);
                    }
                };
                walk(TREE);
                return found;
            }
            function flattenGroups(nodes) {
                const out = [];
                const walk = (list) => {
                    for (const n of list) {
                        if (n.rule === 'children') out.push(n);
                        if (n.children.length) walk(n.children);
                    }
                };
                walk(nodes);
                return out;
            }

            // Tất cả node (phẳng) theo thứ tự cây — dùng dựng danh sách chọn danh mục cha.
            function allNodesFlat() {
                const out = [];
                const walk = (list) => { for (const n of list) { out.push(n); if (n.children.length) walk(n.children); } };
                walk(TREE);
                return out;
            }
            // Chiều cao cây con (1 nếu không có con) — để kiểm tra giới hạn cấp khi chuyển cha.
            function subtreeHeight(node) {
                return node.children.length ? 1 + Math.max(...node.children.map(subtreeHeight)) : 1;
            }
            // Tập id của node + toàn bộ hậu duệ (loại khỏi danh sách cha để tránh vòng lặp).
            function subtreeIds(node) {
                const ids = new Set([node.id]);
                const walk = (n) => { for (const c of n.children) { ids.add(c.id); walk(c); } };
                walk(node);
                return ids;
            }
            /**
             * Danh sách danh mục cha hợp lệ cho modal.
             * editing = node đang sửa (null nếu thêm mới). Tôn trọng MAX_DEPTH và tránh chọn chính nó/hậu duệ.
             */
            function parentOptions(editing) {
                const needed = editing ? subtreeHeight(editing) : 1; // số cấp mà cây con sẽ chiếm dưới cha
                const banned = editing ? subtreeIds(editing) : new Set();
                return allNodesFlat().filter((n) => n.level + needed <= MAX_DEPTH && !banned.has(n.id));
            }
            // Đường đi từ gốc tới node có id cho trước (gồm cả node đó); [] nếu không thấy.
            function pathToNode(id) {
                let result = [];
                const walk = (list, trail) => {
                    for (const n of list) {
                        const t = trail.concat(n);
                        if (n.id === id) { result = t; return true; }
                        if (n.children.length && walk(n.children, t)) return true;
                    }
                    return false;
                };
                walk(TREE, []);
                return result;
            }

            // ---------- State ----------
            const state = {
                selectedKey: null,
                expanded: new Set(),
                search: '',
                status: 'all',
                page: 1,
                perPage: 10,
                selectedRows: new Set(),
            };
            let searchTimer = null;

            function computeRows() {
                let rows;
                if (state.search) {
                    rows = flattenGroups(TREE).filter(
                        (g) => g.name.toLowerCase().includes(state.search)
                            || g.code.toLowerCase().includes(state.search)
                            || fmtCode(g.id).toLowerCase().includes(state.search)
                    );
                } else {
                    const node = findNode(state.selectedKey);
                    rows = node ? node.children : [];
                }
                if (state.status === 'active') rows = rows.filter((g) => g.status);
                else if (state.status === 'inactive') rows = rows.filter((g) => !g.status);
                return rows;
            }

            // ---------- DOM refs ----------
            const $tree = document.getElementById('grpTree');
            const $sideTools = document.getElementById('grpSideTools');
            const $title = document.getElementById('grpTitle');
            const $tableWrap = document.getElementById('grpTableWrap');
            const $footer = document.getElementById('grpFooter');
            const $bulkMount = document.getElementById('grpBulkMount');
            const $modalMount = document.getElementById('grpModalMount');
            const $search = document.getElementById('grpSearch');

            const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (m) => (
                { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]
            ));

            // Mã danh mục hiển thị tự sinh từ id: DM000001, DM000002, ...
            const fmtCode = (id) => 'DM' + String(id).padStart(6, '0');

            // ---------- Render: cây ----------
            function renderTree() {
                if (TREE.length === 0) {
                    $tree.innerHTML = '<p class="grp-tree-empty">Chưa có dữ liệu.</p>';
                    return;
                }
                const walk = (nodes, depth) => nodes.map((node) => {
                    const key = `${node.rule}:${node.id}`;
                    const hasChild = node.children.length > 0;
                    const isOpen = state.expanded.has(key);
                    const isSel = state.selectedKey === key;
                    const cls = ['grp-node'];
                    if (isSel) cls.push('is-selected');
                    else if (node.rule === 'parent') cls.push('is-parent');
                    const caret = hasChild
                        ? `<span class="grp-caret" data-caret="${key}">${isOpen ? ic.chevronDown() : ic.chevronRight()}</span>`
                        : '<span class="grp-caret-spacer"></span>';
                    let html = `<div><button type="button" class="${cls.join(' ')}" data-key="${key}" style="padding-left:${8 + depth * 14}px">`
                        + `${caret}<span class="grp-node-name">${esc(node.name)}</span></button>`;
                    if (hasChild && isOpen) html += walk(node.children, depth + 1);
                    html += '</div>';
                    return html;
                }).join('');
                $tree.innerHTML = walk(TREE, 0);
            }

            // ---------- Render: công cụ sửa/xóa node trên cây ----------
            function renderSideTools() {
                const node = findNode(state.selectedKey);
                if (node && node.rule === 'children') {
                    $sideTools.innerHTML =
                        `<button type="button" class="grp-iconbtn grp-edit" data-edit-node title="Cập nhật danh mục đang chọn">${ic.pencil(16)}</button>`
                        + `<button type="button" class="grp-iconbtn grp-del" data-del-node title="Xoá danh mục đang chọn">${ic.trash(16)}</button>`;
                } else {
                    $sideTools.innerHTML = '';
                }
            }

            // ---------- Render: bảng ----------
            function renderTable() {
                const node = findNode(state.selectedKey);
                $title.textContent = state.search ? 'Kết quả tìm kiếm' : (node ? `Danh mục con của: ${node.name}` : 'Danh mục sản phẩm');

                const rows = computeRows();
                const totalPages = Math.max(1, Math.ceil(rows.length / state.perPage));
                if (state.page > totalPages) state.page = totalPages;
                const firstRank = (state.page - 1) * state.perPage;
                const paged = rows.slice(firstRank, firstRank + state.perPage);

                const allChecked = paged.length > 0 && paged.every((g) => state.selectedRows.has(g.id));
                const someChecked = paged.some((g) => state.selectedRows.has(g.id));

                let body;
                if (rows.length === 0) {
                    let msg;
                    if (state.search) msg = `Không tìm thấy danh mục nào khớp “${esc(state.search)}”.`;
                    else if (!node) msg = 'Chọn một danh mục ở cây bên trái để xem danh mục con.';
                    else if (state.status !== 'all') msg = `Không có danh mục nào ở trạng thái “${state.status === 'active' ? 'Đang hoạt động' : 'Ngừng hoạt động'}”.`;
                    else msg = 'Chưa có danh mục con. Bấm “Thêm danh mục” để thêm mới.';
                    body = `<tr><td colspan="7" class="grp-empty">${msg}</td></tr>`;
                } else {
                    body = paged.map((g, i) => `
                        <tr class="${state.selectedRows.has(g.id) ? 'is-selected' : ''}">
                            <td class="grp-c-check"><input type="checkbox" class="grp-check" data-row="${g.id}" ${state.selectedRows.has(g.id) ? 'checked' : ''}></td>
                            <td class="grp-c-stt">${firstRank + i + 1}</td>
                            <td class="grp-c-code"><span class="grp-code-badge">${fmtCode(g.id)}</span></td>
                            <td class="grp-c-img">
                                ${g.image
                                    ? `<img class="grp-thumb" src="${esc(g.image)}" alt="" loading="lazy" onerror="this.replaceWith(Object.assign(document.createElement('span'),{className:'grp-thumb grp-thumb-empty',innerHTML:this.dataset.ph}))" data-ph="${ic.image(20).replace(/"/g, '&quot;')}">`
                                    : `<span class="grp-thumb grp-thumb-empty">${ic.image(20)}</span>`}
                            </td>
                            <td class="grp-c-name">${esc(g.name)}</td>
                            <td class="grp-c-status">
                                <button type="button" class="grp-switch ${g.status ? 'on' : ''}" data-toggle="${g.id}" title="${g.status ? 'Đang bật — bấm để tắt' : 'Đang tắt — bấm để bật'}"><span class="grp-switch-knob"></span></button>
                            </td>
                            <td class="grp-c-act">
                                <div class="grp-rowacts">
                                    <button type="button" class="grp-rowbtn grp-edit" data-edit="${g.id}" title="Sửa danh mục">${ic.pencil(16)}</button>
                                    <button type="button" class="grp-rowbtn grp-del" data-remove="${g.id}" title="Xoá danh mục">${ic.trash(16)}</button>
                                </div>
                            </td>
                        </tr>`).join('');
                }

                $tableWrap.innerHTML = `
                    <table class="grp-table">
                        <thead>
                            <tr>
                                <th class="grp-c-check"><input type="checkbox" id="grpCheckAll" class="grp-check" ${allChecked ? 'checked' : ''} title="Chọn tất cả"></th>
                                <th class="grp-c-stt">STT</th>
                                <th class="grp-c-code">Mã danh mục</th>
                                <th class="grp-c-img">Ảnh</th>
                                <th class="grp-c-name">Tên danh mục</th>
                                <th class="grp-c-status">Trạng thái</th>
                                <th class="grp-c-act">Thao tác</th>
                            </tr>
                        </thead>
                        <tbody>${body}</tbody>
                    </table>`;

                const chkAll = document.getElementById('grpCheckAll');
                if (chkAll) chkAll.indeterminate = someChecked && !allChecked;

                renderFooter(rows.length, totalPages);
            }

            // ---------- Render: chân trang (số dòng + phân trang) ----------
            function renderFooter(total, totalPages) {
                if (total === 0) { $footer.innerHTML = ''; return; }
                const size = state.perPage;
                const first = (state.page - 1) * size;
                const from = (first + 1).toLocaleString('vi-VN');
                const to = Math.min(first + size, total).toLocaleString('vi-VN');
                const opts = [10, 20, 30, 40, 50].map((n) => `<option value="${n}" ${n === state.perPage ? 'selected' : ''}>Hiển thị ${n}</option>`).join('');
                let nav = '';
                if (totalPages > 1) {
                    const list = pageList(state.page, totalPages);
                    const btns = list.map((p) => p === '...'
                        ? '<span class="pg-gap">…</span>'
                        : `<button type="button" class="pg-btn ${p === state.page ? 'is-active' : ''}" data-page="${p}">${p}</button>`
                    ).join('');
                    nav = `<nav class="pg-nav" aria-label="Điều hướng trang">
                        <button type="button" class="pg-btn" data-page="${state.page - 1}" ${state.page <= 1 ? 'disabled' : ''} aria-label="Trang trước">‹</button>
                        ${btns}
                        <button type="button" class="pg-btn" data-page="${state.page + 1}" ${state.page >= totalPages ? 'disabled' : ''} aria-label="Trang sau">›</button>
                    </nav>`;
                }
                $footer.innerHTML = `<div class="pg">
                    <select class="pg-size" id="grpPerPage" aria-label="Số dòng mỗi trang">${opts}</select>
                    <div class="pg-right">
                        <span class="pg-info"><b>${from}–${to}</b> / ${total.toLocaleString('vi-VN')} danh mục</span>
                        ${nav}
                    </div>
                </div>`;
            }

            function pageList(page, total) {
                const out = [];
                const push = (p) => out.push(p);
                if (total <= 7) { for (let i = 1; i <= total; i++) push(i); return out; }
                push(1);
                if (page > 3) push('...');
                for (let i = Math.max(2, page - 1); i <= Math.min(total - 1, page + 1); i++) push(i);
                if (page < total - 2) push('...');
                push(total);
                return out;
            }

            // ---------- Render: thanh bulk ----------
            function renderBulk() {
                const n = state.selectedRows.size;
                if (n === 0) { $bulkMount.innerHTML = ''; return; }
                $bulkMount.innerHTML = `
                    <div class="grp-bulk">
                        <span class="grp-bulk-text">Đã chọn <b>${n}</b> danh mục</span>
                        <button type="button" class="grp-bulk-clear" id="grpBulkClear">Bỏ chọn</button>
                        <button type="button" class="grp-bulk-del" id="grpBulkDel">${ic.trash(16)} Xoá (${n})</button>
                    </div>`;
            }

            function renderAll() {
                renderTree();
                renderSideTools();
                renderTable();
                renderBulk();
            }

            // ---------- Toast client (validate) ----------
            function toastErr(msg) {
                const cont = document.querySelector('.toast-container');
                if (!cont || typeof bootstrap === 'undefined') { alert(msg); return; }
                const el = document.createElement('div');
                el.className = 'toast align-items-center text-bg-danger border-0';
                el.setAttribute('role', 'alert');
                el.innerHTML = `<div class="d-flex"><div class="toast-body"><i class="bi bi-exclamation-circle-fill me-2"></i>${esc(msg)}</div>`
                    + '<button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>';
                cont.appendChild(el);
                const t = new bootstrap.Toast(el, { delay: 4000 });
                el.addEventListener('hidden.bs.toast', () => el.remove());
                t.show();
            }

            // ---------- Gửi form (mutation) ----------
            function postForm(action, method, main, children) {
                const f = document.createElement('form');
                f.method = 'POST';
                f.action = action;
                f.style.display = 'none';
                const add = (name, val) => {
                    const i = document.createElement('input');
                    i.type = 'hidden';
                    i.name = name;
                    i.value = typeof val === 'boolean' ? (val ? 1 : 0) : (val == null ? '' : val);
                    f.appendChild(i);
                };
                add('_token', CSRF);
                if (method && method !== 'POST') add('_method', method);
                for (const [k, v] of Object.entries(main)) add(k, v);
                if (children) children.forEach((ch, idx) => {
                    for (const [k, v] of Object.entries(ch)) add(`children[${idx}][${k}]`, v);
                });
                document.body.appendChild(f);
                f.submit();
            }

            // ---------- Mutations ----------
            function toggleStatus(node) {
                postForm(`${URL_BASE}/${node.id}`, 'PUT', {
                    name: node.name, slug: node.code, parent_id: node.parent_id,
                    sort_order: node.sort_order, description: node.description, image: node.image, is_active: !node.status,
                });
            }
            function removeNode(node) {
                sysDelete({
                    title: 'Xác nhận xoá danh mục',
                    message: `Bạn có chắc chắn muốn xoá danh mục "${node.name}"? Hành động này không thể hoàn tác.`,
                    highlightText: node.name
                }).then((confirmed) => {
                    if (confirmed) {
                        postForm(`${URL_BASE}/${node.id}`, 'DELETE', {});
                    }
                });
            }
            function bulkRemove() {
                const ids = [...state.selectedRows];
                if (!ids.length) return;
                sysDelete({
                    title: 'Xác nhận xoá hàng loạt',
                    message: `Bạn có chắc chắn muốn xoá ${ids.length} danh mục đã chọn? Hành động này không thể hoàn tác.`,
                    highlightText: `Số lượng: ${ids.length} danh mục`
                }).then((confirmed) => {
                    if (confirmed) {
                        const f = document.createElement('form');
                        f.method = 'POST'; f.action = URL_BULK; f.style.display = 'none';
                        const add = (n, v) => { const i = document.createElement('input'); i.type = 'hidden'; i.name = n; i.value = v; f.appendChild(i); };
                        add('_token', CSRF);
                        ids.forEach((id) => add('ids[]', id));
                        document.body.appendChild(f); f.submit();
                    }
                });
            }

            // ---------- Modal thêm/sửa ----------
            function openModal(mode, node) {
                const selected = findNode(state.selectedKey);
                const isEdit = mode === 'edit';

                // Danh sách danh mục cha hợp lệ (tôn trọng giới hạn MAX_DEPTH).
                const opts = parentOptions(isEdit ? node : null);
                if (!opts.length) {
                    toastErr(`Không còn danh mục cha hợp lệ (đã đạt ${MAX_DEPTH} cấp).`);
                    return;
                }

                const draftId = isEdit ? node.id : null;
                const code = isEdit ? node.code : '';
                const name = isEdit ? node.name : '';
                const status = isEdit ? node.status : true;
                const sortOrder = isEdit ? node.sort_order : 0;
                const description = isEdit ? node.description : '';

                // Danh mục cha mặc định:
                //  - Khi sửa: cha hiện tại của node.
                //  - Khi thêm: node đang chọn trên cây; nếu node đó không thể làm cha
                //    (đã ở cấp cuối) thì lùi lên tổ tiên gần nhất còn hợp lệ để giữ đúng ngữ cảnh.
                let parentId = isEdit ? node.parent_id : null;
                if (!isEdit && selected) {
                    const path = pathToNode(selected.id); // gốc -> node đang chọn
                    for (let i = path.length - 1; i >= 0; i--) {
                        if (opts.some((o) => o.id === path[i].id)) { parentId = path[i].id; break; }
                    }
                }
                // Chốt chặn: nếu vẫn chưa hợp lệ thì lấy option đầu tiên.
                if (!opts.some((o) => o.id === parentId)) parentId = opts[0].id;

                const parentField = `
                    <div>
                        <label class="grp-field-label">Danh mục cha <span class="grp-req">*</span></label>
                        <select id="grpDraftParent" class="grp-select">
                            ${opts.map((o) => `<option value="${o.id}" ${o.id === parentId ? 'selected' : ''}>${'  '.repeat(Math.max(0, o.level - 1))}${o.level > 1 ? '└ ' : ''}${esc(o.name)}</option>`).join('')}
                        </select>
                    </div>`;

                const image = isEdit ? (node.image || '') : '';
                const imgField = `
                    <div>
                        <label class="grp-field-label">Ảnh danh mục</label>
                        <div class="grp-img-field">
                            <div class="grp-img-preview" id="grpImgPreview"></div>
                            <div class="grp-img-actions">
                                <div class="grp-img-btns">
                                    <button type="button" class="grp-btn-ghost" id="grpImgPick">Chọn ảnh</button>
                                    <button type="button" class="grp-btn-ghost grp-img-remove" id="grpImgRemove">Xoá ảnh</button>
                                </div>
                                <p class="grp-img-hint">JPG, PNG, WEBP, AVIF — tối đa 10MB, ảnh lớn sẽ được tự thu nhỏ</p>
                            </div>
                            <input type="file" id="grpImgInput" accept="image/*" hidden>
                        </div>
                    </div>`;

                $modalMount.innerHTML = `
                    <div class="grp-overlay" id="grpOverlay">
                        <div class="grp-dialog" id="grpDialog">
                            <div class="grp-modal-head">
                                <h4 class="grp-modal-title">${isEdit ? 'Sửa danh mục' : 'Thêm danh mục'}</h4>
                                <button type="button" class="grp-modal-x" id="grpModalX">${ic.x(20, 2)}</button>
                            </div>
                            <div class="grp-modal-body">
                                <div class="grp-grid2">
                                    <div>
                                        <label class="grp-field-label">Mã danh mục</label>
                                        <input type="text" class="grp-input" disabled value="${isEdit ? fmtCode(draftId) : 'Tự động tạo khi lưu'}">
                                    </div>
                                    <div>
                                        <label class="grp-field-label">Tên danh mục <span class="grp-req">*</span></label>
                                        <input type="text" id="grpDraftName" class="grp-input" placeholder="VD: Ngoại hạng Anh, La Liga" value="${esc(name)}">
                                    </div>
                                </div>
                                ${parentField}
                                ${imgField}
                                <div>
                                    <label class="grp-field-label">Trạng thái</label>
                                    <div>
                                        <button type="button" class="grp-switch ${status ? 'on' : ''}" id="grpDraftStatus" data-active="${status ? 1 : 0}"><span class="grp-switch-knob"></span></button>
                                    </div>
                                </div>
                            </div>
                            <div class="grp-modal-foot">
                                <button type="button" class="grp-btn-ghost" id="grpModalClose">Đóng</button>
                                <button type="button" class="grp-btn-primary" id="grpModalSave">Lưu</button>
                            </div>
                        </div>
                    </div>`;

                // Dữ liệu ẩn của draft chính (để lúc lưu dựng payload)
                const dialog = document.getElementById('grpDialog');
                dialog.dataset.mode = mode;
                dialog.dataset.id = draftId == null ? '' : draftId;
                dialog.dataset.code = code;
                dialog.dataset.parentId = parentId == null ? '' : parentId;
                dialog.dataset.sortOrder = sortOrder;
                dialog.dataset.description = description;

                // Wiring modal
                document.getElementById('grpModalX').addEventListener('click', closeModal);
                document.getElementById('grpModalClose').addEventListener('click', closeModal);
                const statusBtn = document.getElementById('grpDraftStatus');
                statusBtn.addEventListener('click', () => {
                    const on = statusBtn.dataset.active === '1';
                    statusBtn.dataset.active = on ? '0' : '1';
                    statusBtn.classList.toggle('on', !on);
                });

                // Wiring ảnh danh mục: chọn -> upload -> preview; lưu URL vào dataset.image.
                const imgInput = document.getElementById('grpImgInput');
                const imgPreview = document.getElementById('grpImgPreview');
                const imgRemove = document.getElementById('grpImgRemove');
                const renderImg = (url) => {
                    dialog.dataset.image = url || '';
                    imgPreview.innerHTML = url ? `<img src="${esc(url)}" alt="">` : `<span class="grp-img-ph">${ic.image(22)}</span>`;
                    imgRemove.style.display = url ? '' : 'none';
                };
                renderImg(image);
                document.getElementById('grpImgPick').addEventListener('click', () => imgInput.click());
                imgRemove.addEventListener('click', () => { renderImg(''); imgInput.value = ''; });
                imgInput.addEventListener('change', async () => {
                    const file = imgInput.files && imgInput.files[0];
                    if (!file) return;
                    if (!file.type.startsWith('image/')) { toastErr('File tải lên không phải ảnh.'); imgInput.value = ''; return; }
                    if (file.size > MAX_IMG_BYTES) { toastErr('Ảnh vượt quá 10MB.'); imgInput.value = ''; return; }
                    imgPreview.classList.add('grp-img-loading');
                    try {
                        const fd = new FormData();
                        fd.append('image', file);
                        const res = await fetch(URL_UPLOAD, {
                            method: 'POST', body: fd,
                            headers: { 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
                        });
                        const data = await res.json().catch(() => ({}));
                        if (!res.ok) { toastErr(data.message || 'Tải ảnh thất bại, vui lòng thử lại.'); return; }
                        renderImg(data.url);
                    } catch (err) {
                        toastErr('Không kết nối được máy chủ để tải ảnh.');
                    } finally {
                        imgPreview.classList.remove('grp-img-loading');
                        imgInput.value = '';
                    }
                });

                document.getElementById('grpModalSave').addEventListener('click', saveModal);
                setTimeout(() => document.getElementById('grpDraftName').focus(), 0);
            }

            function closeModal() { $modalMount.innerHTML = ''; }

            function saveModal() {
                const dialog = document.getElementById('grpDialog');
                const name = document.getElementById('grpDraftName').value.trim();
                if (!name) { toastErr('Vui lòng nhập tên danh mục'); return; }
                const parentSel = document.getElementById('grpDraftParent');
                const parentId = parentSel ? parentSel.value : dialog.dataset.parentId;
                if (!parentId) { toastErr('Vui lòng chọn danh mục cha'); return; }
                const statusOn = document.getElementById('grpDraftStatus').dataset.active === '1';
                const image = dialog.dataset.image || '';

                if (dialog.dataset.mode === 'add') {
                    postForm(URL_STORE, 'POST', {
                        name, slug: '', parent_id: parentId,
                        sort_order: 0, description: '', image, is_active: statusOn,
                    });
                } else {
                    postForm(`${URL_BASE}/${dialog.dataset.id}`, 'PUT', {
                        name, slug: dialog.dataset.code, parent_id: parentId,
                        sort_order: dialog.dataset.sortOrder, description: dialog.dataset.description, image, is_active: statusOn,
                    });
                }
            }

            // ---------- Sự kiện ----------
            // Cây: chọn node / xổ nhánh
            $tree.addEventListener('click', (e) => {
                const caret = e.target.closest('[data-caret]');
                if (caret) {
                    e.stopPropagation();
                    const key = caret.getAttribute('data-caret');
                    if (state.expanded.has(key)) state.expanded.delete(key);
                    else state.expanded.add(key);
                    renderTree();
                    return;
                }
                const btn = e.target.closest('[data-key]');
                if (!btn) return;
                state.selectedKey = btn.getAttribute('data-key');
                state.selectedRows.clear();
                state.page = 1;
                renderAll();
            });

            // Công cụ sửa/xóa node đang chọn (cây)
            $sideTools.addEventListener('click', (e) => {
                const node = findNode(state.selectedKey);
                if (!node) return;
                if (e.target.closest('[data-edit-node]')) openModal('edit', node);
                else if (e.target.closest('[data-del-node]')) removeNode(node);
            });

            // Bảng: checkbox, switch, sửa, xóa
            $tableWrap.addEventListener('click', (e) => {
                const all = e.target.closest('#grpCheckAll');
                if (all) {
                    const paged = currentPaged();
                    const everyOn = paged.length > 0 && paged.every((g) => state.selectedRows.has(g.id));
                    paged.forEach((g) => everyOn ? state.selectedRows.delete(g.id) : state.selectedRows.add(g.id));
                    renderTable(); renderBulk();
                    return;
                }
                const row = e.target.closest('[data-row]');
                if (row) {
                    const id = Number(row.getAttribute('data-row'));
                    if (state.selectedRows.has(id)) state.selectedRows.delete(id);
                    else state.selectedRows.add(id);
                    renderTable(); renderBulk();
                    return;
                }
                const tg = e.target.closest('[data-toggle]');
                if (tg) { const n = findNode2(Number(tg.getAttribute('data-toggle'))); if (n) toggleStatus(n); return; }
                const ed = e.target.closest('[data-edit]');
                if (ed) { const n = findNode2(Number(ed.getAttribute('data-edit'))); if (n) openModal('edit', n); return; }
                const rm = e.target.closest('[data-remove]');
                if (rm) { const n = findNode2(Number(rm.getAttribute('data-remove'))); if (n) removeNode(n); return; }
            });

            // Chân trang: đổi số dòng / trang
            $footer.addEventListener('change', (e) => {
                if (e.target.id === 'grpPerPage') { state.perPage = Number(e.target.value); state.page = 1; renderTable(); }
            });
            $footer.addEventListener('click', (e) => {
                const p = e.target.closest('[data-page]');
                if (p && !p.disabled) { state.page = Number(p.getAttribute('data-page')); renderTable(); }
            });

            // Thanh bulk
            $bulkMount.addEventListener('click', (e) => {
                if (e.target.closest('#grpBulkClear')) { state.selectedRows.clear(); renderTable(); renderBulk(); }
                else if (e.target.closest('#grpBulkDel')) bulkRemove();
            });

            // Tìm kiếm (debounce 300ms)
            function applySearch() {
                state.search = $search.value.trim().toLowerCase();
                state.page = 1;
                state.selectedRows.clear();
                renderTable(); renderBulk();
            }
            $search.addEventListener('input', () => { clearTimeout(searchTimer); searchTimer = setTimeout(applySearch, 300); });
            $search.addEventListener('keydown', (e) => { if (e.key === 'Enter') { clearTimeout(searchTimer); applySearch(); } });
            document.getElementById('grpSearchBtn').addEventListener('click', () => { clearTimeout(searchTimer); applySearch(); });

            // Lọc theo trạng thái
            document.getElementById('grpStatusFilter').addEventListener('change', (e) => {
                state.status = e.target.value;
                state.page = 1;
                state.selectedRows.clear();
                renderTable(); renderBulk();
            });

            // Tạo nhóm
            document.getElementById('grpAddBtn').addEventListener('click', () => openModal('add', null));

            // Helpers dùng chung cho bảng
            function currentPaged() {
                const rows = computeRows();
                const totalPages = Math.max(1, Math.ceil(rows.length / state.perPage));
                const page = Math.min(state.page, totalPages);
                const first = (page - 1) * state.perPage;
                return rows.slice(first, first + state.perPage);
            }
            function findNode2(id) {
                let found = null;
                const walk = (list) => { for (const n of list) { if (n.id === id) found = n; else if (n.children.length) walk(n.children); } };
                walk(TREE);
                return found;
            }

            // Sau khi tạo/sửa: mở cây tới danh mục cha và chọn nó để hiện bảng con (gồm mục vừa lưu).
            (function focusParent() {
                if (FOCUS_PARENT == null) return;
                const path = pathToNode(Number(FOCUS_PARENT));
                if (!path.length) return;
                // Mở toàn bộ tổ tiên + chính node cha để thấy mục con mới ngay trên cây.
                for (const n of path) state.expanded.add(`${n.rule}:${n.id}`);
                const target = path[path.length - 1];
                state.selectedKey = `${target.rule}:${target.id}`;
            })();

            renderAll();
        })();
    </script>
@endsection
