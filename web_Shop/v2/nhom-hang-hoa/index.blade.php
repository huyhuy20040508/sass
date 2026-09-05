{{-- Màn Nhóm hàng hoá dựng theo khuôn v2 (menu/menu-group/index).
     Dữ liệu do CategoryController đẩy sang: $categories (danh sách PHẲNG).

     KHÔNG có ô thuế: thuế đặt ở từng mặt hàng, nhóm chỉ là chỗ xếp hàng. --}}
@extends('v2::layouts.master')

@section('title', 'Nhóm hàng hoá')

@push('styles')
    <link href="{{ asset('v2/css/bootstrap-treeview.css') }}" rel="stylesheet">
    <style>
        .treeview .list-group-item { cursor: pointer; }
        .treeview span.indent { margin-left: 10px; margin-right: 10px; }
        .treeview span.icon { width: 12px; margin-right: 1px !important; }
        .fillter-box .card-body { padding: 15px 5px; }
        .fillter-box .card-body .side-bar-menu-group { max-height: 70vh; overflow-y: auto; }
        .form-label { font-weight: bold; }
        .delete-record-category { color: #dc3545 !important; cursor: pointer; }

        /* style.css của v2 ép MỌI th/td canh giữa bằng !important, nên hai cột
           chữ ở đây phải giành lại cũng bằng !important mới canh trái được. */
        #modalMenuCategory .table-create-cate-child { width: 100%; }
        #modalMenuCategory .table-create-cate-child th,
        #modalMenuCategory .table-create-cate-child td {
            text-align: left !important;
            vertical-align: middle;
            padding: 6px 8px;
        }
        #modalMenuCategory .table-create-cate-child th:last-child,
        #modalMenuCategory .table-create-cate-child td:last-child { text-align: center !important; }

        /* Hàng ô đầu hộp dùng flex chứ không .row: .row có lề âm 12px mỗi bên,
           đặt thẳng trong .modal-body là nội dung tràn ra sát mép hộp. */
        #modalMenuCategory .hang-o { display: flex; flex-wrap: wrap; gap: 16px; }
        #modalMenuCategory .hang-o > div { min-width: 0; }
    </style>
@endpush

@section('content')
    {{-- Nút mở cây nhóm, chỉ hiện trên điện thoại. --}}
    <div class="call-to-action-container">
        <div class="wrapper-call-to-action">
            <div class="btn-open-modal" type="button" data-bs-toggle="offcanvas" data-bs-target="#offcanvasBottomInMobile"
                aria-controls="offcanvasBottomInMobile" data-offcanvas-target="#filterTree">
                <p class="open-modal-label">Cây nhóm</p>
                <div class="icon-for-cta"><i class="fa-solid fa-sitemap"></i></div>
            </div>
        </div>
    </div>

    <div class="row d-flex flex-column flex-lg-row">
        {{-- Cột trái: cây loại lớn → nhóm → nhóm con. --}}
        <div class="col-12 col-lg-2_5 pe-lg-0">
            <div class="fillter-box w-100">
                <div class="card">
                    <div class="card-header card-header-primary header_search">Cây nhóm hàng hoá</div>
                    <div class="card-body px-2">
                        <div id="filterTree">
                            <div class="inner-modal-in-mobile">
                                <div class="d-flex justify-content-center mb-2">
                                    <a type="button" class="bt btn_green update-parent">{{ __('message.update-btn') }}</a>
                                    <a type="button" class="bt btn_red delete-parent ms-2">{{ __('message.delete') }}</a>
                                </div>
                                <div class="side-bar-menu-group"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Cột phải: nhóm con của nhóm đang chọn trên cây. --}}
        <div class="col-12 col-lg-9_5 category-group-management-container">
            <div class="content_midd">
                <div class="content_midd_title justify-content-center justify-content-lg-between">
                    {{-- h1 chứ không phải h4: đây là tiêu đề cấp 1 của trang, trình
                         đọc màn hình lấy nó làm mốc. Class .tieu-de-trang giữ nguyên
                         dáng chữ của h4 bên v2. --}}
                    <h1 class="tieu-de-trang">Quản lý nhóm hàng hoá</h1>
                    <div class="justify-content-end">
                        <div class="btn_top_content">
                            <a type="button" class="bt btn_green add-item">{{ __('message.create') }}</a>
                            <a type="button" class="bt btn_red mass-delete ms-2">{{ __('message.delete') }}</a>
                        </div>
                    </div>
                </div>
                <div class="list scrollDiv table-responsive table-border-style"></div>
            </div>
        </div>
    </div>

    {{-- ===================== Hộp Thêm / Sửa nhóm ===================== --}}
    <div class="modal" id="modalMenuCategory">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg mx-auto">
            <div class="modal-content">
                <div class="modal-header">
                    <h4 class="modal-title">{{ __('message.add') }} / {{ __('message.edit') }}</h4>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <div class="modal_center">
                        <input type="hidden" id="id-group">
                        <input type="hidden" id="parent-group">

                        {{-- Nhóm cha là nhóm đang chọn trên cây, không có ô để đổi —
                             nói rõ ra để người dùng biết nhóm mới rơi vào đâu. --}}
                        <p class="mb-3" style="color:#6c757d">Nhóm cha: <b id="parent-name"></b></p>

                        <div class="hang-o">
                            <div style="flex: 1 1 160px">
                                <label for="code-group" class="form-label">Mã nhóm</label>
                                {{-- Mã do máy chủ tự sinh; ô này chỉ để xem. --}}
                                <input type="text" class="form-control" id="code-group" disabled
                                    placeholder="Tự tạo khi lưu">
                            </div>
                            <div style="flex: 2 1 240px">
                                <label for="name-group" class="form-label">Tên nhóm <span style="color:red">*</span></label>
                                <input type="text" class="form-control" id="name-group"
                                    placeholder="Nhập tên nhóm hàng hoá" maxlength="150">
                            </div>
                            <div style="flex: 0 0 110px">
                                <label class="form-label d-block">{{ __('message.status') }}</label>
                                <input type="checkbox" class="switch_customer" id="status-group" checked>
                            </div>
                        </div>

                        <hr class="my-4">

                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                            <label class="form-label mb-0">Nhóm con</label>
                            <div class="d-flex gap-2">
                                <button type="button" class="bt btn_green create-cate-child">
                                    <i class="fas fa-plus me-1"></i> Thêm dòng
                                </button>
                                <button type="button" class="bt btn_red delete-cate-child">
                                    <i class="fas fa-times me-1"></i> {{ __('message.delete_all') }}
                                </button>
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table class="table-create-cate-child">
                                <thead>
                                    <tr>
                                        <th style="width: 30%">Mã nhóm</th>
                                        <th style="width: 58%">Tên nhóm <span style="color:red">*</span></th>
                                        <th style="width: 12%">{{ __('message.action') }}</th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                            <p class="text-center py-3 mb-0 khong-co-con" style="color:#8c8c8c">Chưa có nhóm con nào.</p>
                        </div>
                    </div>
                </div>

                <div class="modal-footer justify-content-center">
                    <button type="button" data-bs-dismiss="modal" class="bt btn_red">{{ __('message.close') }}</button>
                    <button type="button" class="bt btn_green submit-create-group">{{ __('message.save') }}</button>
                </div>
            </div>
        </div>
    </div>

    {{-- ===================== Hộp Xoá nhóm ===================== --}}
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

    {{-- ===================== Hộp Xoá toàn bộ nhóm con ===================== --}}
    <div class="modal" id="deleteItemChild">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title">{{ __('message.delete') }} ?</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="deleteValueChild">
                    <div class="modal_center">
                        <div class="row">
                            <div class="col"><label class="form-label">{{ __('message.delete-confirm') }}</label></div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer justify-content-center">
                    <button type="button" class="bt btn_red" data-bs-dismiss="modal">{{ __('message.close') }}</button>
                    <button type="button" class="bt btn_green delete-value-child">{{ __('message.delete') }}</button>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('v2/js/bootstrap-treeview.js') }}"></script>
    <script>
        const CSRF = '{{ csrf_token() }}';
        const URL_BASE = @json(url('/admin/categories'));
        const URL_STORE = @json(route('admin.categories.store'));
        const URL_BULK = @json(route('admin.categories.bulkDestroy'));

        // Danh sách PHẲNG; cây dựng tại đây theo parent_id.
        const DANH_MUC = @json($categories);
        // Sau khi lưu, controller nhớ hộ nhóm cha để lượt tải lại tự mở đúng nhánh.
        const NHOM_MO = @json(session('focus_parent'));

        const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) =>
            ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

        /** Mọi thao tác ghi đi bằng form POST ẩn: trang tải lại và toast do session bắn ra. */
        function postForm(action, method, fields) {
            const $f = $('<form>', { method: 'POST', action, css: { display: 'none' } });
            const them = (n, v) => $f.append($('<input>', {
                type: 'hidden', name: n, value: typeof v === 'boolean' ? (v ? 1 : 0) : (v == null ? '' : v),
            }));
            them('_token', CSRF);
            if (method && method !== 'POST') them('_method', method);
            $.each(fields || {}, (k, v) => {
                Array.isArray(v) ? v.forEach((x) => them(k + '[]', x)) : them(k, v);
            });
            $('body').append($f);
            $f.trigger('submit');
        }

        // =====================================================================
        //  Cây nhóm
        // =====================================================================
        let nhomDangChon = null;   // node đang chọn trên cây

        /**
         * Dựng dữ liệu cho bootstrap-treeview.
         *
         * `daQua` chặn đệ quy vô tận: cây không giới hạn cấp nên một dòng dữ liệu
         * hỏng kiểu A → B → A sẽ quay vòng mãi.
         */
        function dungCay() {
            const theoCha = new Map();
            DANH_MUC.forEach((c) => {
                const p = c.parent_id == null ? 0 : c.parent_id;
                if (!theoCha.has(p)) theoCha.set(p, []);
                theoCha.get(p).push(c);
            });

            const lam = (c, daQua) => {
                const tiep = new Set(daQua).add(c.id);
                const con = (theoCha.get(c.id) || []).filter((ch) => !tiep.has(ch.id)).map((ch) => lam(ch, tiep));
                const node = {
                    text: c.name,
                    tags: con.length ? [String(con.length)] : undefined,
                    // Nhóm gốc cố định (Hàng bán / Hàng hoá khác) chỉ để chứa.
                    khoa: !!c.protected,
                    data: {
                        id: c.id,
                        name: c.name,
                        code: c.slug || '',
                        parent_id: c.parent_id == null ? null : c.parent_id,
                        status: !!c.is_active,
                        sort_order: c.sort_order || 0,
                        description: c.description || '',
                    },
                };
                if (con.length) node.nodes = con;

                return node;
            };

            return (theoCha.get(0) || []).map((c) => lam(c, new Set()));
        }

        function veCay() {
            const $cay = $('.side-bar-menu-group');
            $cay.treeview({
                data: dungCay(),
                showTags: true,
                levels: 2,
                onhoverColor: '#b0d2df',
                collapseIcon: 'fa-solid fa-caret-down',
                expandIcon: 'fa-solid fa-caret-right',
                onNodeSelected: function (event, node) {
                    nhomDangChon = node;
                    veBang(node.nodes || []);
                },
                onNodeUnselected: function () {
                    nhomDangChon = null;
                    $('.list').empty();
                },
            });

            // Mở lại đúng nhánh vừa thao tác; chưa có thì chọn nhóm gốc đầu tiên.
            const moNode = timNode(NHOM_MO) || $cay.treeview('getNode', 0);
            if (moNode) {
                $cay.treeview('revealNode', [moNode.nodeId, { silent: true }]);
                $cay.treeview('selectNode', [moNode.nodeId]);
            }
        }

        const timNode = (id) => (id ? duyetNode((n) => Number(n.data.id) === Number(id)) : null);

        /** Duyệt mọi node đang có trong cây, trả node đầu tiên khớp. */
        function duyetNode(hop) {
            const ds = $('.side-bar-menu-group').treeview('getEnabled') || [];
            for (const n of ds) {
                if (n.data && hop(n)) return n;
            }

            return null;
        }

        // =====================================================================
        //  Bảng nhóm con
        // =====================================================================
        function veBang(nodes) {
            if (!nodes.length) {
                $('.list').html('<p class="text-center py-4" style="color:#8c8c8c">Nhóm này chưa có nhóm con nào.</p>');

                return;
            }

            const dong = nodes.map((n, i) => {
                const d = n.data;

                return `<tr>
                    <td class="text-center"><input data-id="${d.id}" class="form-check-input item-select" type="checkbox"></td>
                    <td class="text-center">${i + 1}</td>
                    <td class="text-left">${esc(d.code)}</td>
                    <td class="text-left">${esc(d.name)}</td>
                    <td class="text-center">
                        <input type="checkbox" class="switch_customer doi-trang-thai" data-id="${d.id}" ${d.status ? 'checked' : ''}>
                    </td>
                    <td class="text-center">
                        <a href="#" class="sua-nhom mx-1" data-id="${d.id}"><i class="fas fa-edit"></i></a>
                        <a href="#" class="xoa-nhom text-danger" data-id="${d.id}"><i class="fas fa-trash-alt"></i></a>
                    </td>
                </tr>`;
            }).join('');

            $('.list').html(`<table>
                <thead>
                    <tr>
                        <th class="text-center"><input class="form-check-input item-select-all" type="checkbox"></th>
                        <th class="text-center">{{ __('message.stt') }}</th>
                        <th class="text-left">Mã nhóm</th>
                        <th class="text-left">Tên nhóm</th>
                        <th class="text-center">{{ __('message.status') }}</th>
                        <th class="text-center">{{ __('message.action') }}</th>
                    </tr>
                </thead>
                <tbody>${dong}</tbody>
            </table>`);
        }

        $(document).on('change', '.list .item-select-all', function () {
            $('.list .item-select').prop('checked', this.checked);
        });

        // Công tắc trạng thái: gửi lại nguyên các trường khác, không thì lưu rỗng đè lên.
        $(document).on('change', '.list .doi-trang-thai', function () {
            const n = duyetNode((x) => Number(x.data.id) === Number($(this).data('id')));
            if (!n) return;
            const d = n.data;
            postForm(URL_BASE + '/' + d.id, 'PUT', {
                name: d.name, parent_id: d.parent_id, sort_order: d.sort_order,
                description: d.description, is_active: this.checked,
            });
        });

        // =====================================================================
        //  Hộp Thêm / Sửa
        // =====================================================================
        const $hop = $('#modalMenuCategory');

        function dongCon(con) {
            const d = con ? con.data : null;

            return `<tr ${d ? 'data-id-con="' + d.id + '"' : ''}>
                <td><input type="text" class="form-control" disabled value="${d ? esc(d.code) : 'Tự tạo khi lưu'}"></td>
                <td><input type="text" class="form-control ten-con" placeholder="Nhập tên nhóm con" value="${d ? esc(d.name) : ''}"></td>
                <td class="text-center"><i class="fas fa-times delete-record-category"></i></td>
            </tr>`;
        }

        function xoaTrangHop() {
            $hop.find('#id-group, #parent-group, #code-group, #name-group').val('');
            $hop.find('#status-group').prop('checked', true);
            $hop.find('.table-create-cate-child tbody').empty();
            veChoTrongCon();
        }

        /** Bảng nhóm con rỗng thì giấu bảng đi, hiện một dòng chữ thay cho hàng tiêu đề trơ. */
        function veChoTrongCon() {
            const co = $hop.find('.table-create-cate-child tbody tr').length > 0;
            $hop.find('.table-create-cate-child').toggle(co);
            $hop.find('.khong-co-con').toggle(!co);
        }

        /** Đổ một node lên hộp để sửa, kèm cả dãy nhóm con của nó. */
        function moSua(node) {
            const d = node.data;
            xoaTrangHop();
            $hop.find('.modal-title').text('{{ __('message.edit') }}');
            $hop.find('#id-group').val(d.id);
            $hop.find('#parent-group').val(d.parent_id == null ? '' : d.parent_id);
            $hop.find('#code-group').val(d.code);
            $hop.find('#name-group').val(d.name);
            $hop.find('#status-group').prop('checked', d.status);
            const cha = timNode(d.parent_id);
            $hop.find('#parent-name').text(cha ? cha.data.name : '—');
            (node.nodes || []).forEach((c) => $hop.find('.table-create-cate-child tbody').append(dongCon(c)));
            veChoTrongCon();
            $hop.modal('show');
        }

        $(document).on('click', '.add-item', function () {
            if (!nhomDangChon) {
                toastr.error('Hãy chọn một nhóm ở cây bên trái trước khi thêm.');

                return;
            }
            xoaTrangHop();
            $hop.find('.modal-title').text('{{ __('message.create') }}');
            $hop.find('#parent-group').val(nhomDangChon.data.id);
            $hop.find('#parent-name').text(nhomDangChon.data.name);
            $hop.modal('show');
        });

        // Sửa / xoá chính nhóm đang chọn trên cây.
        $(document).on('click', '.update-parent', function () {
            if (!nhomDangChon) { toastr.error('Hãy chọn một nhóm ở cây bên trái.'); return; }
            if (nhomDangChon.khoa) { toastr.error('Đây là nhóm gốc cố định, không sửa được.'); return; }
            moSua(nhomDangChon);
        });

        $(document).on('click', '.delete-parent', function () {
            if (!nhomDangChon) { toastr.error('Hãy chọn một nhóm ở cây bên trái.'); return; }
            if (nhomDangChon.khoa) { toastr.error('Đây là nhóm gốc cố định, không xoá được.'); return; }
            $('#deleteValue').val(nhomDangChon.data.id);
            $('#deleteItem').modal('show');
        });

        $(document).on('click', '.list .sua-nhom', function (e) {
            e.preventDefault();
            const n = duyetNode((x) => Number(x.data.id) === Number($(this).data('id')));
            if (n) moSua(n);
        });

        // Dãy nhóm con trong hộp.
        $(document).on('click', '#modalMenuCategory .create-cate-child', function () {
            $hop.find('.table-create-cate-child tbody').append(dongCon(null));
            veChoTrongCon();
            $hop.find('.table-create-cate-child tbody tr:last .ten-con').focus();
        });

        $(document).on('click', '#modalMenuCategory .delete-cate-child', function () {
            const id = $hop.find('#id-group').val();
            const daLuu = $hop.find('.table-create-cate-child tbody tr[data-id-con]').length;
            // Dòng đã lưu thì phải hỏi máy chủ; dòng mới gõ thì bỏ tại chỗ.
            if (id && daLuu) {
                $('#deleteValueChild').val(id);
                $('#deleteItemChild').modal('show');

                return;
            }
            $hop.find('.table-create-cate-child tbody').empty();
            veChoTrongCon();
        });

        $(document).on('click', '#modalMenuCategory .delete-record-category', function () {
            const $tr = $(this).closest('tr');
            const idCon = $tr.attr('data-id-con');
            // Dòng đã lưu: xoá thật rồi tải lại. Dòng mới gõ: bỏ khỏi bảng là xong.
            if (idCon) {
                postForm(URL_BASE + '/' + idCon, 'DELETE', {});

                return;
            }
            $tr.remove();
            veChoTrongCon();
        });

        $(document).on('click', '#deleteItemChild .delete-value-child', function () {
            postForm(URL_BASE + '/' + $('#deleteValueChild').val() + '/children', 'DELETE', {});
        });

        $(document).on('click', '#modalMenuCategory .submit-create-group', function () {
            const id = $hop.find('#id-group').val();
            const ten = $hop.find('#name-group').val().trim();
            if (!ten) { toastr.error('Chưa nhập tên nhóm hàng hoá.'); return; }

            const truong = {
                name: ten,
                parent_id: $hop.find('#parent-group').val(),
                sort_order: 0,
                description: '',
                is_active: $hop.find('#status-group').is(':checked'),
            };

            // Nhóm con gom thành children[i][...] — controller đọc đúng khuôn này.
            let thieuTen = false;
            $hop.find('.table-create-cate-child tbody tr').each(function (i) {
                const tenCon = $(this).find('.ten-con').val().trim();
                if (!tenCon) { thieuTen = true; return; }
                truong['children[' + i + '][id]'] = $(this).attr('data-id-con') || '';
                truong['children[' + i + '][name]'] = tenCon;
                truong['children[' + i + '][is_active]'] = 1;
            });

            if (thieuTen) { toastr.error('Còn nhóm con chưa nhập tên.'); return; }

            // Lưu bằng AJAX: hỏng thì GIỮ HỘP LẠI cho người dùng sửa (VD trùng
            // tên), thay vì tải lại trang làm mất sạch cả cụm nhóm con vừa gõ.
            V2.luuHop('#modalMenuCategory', id ? URL_BASE + '/' + id : URL_STORE,
                id ? 'PUT' : 'POST', truong, $(this));
        });

        // =====================================================================
        //  Xoá
        // =====================================================================
        $(document).on('click', '.list .xoa-nhom', function (e) {
            e.preventDefault();
            $('#deleteValue').val($(this).data('id'));
            $('#deleteItem').modal('show');
        });

        $(document).on('click', '.mass-delete', function () {
            const ids = $('.list .item-select:checked').map((i, el) => $(el).data('id')).get();
            if (!ids.length) { toastr.error('{{ __('message.delete-none') }}'); return; }
            $('#deleteValue').val(ids.join(','));
            $('#deleteItem').modal('show');
        });

        $(document).on('click', '#deleteItem .delete-value', function () {
            const ids = String($('#deleteValue').val()).split(',').filter(Boolean);
            if (!ids.length) return;
            ids.length === 1
                ? postForm(URL_BASE + '/' + ids[0], 'DELETE', {})
                : postForm(URL_BULK, 'POST', { ids: ids });
        });

        veCay();
    </script>
@endpush
