@extends('layouts.app')

@section('title', 'Nhóm hàng hóa')

@section('content')
    {{--
        Màn "Nhóm hàng hóa" — bố cục theo bản cũ v2:
        [ cây nhóm bên trái ] + [ tiêu đề + tìm kiếm + Thêm nhóm + bảng nhóm con + phân trang ]
        Modal thêm/sửa kèm bảng "Nhóm con"; thanh thao tác nổi khi chọn nhiều dòng.
        Dữ liệu nạp phẳng, JS tự dựng cây theo parent_id.
    --}}
    <div class="grp">
        {{-- ===== Cột trái: cây loại lớn -> nhóm -> nhóm con ===== --}}
        <aside class="grp-side">
            <div class="grp-side-head">
                <span class="grp-side-title">Cây nhóm hàng hóa</span>
                <div class="grp-side-tools" id="grpSideTools"></div>
            </div>
            <div class="grp-tree" id="grpTree"></div>
        </aside>

        {{-- ===== Cột phải: header + bảng ===== --}}
        <div class="grp-main">
            <div class="grp-head">
                <h1 class="grp-title" id="grpTitle">Nhóm hàng hóa</h1>
            </div>

            {{-- Thanh công cụ: tìm kiếm + lọc trạng thái + nút thêm (cùng một hàng) --}}
            <div class="grp-toolbar">
                <div class="grp-searchbox" id="grpSearchBox">
                    <input type="text" id="grpSearch" class="grp-search-input" placeholder="Tìm theo tên hoặc mã nhóm" autocomplete="off">
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
                    <button type="button" id="grpAddBtn" class="grp-btn-primary">Thêm nhóm hàng hóa</button>
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
        .grp-side-tools .grp-iconbtn {
            width: 28px; height: 28px; padding: 0; border: 1px solid #d9d9d9; border-radius: 6px;
            background: #fff; color: #595959; cursor: pointer;
            display: inline-flex; align-items: center; justify-content: center;
            transition: border-color .15s, background .15s, color .15s;
        }
        .grp-side-tools .grp-iconbtn:hover { border-color: #bfbfbf; background: #fafafa; }
        .grp-side-tools .grp-edit:hover { border-color: #91d5ff; background: #e6f7ff; color: #1890ff; }
        .grp-side-tools .grp-del:hover { border-color: #ffa39e; background: #fff1f0; color: #ff4d4f; }

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
        .grp-table { width: 100%; min-width: 900px; border-collapse: collapse; font-size: 14px; table-layout: fixed; }
        .grp-table thead tr { background: #f0f0f0; color: #262626; }
        /* Canh giữa mọi ô + khoảng cách lấy theo bản v2; MÀU giữ của bản hiện tại */
        .grp-table th, .grp-table td { padding: 15px 14px; vertical-align: middle; white-space: nowrap; text-align: center; }
        .grp-table th { font-weight: 700; }
        /* Bề rộng theo TỈ LỆ, tổng đúng 100% -> phần dư chia đều cho mọi cột.
           Để một cột không có width là cột đó nuốt hết phần dư, các cột còn lại dồn cục. */
        .grp-table th.grp-c-check,  .grp-table td.grp-c-check  { width: 7%;  padding-left: 14px; padding-right: 14px; }
        .grp-table th.grp-c-stt,    .grp-table td.grp-c-stt    { width: 9%; }
        .grp-table th.grp-c-code,   .grp-table td.grp-c-code   { width: 26%; }
        .grp-table th.grp-c-name,   .grp-table td.grp-c-name   { width: 33%; overflow: hidden; text-overflow: ellipsis; }
        .grp-table th.grp-c-status, .grp-table td.grp-c-status { width: 13%; }
        .grp-table th.grp-c-act,    .grp-table td.grp-c-act    { width: 12%; }
        .grp-table tbody tr { border-bottom: 1px solid #f0f0f0; }
        .grp-table tbody tr:hover { background: #fafafa; }
        .grp-table tbody tr.is-selected, .grp-table tbody tr.is-selected:hover { background: #e6f7ff; }
        .grp-code-badge { font-variant-numeric: tabular-nums; letter-spacing: .3px; color: #595959; }
        /* display:block để ô tick khỏi bám đường chân chữ (baseline) — có vậy mới đúng tâm ô */
        .grp-check { display: block; margin: 0 auto; width: 16px; height: 16px; cursor: pointer; accent-color: #1890ff; }
        .grp-empty { padding: 48px 12px; text-align: center; color: #8c8c8c; }

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

        /* Nút thao tác: mỗi nút một ô vuông bo góc có viền, lúc thường xám trung tính,
           rê chuột mới ăn màu theo việc. Trang này chỉ có sửa và xoá. */
        .grp-rowacts { display: flex; align-items: center; justify-content: center; gap: 6px; }
        .grp-rowbtn {
            width: 32px; height: 32px; padding: 0; border: 1px solid #d9d9d9; border-radius: 6px;
            background: #fff; color: #595959; cursor: pointer;
            display: inline-flex; align-items: center; justify-content: center;
            transition: border-color .15s, background .15s, color .15s;
        }
        .grp-rowbtn:hover { border-color: #bfbfbf; background: #fafafa; }
        .grp-rowbtn.grp-edit:hover { border-color: #91d5ff; background: #e6f7ff; color: #1890ff; }
        .grp-rowbtn.grp-del:hover { border-color: #ffa39e; background: #fff1f0; color: #ff4d4f; }

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

        .grp-modal-foot {
            position: sticky; bottom: 0; display: flex; justify-content: center; gap: 8px;
            border-top: 1px solid #f0f0f0; background: #fff; padding: 12px 20px;
        }
        .grp-btn-ghost {
            height: 34px; border: 1px solid #d9d9d9; border-radius: 4px; padding: 0 16px; font-size: 13px; font-weight: 500;
            color: #595959; background: #fff; cursor: pointer; transition: border-color .15s;
        }
        .grp-btn-ghost:hover { border-color: #bfbfbf; }

        /* ---- Bảng "Nhóm con" trong modal ---- */
        .grp-sub-head { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 8px; }
        .grp-sub-actions { display: flex; gap: 8px; }
        .grp-sub-actions .grp-btn-ghost { display: inline-flex; align-items: center; gap: 5px; height: 30px; padding: 0 10px; font-size: 12px; }
        .grp-sub-add { color: #389e0d; }
        .grp-sub-add:hover { border-color: #95de64; }
        .grp-sub-clear { color: #ff4d4f; }
        .grp-sub-clear:hover { border-color: #ffa39e; }
        .grp-sub-wrap { border: 1px solid #f0f0f0; border-radius: 4px; overflow: hidden; }
        .grp-sub-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .grp-sub-table th {
            background: #fafafa; padding: 8px 10px; text-align: left; white-space: nowrap;
            font-size: 12px; font-weight: 600; color: #595959; border-bottom: 1px solid #f0f0f0;
        }
        .grp-sub-table td { padding: 6px 10px; border-bottom: 1px solid #f5f5f5; vertical-align: middle; }
        .grp-sub-table tr:last-child td { border-bottom: 0; }
        .grp-sub-table .grp-input { height: 32px; }
        .grp-sub-c-code { width: 190px; }
        .grp-sub-c-act { width: 56px; text-align: center; }
        .grp-sub-empty { padding: 14px 10px; text-align: center; font-size: 12px; color: #bfbfbf; }

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

            const URL_STORE = @json(route('admin.categories.store'));
            const URL_BULK = @json(route('admin.categories.bulkDestroy'));
            const URL_BASE = @json(url('admin/categories'));


            // Sau khi tạo/sửa, controller flash id nhóm cha để tự trỏ tới (mở cây + chọn).
            const FOCUS_PARENT = @json(session('focus_parent'));

            // ---------- Icons (lucide) ----------
            const ic = {
                chevronRight: (s = 14) => `<svg viewBox="0 0 24 24" width="${s}" height="${s}" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>`,
                chevronDown: (s = 14) => `<svg viewBox="0 0 24 24" width="${s}" height="${s}" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>`,
                pencil: (s = 16) => `<svg viewBox="0 0 24 24" width="${s}" height="${s}" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>`,
                trash: (s = 16) => `<svg viewBox="0 0 24 24" width="${s}" height="${s}" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/></svg>`,
                x: (s = 16, sw = 2.5) => `<svg viewBox="0 0 24 24" width="${s}" height="${s}" fill="none" stroke="currentColor" stroke-width="${sw}" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>`,
                plus: (s = 14) => `<svg viewBox="0 0 24 24" width="${s}" height="${s}" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M12 5v14"/></svg>`,
            };

            // ---------- Dựng cây từ danh sách phẳng ----------
            function buildTree() {
                const byParent = new Map();
                for (const c of CATEGORIES) {
                    const p = c.parent_id == null ? 0 : c.parent_id;
                    if (!byParent.has(p)) byParent.set(p, []);
                    byParent.get(p).push(c);
                }
                // seen = id các tổ tiên trên nhánh hiện tại. Cây không giới hạn cấp nên
                // dữ liệu lỗi kiểu A->B->A sẽ đệ quy vô tận nếu không chặn.
                const make = (c, seen) => {
                    const next = new Set(seen).add(c.id);

                    return {
                        id: c.id,
                        name: c.name,
                        code: c.slug || '',
                        status: !!c.is_active,
                        rule: c.protected ? 'parent' : 'children',
                        parent_id: c.parent_id == null ? null : c.parent_id,
                        sort_order: c.sort_order || 0,
                        description: c.description || '',
                        image: c.image || '',
                        children: (byParent.get(c.id) || [])
                            .filter((ch) => !next.has(ch.id))
                            .map((ch) => make(ch, next)),
                    };
                };

                return (byParent.get(0) || []).map((c) => make(c, new Set()));
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
                    let html = `<div><button type="button" class="${cls.join(' ')}" data-key="${key}" style="padding-left:${8 + Math.min(depth, 8) * 14}px">`
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
                        `<button type="button" class="grp-iconbtn grp-edit" data-edit-node title="Sửa nhóm đang chọn">${ic.pencil(16)}</button>`
                        + `<button type="button" class="grp-iconbtn grp-del" data-del-node title="Xoá nhóm đang chọn">${ic.trash(16)}</button>`;
                } else {
                    $sideTools.innerHTML = '';
                }
            }

            // ---------- Render: bảng ----------
            function renderTable() {
                const node = findNode(state.selectedKey);
                $title.textContent = state.search ? 'Kết quả tìm kiếm' : (node ? `Nhóm con của: ${node.name}` : 'Nhóm hàng hóa');

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
                    if (state.search) msg = `Không tìm thấy nhóm nào khớp “${esc(state.search)}”.`;
                    else if (!node) msg = 'Chọn một nhóm ở cây bên trái để xem nhóm con.';
                    else if (state.status !== 'all') msg = `Không có nhóm nào ở trạng thái “${state.status === 'active' ? 'Đang hoạt động' : 'Ngừng hoạt động'}”.`;
                    else msg = 'Chưa có nhóm con. Bấm “Thêm nhóm hàng hóa” để thêm mới.';
                    body = `<tr><td colspan="6" class="grp-empty">${msg}</td></tr>`;
                } else {
                    body = paged.map((g, i) => `
                        <tr class="${state.selectedRows.has(g.id) ? 'is-selected' : ''}">
                            <td class="grp-c-check"><input type="checkbox" class="grp-check" data-row="${g.id}" ${state.selectedRows.has(g.id) ? 'checked' : ''}></td>
                            <td class="grp-c-stt">${firstRank + i + 1}</td>
                            <td class="grp-c-code"><span class="grp-code-badge">${esc(g.code)}</span></td>
                            <td class="grp-c-name">${esc(g.name)}</td>
                            <td class="grp-c-status">
                                <button type="button" class="grp-switch ${g.status ? 'on' : ''}" data-toggle="${g.id}" title="${g.status ? 'Đang bật — bấm để tắt' : 'Đang tắt — bấm để bật'}"><span class="grp-switch-knob"></span></button>
                            </td>
                            <td class="grp-c-act">
                                <div class="grp-rowacts">
                                    <button type="button" class="grp-rowbtn grp-edit" data-edit="${g.id}" title="Sửa nhóm">${ic.pencil(16)}</button>
                                    <button type="button" class="grp-rowbtn grp-del" data-remove="${g.id}" title="Xoá nhóm">${ic.trash(16)}</button>
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
                                <th class="grp-c-code">Mã nhóm hàng hóa</th>
                                <th class="grp-c-name">Tên nhóm hàng hóa</th>
                                <th class="grp-c-status">Trạng thái</th>
                                <th class="grp-c-act">Hành động</th>
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
                        <span class="pg-info"><b>${from}–${to}</b> / ${total.toLocaleString('vi-VN')} nhóm</span>
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
                        <span class="grp-bulk-text">Đã chọn <b>${n}</b> nhóm</span>
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
                    sort_order: node.sort_order, description: node.description, is_active: !node.status,
                });
            }
            function removeNode(node) {
                sysDelete({
                    title: 'Xác nhận xoá nhóm hàng hóa',
                    message: `Bạn có chắc chắn muốn xoá nhóm "${node.name}"? Hành động này không thể hoàn tác.`,
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
                    message: `Bạn có chắc chắn muốn xoá ${ids.length} nhóm đã chọn? Hành động này không thể hoàn tác.`,
                    highlightText: `Số lượng: ${ids.length} nhóm`
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

            /**
             * Một dòng trong bảng "Nhóm con" của modal.
             * child = nhóm con đã lưu (có id) hoặc null cho dòng nhập mới.
             * Mã do máy chủ tự sinh nên ô mã chỉ để xem. Các giá trị không hiển thị
             * (mã, ảnh, thứ tự, mô tả, trạng thái) giữ trong dataset để lưu lại nguyên vẹn.
             */
            function subRowHtml(child) {
                const d = child
                    ? ` data-sub-id="${child.id}" data-sub-code="${esc(child.code)}" data-sub-image="${esc(child.image)}"`
                        + ` data-sub-sort="${child.sort_order}" data-sub-desc="${esc(child.description)}" data-sub-active="${child.status ? 1 : 0}"`
                    : '';
                return `<tr data-sub${d}>
                    <td class="grp-sub-c-code"><input type="text" class="grp-input" disabled value="${child ? esc(child.code) : 'Tự tạo khi lưu'}"></td>
                    <td><input type="text" class="grp-input grp-sub-name" placeholder="Nhập tên nhóm con" value="${child ? esc(child.name) : ''}"></td>
                    <td class="grp-sub-c-act"><button type="button" class="grp-rowbtn grp-del" data-sub-del title="Xoá dòng">${ic.x(15)}</button></td>
                </tr>`;
            }

            /**
             * Modal thêm/sửa — theo bản v2: mã (tự sinh, chỉ để xem), tên, trạng thái
             * và bảng "Nhóm con". Không có ô chọn nhóm cha: cha luôn là nhóm đang chọn
             * trên cây, nên phải chọn nhóm trước khi bấm Thêm.
             */
            function openModal(mode, node) {
                const isEdit = mode === 'edit';
                const parent = isEdit ? findNode2(node.parent_id) : findNode(state.selectedKey);

                if (!isEdit && !parent) {
                    toastErr('Vui lòng chọn một nhóm ở cây bên trái trước khi thêm.');
                    return;
                }

                const subSection = `
                    <div>
                        <div class="grp-sub-head">
                            <label class="grp-field-label" style="margin:0">Nhóm con</label>
                            <div class="grp-sub-actions">
                                <button type="button" class="grp-btn-ghost grp-sub-add" id="grpSubAdd">${ic.plus(13)} Thêm dòng</button>
                                <button type="button" class="grp-btn-ghost grp-sub-clear" id="grpSubClear">${ic.x(13)} Xoá tất cả</button>
                            </div>
                        </div>
                        <div class="grp-sub-wrap">
                            <table class="grp-sub-table">
                                <thead>
                                    <tr>
                                        <th class="grp-sub-c-code">Mã nhóm hàng hóa</th>
                                        <th>Tên nhóm hàng hóa <span class="grp-req">*</span></th>
                                        <th class="grp-sub-c-act">Hành động</th>
                                    </tr>
                                </thead>
                                <tbody id="grpSubBody">${(isEdit ? node.children : []).map(subRowHtml).join('')}</tbody>
                            </table>
                        </div>
                    </div>`;

                $modalMount.innerHTML = `
                    <div class="grp-overlay" id="grpOverlay">
                        <div class="grp-dialog" id="grpDialog">
                            <div class="grp-modal-head">
                                <h4 class="grp-modal-title">${isEdit ? 'Sửa nhóm hàng hóa' : 'Thêm nhóm hàng hóa'}</h4>
                                <button type="button" class="grp-modal-x" id="grpModalX">${ic.x(20, 2)}</button>
                            </div>
                            <div class="grp-modal-body">
                                <div class="grp-banner">Thuộc nhóm: <b>${esc(parent ? parent.name : '—')}</b></div>
                                <div class="grp-grid2">
                                    <div>
                                        <label class="grp-field-label">Mã nhóm hàng hóa</label>
                                        <input type="text" class="grp-input" disabled value="${isEdit ? esc(node.code) : 'Tự tạo khi lưu'}">
                                    </div>
                                    <div>
                                        <label class="grp-field-label">Tên nhóm hàng hóa <span class="grp-req">*</span></label>
                                        <input type="text" id="grpDraftName" class="grp-input" placeholder="VD: Cà phê, Trà sữa" value="${isEdit ? esc(node.name) : ''}">
                                    </div>
                                </div>
                                <div>
                                    <label class="grp-field-label">Trạng thái</label>
                                    <div>
                                        <button type="button" class="grp-switch ${!isEdit || node.status ? 'on' : ''}" id="grpDraftStatus" data-active="${!isEdit || node.status ? 1 : 0}"><span class="grp-switch-knob"></span></button>
                                    </div>
                                </div>
                                ${subSection}
                            </div>
                            <div class="grp-modal-foot">
                                <button type="button" class="grp-btn-ghost" id="grpModalClose">Đóng</button>
                                <button type="button" class="grp-btn-primary" id="grpModalSave">Lưu</button>
                            </div>
                        </div>
                    </div>`;

                // Dữ liệu ẩn của bản nháp — lúc lưu dựng payload từ đây
                const dialog = document.getElementById('grpDialog');
                dialog.dataset.mode = mode;
                dialog.dataset.id = isEdit ? node.id : '';
                dialog.dataset.code = isEdit ? node.code : '';
                dialog.dataset.parentId = parent ? parent.id : '';
                dialog.dataset.sortOrder = isEdit ? node.sort_order : 0;
                dialog.dataset.description = isEdit ? node.description : '';

                document.getElementById('grpModalX').addEventListener('click', closeModal);
                document.getElementById('grpModalClose').addEventListener('click', closeModal);
                const statusBtn = document.getElementById('grpDraftStatus');
                statusBtn.addEventListener('click', () => {
                    const on = statusBtn.dataset.active === '1';
                    statusBtn.dataset.active = on ? '0' : '1';
                    statusBtn.classList.toggle('on', !on);
                });

                // Wiring bảng "Nhóm con": thêm dòng, xoá dòng, xoá tất cả.
                const subBody = document.getElementById('grpSubBody');
                const syncSubEmpty = () => {
                    const ph = subBody.querySelector('[data-sub-empty]');
                    if (subBody.querySelector('[data-sub]')) { if (ph) ph.remove(); }
                    else if (!ph) subBody.innerHTML = '<tr data-sub-empty><td colspan="3" class="grp-sub-empty">Chưa có nhóm con. Bấm “Thêm dòng” để thêm.</td></tr>';
                };
                syncSubEmpty();

                document.getElementById('grpSubAdd').addEventListener('click', () => {
                    subBody.insertAdjacentHTML('beforeend', subRowHtml(null));
                    syncSubEmpty();
                    const rows = subBody.querySelectorAll('tr[data-sub] .grp-sub-name');
                    rows[rows.length - 1].focus();
                });

                // Dòng chưa lưu chỉ cần gỡ khỏi bảng; dòng đã lưu phải xoá thật ở máy chủ.
                document.getElementById('grpSubClear').addEventListener('click', () => {
                    const saved = subBody.querySelectorAll('tr[data-sub-id]').length;
                    if (!saved) {
                        subBody.querySelectorAll('tr[data-sub]').forEach((r) => r.remove());
                        syncSubEmpty();
                        return;
                    }
                    sysDelete({
                        title: 'Xoá tất cả nhóm con',
                        message: `Bạn có chắc chắn muốn xoá toàn bộ ${saved} nhóm con của "${node.name}"? Hành động này không thể hoàn tác.`,
                        highlightText: `${saved} nhóm con`,
                    }).then((confirmed) => {
                        if (confirmed) postForm(`${URL_BASE}/${dialog.dataset.id}/children`, 'DELETE', {});
                    });
                });

                subBody.addEventListener('click', (e) => {
                    const btn = e.target.closest('[data-sub-del]');
                    if (!btn) return;
                    const tr = btn.closest('tr[data-sub]');
                    const savedId = tr.getAttribute('data-sub-id');
                    if (!savedId) { tr.remove(); syncSubEmpty(); return; }
                    const subName = tr.querySelector('.grp-sub-name').value.trim();
                    sysDelete({
                        title: 'Xoá nhóm con',
                        message: `Bạn có chắc chắn muốn xoá nhóm con "${subName}"? Hành động này không thể hoàn tác.`,
                        highlightText: subName,
                    }).then((confirmed) => {
                        if (confirmed) postForm(`${URL_BASE}/${savedId}`, 'DELETE', {});
                    });
                });

                document.getElementById('grpModalSave').addEventListener('click', saveModal);
                setTimeout(() => document.getElementById('grpDraftName').focus(), 0);
            }

            function closeModal() { $modalMount.innerHTML = ''; }

            function saveModal() {
                const dialog = document.getElementById('grpDialog');
                const isAdd = dialog.dataset.mode === 'add';

                const name = document.getElementById('grpDraftName').value.trim();
                if (!name) { toastErr('Vui lòng nhập tên nhóm hàng hóa'); return; }

                const statusOn = document.getElementById('grpDraftStatus').dataset.active === '1';

                // Thu các dòng "Nhóm con". Dòng đã lưu gửi kèm dữ liệu ẩn để khỏi bị ghi rỗng.
                const children = [];
                for (const tr of document.querySelectorAll('#grpSubBody tr[data-sub]')) {
                    const d = tr.dataset;
                    const subName = tr.querySelector('.grp-sub-name').value.trim();
                    if (!subName) { toastErr('Vui lòng nhập tên cho mọi dòng nhóm con'); return; }
                    children.push({
                        id: d.subId || '', code: d.subCode || '', name: subName,
                        sort_order: d.subSort || 0, description: d.subDesc || '',
                        image: d.subImage || '', is_active: d.subId ? (d.subActive || 0) : 1,
                    });
                }

                const main = {
                    name, parent_id: dialog.dataset.parentId,
                    sort_order: dialog.dataset.sortOrder, description: dialog.dataset.description, is_active: statusOn,
                };

                if (isAdd) postForm(URL_STORE, 'POST', main, children);
                else postForm(`${URL_BASE}/${dialog.dataset.id}`, 'PUT', main, children);
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
            // Mở cây tới nhóm $id rồi chọn nó — bảng chuyển sang hiện nhóm con của nó.
            function focusNode(id) {
                const path = pathToNode(Number(id));
                if (!path.length) return;
                for (const n of path) state.expanded.add(`${n.rule}:${n.id}`);
                const target = path[path.length - 1];
                state.selectedKey = `${target.rule}:${target.id}`;
                state.selectedRows.clear();
                state.page = 1;
            }

            function findNode2(id) {
                let found = null;
                const walk = (list) => { for (const n of list) { if (n.id === id) found = n; else if (n.children.length) walk(n.children); } };
                walk(TREE);
                return found;
            }

            // Sau khi tạo/sửa: mở cây tới nhóm cha và chọn nó để thấy nhóm vừa lưu.
            if (FOCUS_PARENT != null) focusNode(FOCUS_PARENT);

            renderAll();
        })();
    </script>
@endsection
