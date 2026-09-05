@extends('layouts.app')

@section('title', \App\Http\Controllers\PromotionController::TITLE)

@section('content')
    {{--
        Trang "Khuyến mãi" — dựng theo đúng khuôn trang Nhà cung cấp:
        [ header ] + [ thanh lọc realtime ] + [ bảng ] + [ chân trang ] + [ modal CRUD ].

        Đây là thứ QUYẾT ĐỊNH GIÁ BÁN ngoài cửa hàng, nên trang này nói rõ ba điều mà
        người bán hay hỏi nhất:
          - Đợt này ĐANG chạy hay chưa tới ngày (trạng thái, không chỉ bật/tắt).
          - Nó đang phủ BAO NHIÊU sản phẩm (cột Phạm vi).
          - Giảm bao nhiêu, tới khi nào.

        Giá gốc của sản phẩm KHÔNG bị ghi đè: API tính mức giảm lúc khách đọc hàng,
        nên tắt hoặc xoá chương trình là giá tự về như cũ ngay lập tức.
    --}}
    @php
        $STATUSES = \App\Http\Controllers\PromotionController::STATUSES;
        $TYPES = \App\Http\Controllers\PromotionController::DISCOUNT_TYPES;
        $SORTS = \App\Http\Controllers\PromotionController::SORTS;
        $PAGE_SIZES = \App\Http\Controllers\PromotionController::PAGE_SIZES;
        $TITLE = \App\Http\Controllers\PromotionController::TITLE;

        $firstRank = ($meta['page'] - 1) * $meta['page_size'];
        $hasFilter = $filters['keyword'] !== ''
            || $filters['status'] !== 'all'
            || $filters['from_date'] !== ''
            || $filters['to_date'] !== ''
            || $filters['sort'] !== 'newest';

        $money = fn ($v) => number_format((float) $v, 0, ',', '.') . '₫';
        $num = fn ($v) => number_format((int) $v, 0, ',', '.');

        // Mức giảm dạng chữ: "-20%" hoặc "-50.000₫", kèm trần giảm nếu có.
        $discountText = function (array $p) use ($money) {
            $value = (float) ($p['discount_value'] ?? 0);
            if (($p['discount_type'] ?? '') === 'percentage') {
                return '-' . rtrim(rtrim(number_format($value, 2, ',', '.'), '0'), ',') . '%';
            }
            return '-' . $money($value);
        };

        // Cây danh mục: thụt lề theo cấp để người chọn thấy ngay cái nào nằm trong cái nào.
        $catById = collect($categories)->keyBy(fn ($c) => (int) ($c['id'] ?? 0));
        $catDepth = function (int $id) use ($catById) {
            $depth = 0;
            $cur = $catById->get($id);
            // Chặn 10 bước phòng dữ liệu danh mục trỏ vòng.
            while ($cur && !empty($cur['parent_id']) && $depth < 10) {
                $depth++;
                $cur = $catById->get((int) $cur['parent_id']);
            }
            return $depth;
        };
    @endphp

    <div class="pmo">
        {{-- Header --}}
        <div class="pmo-head">
            <h1 class="pmo-title">{{ $TITLE }}</h1>
            <span class="pmo-sum">
                Đang chạy: <b>{{ $num($stats['running'] ?? 0) }}</b>/{{ $num($stats['total'] ?? 0) }} ·
                Chờ tới ngày: <b>{{ $num($stats['scheduled'] ?? 0) }}</b> ·
                Tạm dừng: <b>{{ $num($stats['paused'] ?? 0) }}</b>
                <em>(giá ngoài cửa hàng tự đổi theo, không phải sửa từng sản phẩm)</em>
            </span>
        </div>

        {{-- Bộ lọc: đổi select là chạy ngay, gõ tìm kiếm thì chờ 400ms — không có nút "Áp dụng" --}}
        <form method="GET" action="{{ route('admin.promotions.index') }}" id="pmoFilter" class="pmo-filter">
            <div class="pmo-toolbar">
                <div class="pmo-searchbox">
                    <input type="text" name="keyword" value="{{ $filters['keyword'] }}" class="pmo-search-input"
                           placeholder="Tìm theo tên chương trình" autocomplete="off">
                    <button type="submit" class="pmo-search-btn" title="Tìm kiếm">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                    </button>
                </div>

                <select name="status" class="pmo-select" title="Lọc theo trạng thái">
                    <option value="all" {{ $filters['status'] === 'all' ? 'selected' : '' }}>
                        Tất cả trạng thái ({{ $num($stats['total'] ?? 0) }})
                    </option>
                    @foreach($STATUSES as $value => $label)
                        <option value="{{ $value }}" {{ $filters['status'] === $value ? 'selected' : '' }}>
                            {{ $label }} ({{ $num($stats[$value] ?? 0) }})
                        </option>
                    @endforeach
                </select>

                @include('partials.date-range', [
                    'formId' => 'pmoFilter',
                    'from' => $filters['from_date'],
                    'to' => $filters['to_date'],
                    'title' => 'Lọc theo khoảng thời gian chạy',
                ])

                <select name="sort" class="pmo-select" title="Sắp xếp">
                    @foreach($SORTS as $value => $label)
                        <option value="{{ $value }}" {{ $filters['sort'] === $value ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>

                @if($hasFilter)
                    <a href="{{ route('admin.promotions.index') }}" class="pmo-clear">Xoá lọc</a>
                @endif

                <div class="pmo-toolbar-actions">
                    <button type="button" class="pmo-btn-primary" id="pmoAddBtn">
                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M12 5v14"/></svg>
                        Tạo chương trình
                    </button>

                    <div class="pmo-util" id="pmoUtil">
                        <button type="button" class="pmo-util-btn" id="pmoUtilBtn" aria-haspopup="true" aria-expanded="false">
                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="1.6"/><circle cx="19" cy="12" r="1.6"/><circle cx="5" cy="12" r="1.6"/></svg>
                            Tiện ích
                            <svg class="pmo-util-caret" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                        </button>
                        <div class="pmo-util-menu">
                            <a href="{{ route('admin.promotions.export', request()->query()) }}" class="pmo-util-item">
                                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5 5 5-5"/><path d="M12 15V3"/></svg>
                                Xuất file (CSV)
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Giữ số dòng/trang khi đổi bộ lọc --}}
            <input type="hidden" name="page_size" value="{{ $meta['page_size'] }}">
        </form>

        {{-- Bảng --}}
        <div class="pmo-table-wrap">
            <table class="pmo-table">
                <thead>
                    <tr>
                        <th class="pmo-c-check"><input type="checkbox" id="pmoCheckAll" class="pmo-check" title="Chọn tất cả trong trang"></th>
                        <th class="pmo-c-stt">STT</th>
                        <th class="pmo-c-name">Chương trình</th>
                        <th class="pmo-c-off">Mức giảm</th>
                        <th class="pmo-c-scope">Phạm vi</th>
                        <th class="pmo-c-time">Thời gian chạy</th>
                        <th class="pmo-c-state">Trạng thái</th>
                        <th class="pmo-c-switch">Bật</th>
                        <th class="pmo-c-act">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($promotions as $i => $p)
                        @php
                            $id = (int) ($p['id'] ?? 0);
                            $name = (string) ($p['name'] ?? '');
                            $state = (string) ($p['status'] ?? 'ended');
                            $isOn = (bool) ($p['is_active'] ?? false);
                            $start = !empty($p['start_at']) ? \Illuminate\Support\Carbon::parse($p['start_at']) : null;
                            $end = !empty($p['end_at']) ? \Illuminate\Support\Carbon::parse($p['end_at']) : null;
                            $nProduct = count($p['product_ids'] ?? []);
                            $nCategory = count($p['category_ids'] ?? []);
                        @endphp
                        <tr data-id="{{ $id }}">
                            <td class="pmo-c-check"><input type="checkbox" class="pmo-check pmo-row-check" value="{{ $id }}"
                                       aria-label="Chọn chương trình {{ $name !== '' ? $name : $id }}"></td>
                            <td class="pmo-c-stt">{{ $firstRank + $i + 1 }}</td>
                            <td class="pmo-c-name" data-edit="{{ $id }}" title="Bấm để sửa chương trình">
                                <span class="pmo-name">{{ $name !== '' ? $name : '—' }}</span>
                                <span class="pmo-sub">
                                    {{ $TYPES[$p['discount_type'] ?? ''] ?? '' }}
                                    @if(!empty($p['max_discount_amount'])) · tối đa {{ $money($p['max_discount_amount']) }} @endif
                                    @if(!empty($p['description'])) · {{ \Illuminate\Support\Str::limit($p['description'], 60) }} @endif
                                </span>
                            </td>
                            <td class="pmo-c-off"><span class="pmo-off">{{ $discountText($p) }}</span></td>
                            <td class="pmo-c-scope">
                                {{-- Số sản phẩm bị phủ là con số quan trọng nhất; hai nhóm đích chỉ
                                     là cách khai ra con số đó nên xuống dòng phụ. --}}
                                <span class="pmo-scope-total">{{ $num($p['product_count'] ?? 0) }} sản phẩm</span>
                                <span class="pmo-sub">
                                    @php
                                        $parts = [];
                                        if ($nCategory) $parts[] = $nCategory.' danh mục';
                                        if ($nProduct) $parts[] = $nProduct.' sản phẩm lẻ';
                                    @endphp
                                    {{ $parts ? implode(' · ', $parts) : 'Chưa khai phạm vi' }}
                                </span>
                            </td>
                            <td class="pmo-c-time">
                                <span class="pmo-time">{{ $start?->format('d/m/Y H:i') ?? '—' }}</span>
                                <span class="pmo-sub">đến {{ $end?->format('d/m/Y H:i') ?? '—' }}</span>
                            </td>
                            <td class="pmo-c-state"><span class="pmo-badge pmo-badge-{{ $state }}">{{ $STATUSES[$state] ?? $state }}</span></td>
                            <td class="pmo-c-switch">
                                <button type="button" class="pmo-switch {{ $isOn ? 'on' : '' }}"
                                        data-toggle="{{ $id }}" data-on="{{ $isOn ? 1 : 0 }}" data-state="{{ $state }}"
                                        title="{{ $isOn ? 'Đang bật — bấm để tạm dừng' : 'Đang tắt — bấm để bật lại' }}">
                                    <span class="pmo-switch-knob"></span>
                                </button>
                            </td>
                            <td class="pmo-c-act">
                                <div class="pmo-rowacts">
                                    <button type="button" class="pmo-rowbtn pmo-edit" data-edit="{{ $id }}" title="Sửa chương trình">
                                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                                    </button>
                                    <button type="button" class="pmo-rowbtn pmo-del" data-remove="{{ $id }}" title="Xoá chương trình">
                                        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/></svg>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="pmo-empty">
                                @if($hasFilter)
                                    Không tìm thấy chương trình nào khớp bộ lọc. Thử xoá bớt điều kiện lọc.
                                @else
                                    Chưa có chương trình khuyến mãi nào. Bấm “Tạo chương trình” để mở đợt giảm giá đầu tiên —
                                    chọn sản phẩm hoặc danh mục rồi đặt ngày bắt đầu và kết thúc, tới giờ hệ thống tự chạy.
                                @endif
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @include('partials.pagination', [
            'meta' => $meta,
            'noun' => 'chương trình',
            'perPageName' => 'page_size',
            'perPageOptions' => $PAGE_SIZES,
        ])
    </div>

    <div id="pmoBulkMount"></div>

    {{-- Modal Tạo / Sửa chương trình --}}
    <div class="pmo-overlay" id="pmoFormOverlay" style="display:none;">
        <div class="pmo-dialog">
            <div class="pmo-modal-head">
                <h4 class="pmo-modal-title" id="pmoFormTitle">Tạo chương trình khuyến mãi</h4>
                <button type="button" class="pmo-modal-x" data-close>
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </div>

            <form id="pmoForm" method="POST" action="{{ route('admin.promotions.store') }}">
                @csrf
                <input type="hidden" name="_method" id="pmoFormMethod" value="POST">
                <input type="hidden" name="return" value="{{ request()->getRequestUri() }}">

                <div class="pmo-modal-body">
                    <div class="pmo-grid2">
                        <div class="pmo-col-2">
                            <label class="pmo-field-label" for="pmoName">Tên chương trình <span class="pmo-req">*</span></label>
                            <input type="text" id="pmoName" name="name" class="pmo-input" maxlength="150" required
                                   autocomplete="off" placeholder="VD: Sale hè — giảm 20% áo CLB">
                            <p class="pmo-hint">Tên này hiện cho khách thấy ngay dưới giá ở trang sản phẩm.</p>
                        </div>

                        <div class="pmo-col-2">
                            <label class="pmo-field-label" for="pmoDesc">Mô tả</label>
                            <input type="text" id="pmoDesc" name="description" class="pmo-input" maxlength="255"
                                   autocomplete="off" placeholder="Ghi chú nội bộ, VD: đợt xả hàng mùa cũ">
                        </div>

                        <div>
                            <label class="pmo-field-label" for="pmoType">Kiểu giảm <span class="pmo-req">*</span></label>
                            <select id="pmoType" name="discount_type" class="pmo-msel" required>
                                @foreach($TYPES as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="pmo-field-label" for="pmoValue">
                                Mức giảm <span class="pmo-req">*</span>
                                <span class="pmo-unit" id="pmoValueUnit">(%)</span>
                            </label>
                            <input type="text" id="pmoValue" name="discount_value" class="pmo-input" required
                                   inputmode="numeric" autocomplete="off" placeholder="20">
                            <p class="pmo-hint" id="pmoValueHint">Giảm bao nhiêu phần trăm trên giá đang bán của mỗi sản phẩm.</p>
                        </div>

                        {{-- Trần giảm chỉ có nghĩa khi giảm theo %: ẩn hẳn ở kiểu giảm số tiền
                             thay vì để đó rồi có người tưởng nó đang có tác dụng. --}}
                        <div id="pmoMaxWrap">
                            <label class="pmo-field-label" for="pmoMax">Trần giảm <span class="pmo-unit">(₫)</span></label>
                            <input type="text" id="pmoMax" name="max_discount_amount" class="pmo-input"
                                   inputmode="numeric" autocomplete="off" placeholder="Bỏ trống = không giới hạn">
                            <p class="pmo-hint">VD: giảm 30% nhưng tối đa 200.000₫ mỗi sản phẩm.</p>
                        </div>
                        <div>
                            <label class="pmo-field-label" for="pmoActive">Trạng thái <span class="pmo-req">*</span></label>
                            <select id="pmoActive" name="is_active" class="pmo-msel" required>
                                <option value="1">Bật</option>
                                <option value="0">Tạm dừng</option>
                            </select>
                            <p class="pmo-hint">Bật rồi vẫn phải tới ngày bắt đầu mới thật sự giảm giá.</p>
                        </div>

                        <div>
                            <label class="pmo-field-label" for="pmoStart">Bắt đầu <span class="pmo-req">*</span></label>
                            <input type="datetime-local" id="pmoStart" name="start_at" class="pmo-input" required>
                        </div>
                        <div>
                            <label class="pmo-field-label" for="pmoEnd">Kết thúc <span class="pmo-req">*</span></label>
                            <input type="datetime-local" id="pmoEnd" name="end_at" class="pmo-input" required>
                        </div>
                    </div>

                    {{-- ---- Phạm vi áp dụng ---- --}}
                    <div class="pmo-scope-wrap">
                        <div class="pmo-scope-title">
                            Phạm vi áp dụng <span class="pmo-req">*</span>
                            <span class="pmo-scope-sum" id="pmoScopeSum">Chưa chọn gì</span>
                        </div>
                        <p class="pmo-hint pmo-scope-note">
                            Chọn ít nhất một mục. Chọn danh mục cha là phủ luôn mọi danh mục con.
                            Sản phẩm dính nhiều chương trình thì lấy mức giảm CÓ LỢI NHẤT cho khách, không cộng dồn.
                        </p>

                        <div class="pmo-scope-grid">
                            <div class="pmo-scope">
                                <div class="pmo-scope-head">
                                    <span>Danh mục</span>
                                    <span class="pmo-scope-count" data-count="category">0</span>
                                </div>
                                <input type="text" class="pmo-scope-search" data-search="category" placeholder="Tìm danh mục…" autocomplete="off">
                                <div class="pmo-scope-list" data-list="category">
                                    @forelse($categories as $c)
                                        <label class="pmo-pick" data-text="{{ mb_strtolower($c['name'] ?? '', 'UTF-8') }}">
                                            <input type="checkbox" class="pmo-check" name="category_ids[]" value="{{ $c['id'] ?? 0 }}" data-kind="category">
                                            <span class="pmo-pick-name" style="padding-left: {{ $catDepth((int) ($c['id'] ?? 0)) * 14 }}px">{{ $c['name'] ?? '' }}</span>
                                        </label>
                                    @empty
                                        <p class="pmo-scope-empty">Chưa có danh mục nào.</p>
                                    @endforelse
                                </div>
                            </div>

                            <div class="pmo-scope">
                                <div class="pmo-scope-head">
                                    <span>Sản phẩm lẻ</span>
                                    <span class="pmo-scope-count" data-count="product">0</span>
                                </div>
                                <input type="text" class="pmo-scope-search" data-search="product" placeholder="Tìm theo tên hoặc SKU…" autocomplete="off">
                                <div class="pmo-scope-list" data-list="product">
                                    @forelse($products as $pr)
                                        <label class="pmo-pick" data-text="{{ mb_strtolower(($pr['name'] ?? '').' '.($pr['sku'] ?? ''), 'UTF-8') }}">
                                            <input type="checkbox" class="pmo-check" name="product_ids[]" value="{{ $pr['id'] }}" data-kind="product">
                                            <span class="pmo-pick-name">
                                                {{ $pr['name'] }}
                                                <span class="pmo-pick-sub">{{ $pr['sku'] !== '' ? $pr['sku'].' · ' : '' }}{{ $money($pr['base_price']) }}</span>
                                            </span>
                                        </label>
                                    @empty
                                        <p class="pmo-scope-empty">Chưa có sản phẩm nào.</p>
                                    @endforelse
                                </div>
                                @if(count($products) >= 100)
                                    <p class="pmo-hint pmo-scope-more">Đang hiện 100 sản phẩm mới nhất. Cần đợt rộng hơn thì chọn theo danh mục.</p>
                                @endif
                            </div>
                        </div>
                    </div>

                    {{-- ---- Chi nhánh áp dụng ----
                         KHÁC hẳn khối Phạm vi ở trên: bỏ trống ở đây là HỢP LỆ và có
                         nghĩa "chạy khắp mọi kho". Vì vậy nó đứng riêng, và checkbox
                         mang class khác (.pmo-shop) — dùng chung .pmo-check là một
                         lượt tick chi nhánh sẽ bị tính thành đã chọn phạm vi. --}}
                    <div class="pmo-scope-wrap">
                        <div class="pmo-scope-title">
                            Chi nhánh áp dụng
                            <span class="pmo-scope-sum" id="pmoShopSum">Mọi chi nhánh</span>
                        </div>
                        <p class="pmo-hint pmo-scope-note">
                            Để trống là chạy ở <b>mọi chi nhánh</b>. Chỉ tick khi muốn đợt này
                            giới hạn trong vài kho — kho Quận 7 xả hàng cuối mùa thì kho trung
                            tâm không phải bán rẻ theo.
                        </p>

                        @if(count($chiNhanh) > 1)
                            <div class="pmo-scope-list" data-list="shop" style="max-height: 160px">
                                @foreach($chiNhanh as $cn)
                                    <label class="pmo-pick">
                                        <input type="checkbox" class="pmo-shop" name="shop_ids[]" value="{{ $cn['id'] ?? 0 }}">
                                        <span class="pmo-pick-name">{{ $cn['name'] ?? '' }}</span>
                                    </label>
                                @endforeach
                            </div>
                        @else
                            <p class="pmo-scope-empty">Cửa hàng chỉ có một chi nhánh — không cần chọn.</p>
                        @endif
                    </div>
                </div>

                <div class="pmo-modal-foot">
                    <button type="button" class="pmo-btn-ghost" data-close>Đóng</button>
                    <button type="submit" class="pmo-btn-primary" id="pmoFormSubmit">Lưu</button>
                </div>
            </form>
        </div>
    </div>

    <style>
        .pmo {
            /* Phá padding p-4 của <main> để tràn viền như các trang danh sách khác */
            margin: -1.5rem;
            min-height: calc(100vh - 56px);
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            color: #262626; background: #fff;
        }

        /* Header */
        .pmo-head { display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; padding: 14px 20px; }
        .pmo-title { margin: 0; font-size: 16px; font-weight: 700; color: #262626; line-height: 34px; }
        .pmo-sum { font-size: 13px; color: #595959; }
        .pmo-sum b { color: #262626; }
        .pmo-sum em { font-style: normal; font-size: 11px; color: #bfbfbf; }

        /* Bộ lọc */
        .pmo-filter { padding: 0 20px 12px; margin-bottom: 12px; border-bottom: 1px solid #eee; }
        .pmo-toolbar { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; }
        .pmo-toolbar-actions { margin-left: auto; display: flex; align-items: center; gap: 8px; }

        .pmo-searchbox { display: flex; border-radius: 4px; }
        .pmo-searchbox:focus-within { box-shadow: 0 0 0 4px rgba(13,110,253,.25); }
        .pmo-search-input {
            height: 34px; width: 280px; border: 1px solid #d9d9d9; border-right: 0; border-radius: 4px 0 0 4px;
            background: #fff; padding: 0 12px; font-size: 13px; outline: none; transition: border-color .15s;
        }
        .pmo-search-input::placeholder { color: #bfbfbf; }
        .pmo-searchbox:focus-within .pmo-search-input,
        .pmo-searchbox:focus-within .pmo-search-btn { border-color: #86b7fe; }
        .pmo-search-btn {
            height: 34px; width: 40px; display: inline-flex; align-items: center; justify-content: center;
            border: 1px solid #d9d9d9; border-radius: 0 4px 4px 0; background: #fafafa; color: #595959;
            cursor: pointer; transition: color .15s;
        }
        .pmo-search-btn:hover { color: #1890ff; }

        .pmo-select {
            height: 34px; border: 1px solid #d9d9d9; border-radius: 4px; background-color: #fff;
            padding: 0 30px 0 12px; font-size: 13px; color: #262626; cursor: pointer; outline: none;
            transition: border-color .15s; max-width: 240px; appearance: none; -webkit-appearance: none;
            background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23595959' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><path d='m6 9 6 6 6-6'/></svg>");
            background-repeat: no-repeat; background-position: right 8px center;
        }
        .pmo-select:focus { border-color: #86b7fe; box-shadow: 0 0 0 .25rem rgba(13,110,253,.25); }

        .pmo-clear {
            display: inline-flex; align-items: center; height: 34px; padding: 0 10px; border-radius: 4px;
            font-size: 13px; color: #8c8c8c; text-decoration: none; transition: background .15s, color .15s;
        }
        .pmo-clear:hover { background: #fff1f0; color: #ff4d4f; }

        /* Nút */
        .pmo-btn-primary {
            height: 34px; display: inline-flex; align-items: center; gap: 6px; border: 0; border-radius: 4px;
            background: #1890ff; color: #fff; padding: 0 16px; font-size: 13px; font-weight: 500; cursor: pointer;
            transition: background .15s;
        }
        .pmo-btn-primary:hover { background: #40a9ff; }
        .pmo-btn-primary svg { flex-shrink: 0; }
        .pmo-btn-ghost {
            height: 34px; border: 1px solid #d9d9d9; border-radius: 4px; padding: 0 16px; font-size: 13px;
            font-weight: 500; color: #595959; background: #fff; cursor: pointer; transition: border-color .15s;
        }
        .pmo-btn-ghost:hover { border-color: #bfbfbf; }

        /* Dropdown Tiện ích */
        .pmo-util { position: relative; }
        .pmo-util-btn {
            height: 34px; display: inline-flex; align-items: center; gap: 6px; border: 1px solid #d9d9d9;
            border-radius: 4px; background: #fff; padding: 0 12px; font-size: 13px; color: #595959;
            cursor: pointer; transition: border-color .15s, color .15s;
        }
        .pmo-util-btn:hover, .pmo-util.open .pmo-util-btn { border-color: #1890ff; color: #1890ff; }
        .pmo-util-caret { transition: transform .2s; }
        .pmo-util.open .pmo-util-caret { transform: rotate(180deg); }
        .pmo-util-menu {
            position: absolute; right: 0; top: calc(100% + 4px); min-width: 210px; z-index: 1050;
            background: #fff; border: 1px solid #e6e6e6; border-radius: 6px; padding: 4px;
            box-shadow: 0 6px 24px rgba(0,0,0,.12); display: none;
        }
        .pmo-util.open .pmo-util-menu { display: block; }
        .pmo-util-item {
            display: flex; align-items: center; gap: 8px; width: 100%; padding: 8px 10px; border: 0;
            background: none; border-radius: 4px; font-size: 13px; color: #262626; text-decoration: none;
            cursor: pointer; text-align: left; transition: background .15s, color .15s;
        }
        .pmo-util-item:hover { background: #f5f7fa; color: #1890ff; }
        .pmo-util-item svg { color: #8c8c8c; flex-shrink: 0; }
        .pmo-util-item:hover svg { color: #1890ff; }

        /* Bảng — th và td của cùng một cột khai CÙNG text-align để tiêu đề thẳng cột. */
        .pmo-table-wrap { width: 100%; padding: 0 20px; overflow-x: auto; scrollbar-width: thin; }
        .pmo-table-wrap::-webkit-scrollbar { height: 11px; }
        .pmo-table-wrap::-webkit-scrollbar-thumb { background-color: #dcdcdc; border-radius: 8px; border: 3px solid #fff; }

        .pmo-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .pmo-table thead th {
            text-align: left; padding: 13px 18px; border-bottom: 1px solid #f0f0f0; background: #fafafa;
            font-size: 12px; font-weight: 600; color: #8c8c8c; white-space: nowrap;
        }
        .pmo-table tbody td {
            padding: 16px 18px; border-bottom: 1px solid #f5f5f5; vertical-align: middle;
            white-space: nowrap; line-height: 1.5;
        }
        .pmo-table tbody tr:hover { background: #fafcff; }
        .pmo-table tbody tr.is-selected, .pmo-table tbody tr.is-selected:hover { background: #e6f7ff; }

        /* Mọi cột co vừa nội dung, riêng "Chương trình" hút hết khoảng dư.
           min-width là thứ giữ cho bảng THOÁNG: không có nó thì cột chỉ rộng đúng
           bằng chữ bên trong, sáu cột cuối dồn hết sang phải và dính vào nhau —
           mắt phải dò xem con số nào thuộc cột nào. */
        .pmo-table th.pmo-c-check,  .pmo-table td.pmo-c-check  { width: 1%; min-width: 46px;  text-align: center; }
        .pmo-table th.pmo-c-stt,    .pmo-table td.pmo-c-stt    { width: 1%; min-width: 58px;  text-align: center; color: #8c8c8c; }
        .pmo-table th.pmo-c-name,   .pmo-table td.pmo-c-name   { width: 100%; max-width: 0; min-width: 240px; overflow: hidden; }
        .pmo-table th.pmo-c-off,    .pmo-table td.pmo-c-off    { width: 1%; min-width: 118px; text-align: right; }
        .pmo-table th.pmo-c-scope,  .pmo-table td.pmo-c-scope  { width: 1%; min-width: 158px; }
        .pmo-table th.pmo-c-time,   .pmo-table td.pmo-c-time   { width: 1%; min-width: 162px; }
        .pmo-table th.pmo-c-state,  .pmo-table td.pmo-c-state  { width: 1%; min-width: 122px; text-align: center; }
        .pmo-table th.pmo-c-switch, .pmo-table td.pmo-c-switch { width: 1%; min-width: 76px;  text-align: center; }
        .pmo-table th.pmo-c-act,    .pmo-table td.pmo-c-act    { width: 1%; min-width: 96px;  text-align: center; }

        .pmo-check { width: 15px; height: 15px; cursor: pointer; accent-color: #1890ff; margin: 0; }
        .pmo-name { display: block; font-weight: 500; color: #262626; overflow: hidden; text-overflow: ellipsis; }
        .pmo-sub { display: block; margin-top: 3px; font-size: 12px; color: #8c8c8c; overflow: hidden; text-overflow: ellipsis; }
        .pmo-off { font-weight: 700; color: #cf1322; font-variant-numeric: tabular-nums; }
        .pmo-scope-total { display: block; font-weight: 500; color: #262626; }
        .pmo-time { display: block; color: #262626; }

        .pmo-c-name[data-edit] { cursor: pointer; }
        .pmo-c-name[data-edit]:hover .pmo-name { color: #1890ff; text-decoration: underline; }

        /* Bốn trạng thái — màu phải khác nhau rõ, vì đây là thứ người bán liếc qua là
           phải biết đợt đang chạy hay chưa. */
        .pmo-badge {
            display: inline-block; padding: 3px 10px; border-radius: 9999px;
            font-size: 12px; font-weight: 500; white-space: nowrap;
        }
        .pmo-badge-running { background: #f6ffed; color: #389e0d; border: 1px solid #b7eb8f; }
        .pmo-badge-scheduled { background: #e6f7ff; color: #1890ff; border: 1px solid #91d5ff; }
        .pmo-badge-ended { background: #fafafa; color: #8c8c8c; border: 1px solid #e8e8e8; }
        .pmo-badge-paused { background: #fff7e6; color: #d46b08; border: 1px solid #ffd591; }

        /* Công tắc — cùng cấu trúc & màu với trang Sản phẩm / Nhà cung cấp */
        .pmo-switch {
            position: relative; display: inline-flex; align-items: center; height: 22px; width: 42px; border: 0;
            border-radius: 9999px; background: #ddd; cursor: pointer; padding: 0; transition: background .15s;
        }
        .pmo-switch.on { background: #7083b6; }
        .pmo-switch-knob {
            display: inline-block; height: 16px; width: 16px; border-radius: 50%; background: #fff;
            box-shadow: 0 0 3px rgba(0,0,0,.3); transform: translateX(3px); transition: transform .15s;
        }
        .pmo-switch.on .pmo-switch-knob { transform: translateX(23px); }

        .pmo-rowacts { display: inline-flex; align-items: center; gap: 6px; }
        .pmo-rowbtn {
            width: 30px; height: 30px; border: 0; background: none; border-radius: 4px; padding: 0;
            cursor: pointer; display: inline-flex; align-items: center; justify-content: center;
            transition: background .15s, color .15s;
        }
        .pmo-rowbtn.pmo-edit { color: #1890ff; }
        .pmo-rowbtn.pmo-edit:hover { background: #e6f7ff; }
        .pmo-rowbtn.pmo-del { color: #ff4d4f; }
        .pmo-rowbtn.pmo-del:hover { background: #fff1f0; }

        .pmo-empty { padding: 48px 12px; text-align: center; color: #8c8c8c; white-space: normal; line-height: 1.7; }

        .pmo-btn-primary:focus-visible, .pmo-btn-ghost:focus-visible,
        .pmo-search-btn:focus-visible { outline: none; box-shadow: 0 0 0 .25rem rgba(13,110,253,.25); }

        /* Thanh thao tác hàng loạt (pill nổi — đồng bộ các trang khác) */
        .pmo-bulk {
            position: fixed; bottom: 24px; left: 230px; right: 0; margin-inline: auto; z-index: 1070;
            width: max-content; max-width: calc(100vw - 260px);
            display: flex; align-items: center; gap: 14px; border: 1px solid #e6e6e6; border-radius: 9999px;
            background: #fff; padding: 12px 20px; box-shadow: 0 6px 24px rgba(0,0,0,.15);
        }
        body:has(.jh-sidebar.collapsed) .pmo-bulk { left: 48px; }
        @media (max-width: 820px) { .pmo-bulk { left: 0; } }
        .pmo-bulk-text { font-size: 13px; font-weight: 500; color: #262626; }
        .pmo-bulk-text b { color: #1890ff; }
        .pmo-bulk-clear { border: 0; background: none; font-size: 13px; color: #8c8c8c; cursor: pointer; transition: color .15s; }
        .pmo-bulk-clear:hover { color: #262626; }
        .pmo-bulk-btn {
            display: inline-flex; align-items: center; gap: 6px; height: 30px; border: 1px solid #d9d9d9;
            border-radius: 9999px; background: #fff; padding: 0 14px; font-size: 13px; font-weight: 500;
            color: #595959; cursor: pointer; transition: border-color .15s, color .15s;
        }
        .pmo-bulk-btn:hover { border-color: #1890ff; color: #1890ff; }
        .pmo-bulk-del {
            display: inline-flex; align-items: center; gap: 6px; height: 30px; border: 0; border-radius: 9999px;
            background: #ff4d4f; padding: 0 16px; font-size: 13px; font-weight: 500; color: #fff;
            cursor: pointer; transition: background .15s;
        }
        .pmo-bulk-del:hover { background: #ff7875; }

        /* ---- Modal ---- */
        .pmo-overlay { position: fixed; inset: 0; z-index: 1080; display: flex; align-items: center; justify-content: center; padding: 16px; background: rgba(0,0,0,.4); }
        .pmo-dialog {
            max-height: 92vh; width: 100%; max-width: 860px; overflow-y: auto; border-radius: 6px;
            background: #fff; box-shadow: 0 10px 40px rgba(0,0,0,.2); scrollbar-width: thin;
        }
        .pmo-dialog::-webkit-scrollbar { width: 11px; }
        .pmo-dialog::-webkit-scrollbar-thumb { background-color: #dcdcdc; border-radius: 8px; border: 3px solid #fff; }
        .pmo-modal-head {
            position: sticky; top: 0; z-index: 2; display: flex; align-items: center; justify-content: space-between;
            border-bottom: 1px solid #f0f0f0; background: #fff; padding: 12px 20px;
        }
        .pmo-modal-title { margin: 0; font-size: 15px; font-weight: 700; color: #262626; }
        .pmo-modal-x { border: 0; background: none; padding: 0; color: #8c8c8c; cursor: pointer; display: inline-flex; transition: color .15s; }
        .pmo-modal-x:hover { color: #262626; }
        .pmo-modal-body { padding: 16px 20px; display: flex; flex-direction: column; gap: 16px; }
        .pmo-modal-foot {
            position: sticky; bottom: 0; display: flex; justify-content: center; gap: 8px;
            border-top: 1px solid #f0f0f0; background: #fff; padding: 12px 20px;
        }

        .pmo-grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px 16px; }
        .pmo-col-2 { grid-column: span 2; }
        .pmo-field-label { display: block; margin-bottom: 4px; font-size: 13px; font-weight: 500; color: #262626; }
        .pmo-req { color: #ff4d4f; }
        .pmo-unit { font-weight: 400; color: #8c8c8c; }
        .pmo-input, .pmo-msel {
            width: 100%; border: 1px solid #d9d9d9; border-radius: 4px; padding: 0 12px; font-size: 13px;
            outline: none; transition: border-color .15s; font-family: inherit; color: #262626; background: #fff;
        }
        .pmo-input { height: 36px; }
        .pmo-input::placeholder { color: #bfbfbf; }
        .pmo-input:focus, .pmo-msel:focus { border-color: #86b7fe; box-shadow: 0 0 0 .25rem rgba(13,110,253,.25); }
        .pmo-msel {
            height: 36px; cursor: pointer; padding-right: 32px; appearance: none; -webkit-appearance: none;
            background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23595959' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><path d='m6 9 6 6 6-6'/></svg>");
            background-repeat: no-repeat; background-position: right 10px center;
        }
        .pmo-hint { margin: 4px 0 0; font-size: 11px; color: #8c8c8c; line-height: 1.5; }

        /* ---- Phạm vi áp dụng ---- */
        .pmo-scope-wrap { border-top: 1px solid #f0f0f0; padding-top: 14px; }
        .pmo-scope-title { font-size: 13px; font-weight: 600; color: #262626; display: flex; align-items: baseline; gap: 8px; flex-wrap: wrap; }
        .pmo-scope-sum { font-size: 12px; font-weight: 400; color: #1890ff; }
        .pmo-scope-note { margin-bottom: 10px; }
        .pmo-scope-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; }
        .pmo-scope { border: 1px solid #e8e8e8; border-radius: 6px; overflow: hidden; display: flex; flex-direction: column; }
        .pmo-scope-head {
            display: flex; align-items: center; justify-content: space-between; gap: 8px;
            padding: 8px 10px; background: #fafafa; border-bottom: 1px solid #f0f0f0;
            font-size: 12px; font-weight: 600; color: #595959;
        }
        .pmo-scope-count {
            min-width: 22px; padding: 1px 7px; border-radius: 9999px; background: #f0f0f0;
            color: #8c8c8c; font-size: 11px; text-align: center;
        }
        .pmo-scope-count.on { background: #e6f7ff; color: #1890ff; }
        .pmo-scope-search {
            width: 100%; height: 32px; border: 0; border-bottom: 1px solid #f0f0f0; padding: 0 10px;
            font-size: 12px; outline: none; font-family: inherit; color: #262626;
        }
        .pmo-scope-search::placeholder { color: #bfbfbf; }
        .pmo-scope-search:focus { background: #f8fbff; }
        .pmo-scope-list { max-height: 190px; overflow-y: auto; padding: 4px; scrollbar-width: thin; }
        .pmo-scope-list::-webkit-scrollbar { width: 9px; }
        .pmo-scope-list::-webkit-scrollbar-thumb { background-color: #dcdcdc; border-radius: 8px; border: 3px solid #fff; }
        .pmo-pick {
            display: flex; align-items: flex-start; gap: 8px; padding: 6px 8px; border-radius: 4px;
            font-size: 12.5px; color: #262626; cursor: pointer; transition: background .15s;
        }
        .pmo-pick:hover { background: #f5f9ff; }
        .pmo-pick input { margin-top: 2px; flex-shrink: 0; }
        .pmo-pick-name { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; }
        .pmo-pick-sub { display: block; margin-top: 2px; font-size: 11px; color: #8c8c8c; }
        .pmo-scope-empty, .pmo-scope-more { margin: 0; padding: 12px 10px; font-size: 12px; color: #bfbfbf; text-align: center; }
        .pmo-scope-more { text-align: left; border-top: 1px solid #f5f5f5; }

        @media (max-width: 760px) {
            .pmo-grid2 { grid-template-columns: 1fr; }
            .pmo-col-2 { grid-column: span 1; }
            .pmo-scope-grid { grid-template-columns: 1fr; }
        }
    </style>

    <script>
        (function () {
            const CSRF = @json(csrf_token());
            const URL_BASE = @json(url('admin/khuyen-mai'));
            const URL_STORE = @json(route('admin.promotions.store'));
            const URL_BULK_DEL = @json(route('admin.promotions.bulkDestroy'));
            const RETURN_URL = @json(request()->getRequestUri());
            const PROMOS = @json($promotions);
            const BY_ID = new Map(PROMOS.map((p) => [p.id, p]));

            const $filter = document.getElementById('pmoFilter');
            const $bulkMount = document.getElementById('pmoBulkMount');

            const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (m) => (
                { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]
            ));

            // ---------- Bộ lọc: đổi select -> chạy ngay; gõ tìm kiếm -> chờ 400ms ----------
            $filter.querySelectorAll('select').forEach((sel) => {
                sel.addEventListener('change', () => $filter.submit());
            });
            const search = $filter.querySelector('input[name="keyword"]');
            let searchTimer = null;
            search.addEventListener('input', () => {
                clearTimeout(searchTimer);
                searchTimer = setTimeout(() => $filter.submit(), 400);
            });

            // ---------- Form POST động cho mọi thao tác ghi (kèm CSRF) ----------
            function postForm(action, method, fields) {
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
                add('return', RETURN_URL);
                for (const [k, v] of Object.entries(fields)) add(k, v);
                document.body.appendChild(f);
                f.submit();
            }

            // Toast lỗi phía client (dùng chung hạ tầng toast của layout).
            function toastErr(msg) {
                const cont = document.querySelector('.toast-container');
                if (!cont || typeof bootstrap === 'undefined') { alert(msg); return; }
                const el = document.createElement('div');
                el.className = 'toast align-items-center text-bg-danger border-0';
                el.setAttribute('role', 'alert');
                el.innerHTML = `<div class="d-flex"><div class="toast-body"><i class="bi bi-exclamation-circle-fill me-2"></i>${esc(msg)}</div>`
                    + '<button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>';
                cont.appendChild(el);
                const t = new bootstrap.Toast(el, { delay: 7000 });
                el.addEventListener('hidden.bs.toast', () => el.remove());
                t.show();
            }

            function toggleStatus(btn) {
                const turningOn = btn.dataset.on !== '1';
                // Đợt đã hết hạn thì bật lên cũng không giảm cho ai — nói trước, đừng
                // để người bán bật xong ngồi đợi giá đổi.
                if (turningOn && btn.dataset.state === 'ended') {
                    toastErr('Chương trình này đã qua ngày kết thúc nên bật lên cũng chưa chạy. '
                        + 'Hãy mở phần sửa và dời ngày kết thúc sang mốc trong tương lai.');
                    return;
                }
                postForm(`${URL_BASE}/${btn.getAttribute('data-toggle')}/toggle-status`, 'PUT', {
                    is_active: turningOn ? 1 : 0,
                });
            }

            function removePromo(p) {
                sysDelete({
                    title: 'Xác nhận xoá chương trình',
                    message: `Bạn có chắc chắn muốn xoá "${p.name}"? Giá của ${Number(p.product_count) || 0} sản phẩm `
                        + 'sẽ trở về như cũ ngay lập tức. Hành động này không thể hoàn tác.',
                    highlightText: p.name || '',
                }).then((ok) => { if (ok) postForm(`${URL_BASE}/${p.id}`, 'DELETE', {}); });
            }

            // ---------- Ô nhập tiền: hiện dấu chấm ngăn nghìn kiểu Việt Nam ----------
            const digits = (s) => String(s == null ? '' : s).replace(/\D/g, '');
            const groupVN = (s) => digits(s).replace(/\B(?=(\d{3})+(?!\d))/g, '.');

            function bindMoney(el) {
                el.addEventListener('input', () => { el.value = groupVN(el.value); });
            }

            // ---------- Modal tạo / sửa ----------
            const $overlay = document.getElementById('pmoFormOverlay');
            const form = document.getElementById('pmoForm');
            const $type = document.getElementById('pmoType');
            const $value = document.getElementById('pmoValue');
            const $max = document.getElementById('pmoMax');
            const $maxWrap = document.getElementById('pmoMaxWrap');
            const $valueUnit = document.getElementById('pmoValueUnit');
            const $valueHint = document.getElementById('pmoValueHint');
            const $scopeSum = document.getElementById('pmoScopeSum');

            bindMoney($max);

            const closeOverlay = () => { $overlay.style.display = 'none'; };
            $overlay.querySelectorAll('[data-close]').forEach((b) => b.addEventListener('click', closeOverlay));
            document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeOverlay(); });

            // Kiểu giảm quyết định đơn vị của ô "Mức giảm" và sự tồn tại của ô "Trần giảm".
            function syncType() {
                const percent = $type.value === 'percentage';
                $maxWrap.style.display = percent ? '' : 'none';
                $valueUnit.textContent = percent ? '(%)' : '(₫)';
                $value.placeholder = percent ? '20' : '50.000';
                $valueHint.textContent = percent
                    ? 'Giảm bao nhiêu phần trăm trên giá đang bán của mỗi sản phẩm.'
                    : 'Giảm thẳng bao nhiêu tiền trên mỗi sản phẩm.';
                // Đổi kiểu thì định dạng số cũng phải đổi theo, không thì "20" thành "20.000".
                $value.value = percent ? digits($value.value) : groupVN($value.value);
            }
            $type.addEventListener('change', syncType);
            $value.addEventListener('input', () => {
                $value.value = $type.value === 'percentage' ? digits($value.value) : groupVN($value.value);
            });

            // ---------- Phạm vi áp dụng ----------
            const boxes = () => Array.from(form.querySelectorAll('.pmo-scope-list input[type="checkbox"]'));

            function syncScope() {
                const counts = { category: 0, product: 0 };
                boxes().forEach((cb) => { if (cb.checked) counts[cb.dataset.kind]++; });

                form.querySelectorAll('[data-count]').forEach((el) => {
                    const n = counts[el.getAttribute('data-count')] || 0;
                    el.textContent = n;
                    el.classList.toggle('on', n > 0);
                });

                const parts = [];
                if (counts.category) parts.push(`${counts.category} danh mục`);
                if (counts.product) parts.push(`${counts.product} sản phẩm lẻ`);
                $scopeSum.textContent = parts.length ? 'Đã chọn ' + parts.join(' · ') : 'Chưa chọn gì';
            }

            form.querySelectorAll('.pmo-scope-list').forEach((list) => {
                list.addEventListener('change', syncScope);
            });

            // Ô tìm trong từng cột phạm vi — lọc tại chỗ, không gọi lại API.
            form.querySelectorAll('[data-search]').forEach((input) => {
                input.addEventListener('input', () => {
                    const kw = input.value.trim().toLowerCase();
                    const list = form.querySelector(`[data-list="${input.getAttribute('data-search')}"]`);
                    list.querySelectorAll('.pmo-pick').forEach((row) => {
                        row.style.display = !kw || (row.dataset.text || '').includes(kw) ? '' : 'none';
                    });
                });
            });

            function openForm(mode, p) {
                const isEdit = mode === 'edit';
                form.action = isEdit ? `${URL_BASE}/${p.id}` : URL_STORE;
                document.getElementById('pmoFormMethod').value = isEdit ? 'PUT' : 'POST';
                document.getElementById('pmoFormTitle').textContent = isEdit ? 'Sửa chương trình khuyến mãi' : 'Tạo chương trình khuyến mãi';
                document.getElementById('pmoFormSubmit').textContent = isEdit ? 'Cập nhật' : 'Lưu';

                document.getElementById('pmoName').value = isEdit ? (p.name || '') : '';
                document.getElementById('pmoDesc').value = isEdit ? (p.description || '') : '';
                $type.value = isEdit ? (p.discount_type || 'percentage') : 'percentage';
                document.getElementById('pmoActive').value = isEdit ? (p.is_active ? '1' : '0') : '1';

                // Mặc định cho đợt mới: bắt đầu từ đầu giờ hôm nay, kết thúc sau 7 ngày —
                // gõ tay hai mốc ngày giờ là việc nhàm nhất trong biểu mẫu này.
                const pad = (n) => String(n).padStart(2, '0');
                const fmt = (d) => `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
                const now = new Date();
                const week = new Date(now.getTime() + 7 * 86400000);
                document.getElementById('pmoStart').value = isEdit ? (p.start_at || '') : fmt(new Date(now.getFullYear(), now.getMonth(), now.getDate(), 0, 0));
                document.getElementById('pmoEnd').value = isEdit ? (p.end_at || '') : fmt(new Date(week.getFullYear(), week.getMonth(), week.getDate(), 23, 59));

                const rawValue = isEdit ? Math.round(Number(p.discount_value) || 0) : '';
                $value.value = rawValue === '' ? '' : String(rawValue);
                $max.value = isEdit && p.max_discount_amount ? String(Math.round(Number(p.max_discount_amount))) : '';

                const picked = {
                    category: new Set((isEdit ? p.category_ids : []) || []),
                    product: new Set((isEdit ? p.product_ids : []) || []),
                };
                boxes().forEach((cb) => { cb.checked = picked[cb.dataset.kind].has(Number(cb.value)); });

                const shops = new Set((isEdit ? p.shop_ids : []) || []);
                oShop().forEach((cb) => { cb.checked = shops.has(Number(cb.value)); });
                syncShop();

                // Dọn ô tìm kiếm của lần mở trước, không thì danh sách vẫn đang bị lọc.
                form.querySelectorAll('[data-search]').forEach((input) => {
                    input.value = '';
                    input.dispatchEvent(new Event('input'));
                });

                syncType();
                syncScope();
                $overlay.style.display = 'flex';
                setTimeout(() => document.getElementById('pmoName').focus(), 30);
            }

            // Ô chi nhánh: rỗng thì nói thẳng "Mọi chi nhánh", đừng để "Chưa chọn gì"
            // — hai câu ấy nghe giống nhau nhưng ở đây nghĩa ngược nhau hoàn toàn.
            const oShop = () => Array.from(form.querySelectorAll('.pmo-shop'));

            function syncShop() {
                const n = oShop().filter((cb) => cb.checked).length;
                const $sum = document.getElementById('pmoShopSum');
                if ($sum) {
                    $sum.textContent = n === 0 ? 'Mọi chi nhánh' : n + ' chi nhánh';
                }
            }

            form.addEventListener('change', (e) => {
                if (e.target.classList && e.target.classList.contains('pmo-shop')) {
                    syncShop();
                }
            });

            document.getElementById('pmoAddBtn').addEventListener('click', () => openForm('add', null));

            // Chặn ngay tại biểu mẫu: gửi lên rồi mới báo "chưa chọn phạm vi" thì người
            // dùng mất cả trang vừa điền.
            form.addEventListener('submit', (e) => {
                if (!boxes().some((cb) => cb.checked)) {
                    e.preventDefault();
                    toastErr('Chưa chọn phạm vi áp dụng. Hãy tick ít nhất một danh mục hoặc sản phẩm — '
                        + 'chương trình không có phạm vi thì không giảm giá cho ai cả.');
                    return;
                }
                if ($type.value === 'percentage' && Number(digits($value.value)) > 100) {
                    e.preventDefault();
                    toastErr('Phần trăm giảm phải trong khoảng 1–100.');
                    return;
                }
                // Bỏ dấu chấm ngăn nghìn trước khi gửi: server nhận số thuần.
                $value.value = digits($value.value);
                $max.value = digits($max.value);
            });

            // ---------- Sự kiện bảng ----------
            const tbody = document.querySelector('.pmo-table tbody');
            tbody.addEventListener('click', (e) => {
                if (e.target.closest('a')) return;

                const tg = e.target.closest('[data-toggle]');
                if (tg) { toggleStatus(tg); return; }
                const rm = e.target.closest('[data-remove]');
                if (rm) { const p = BY_ID.get(Number(rm.getAttribute('data-remove'))); if (p) removePromo(p); return; }
                const ed = e.target.closest('[data-edit]');
                if (ed) { const p = BY_ID.get(Number(ed.getAttribute('data-edit'))); if (p) openForm('edit', p); return; }
            });

            // ---------- Chọn dòng + thanh thao tác hàng loạt ----------
            const selected = new Set();
            const checkAll = document.getElementById('pmoCheckAll');
            const rowChecks = () => Array.from(document.querySelectorAll('.pmo-row-check'));

            function syncRow(cb) {
                const tr = cb.closest('tr');
                if (cb.checked) { selected.add(Number(cb.value)); tr.classList.add('is-selected'); }
                else { selected.delete(Number(cb.value)); tr.classList.remove('is-selected'); }
            }
            function syncHeader() {
                const all = rowChecks();
                const on = all.filter((c) => c.checked).length;
                checkAll.checked = on > 0 && on === all.length;
                checkAll.indeterminate = on > 0 && on < all.length;
            }
            function renderBulk() {
                const n = selected.size;
                if (n === 0) { $bulkMount.innerHTML = ''; return; }

                const ids = [...selected];
                const running = ids.filter((id) => BY_ID.get(id)?.status === 'running').length;

                $bulkMount.innerHTML = `
                    <div class="pmo-bulk">
                        <span class="pmo-bulk-text">Đã chọn <b>${n}</b> chương trình${running ? ` · ${running} đang chạy` : ''}</span>
                        <button type="button" class="pmo-bulk-clear" id="pmoBulkClear">Bỏ chọn</button>
                        <button type="button" class="pmo-bulk-del" id="pmoBulkDel">
                            <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/></svg>
                            Xoá (${n})
                        </button>
                    </div>`;
            }

            rowChecks().forEach((cb) => cb.addEventListener('change', () => { syncRow(cb); syncHeader(); renderBulk(); }));
            if (checkAll) {
                checkAll.addEventListener('change', () => {
                    rowChecks().forEach((cb) => { cb.checked = checkAll.checked; syncRow(cb); });
                    syncHeader(); renderBulk();
                });
            }

            function idFields(ids) {
                const out = {};
                ids.forEach((id, i) => { out[`ids[${i}]`] = id; });
                return out;
            }

            $bulkMount.addEventListener('click', (e) => {
                if (e.target.closest('#pmoBulkClear')) {
                    selected.clear();
                    rowChecks().forEach((cb) => { cb.checked = false; cb.closest('tr').classList.remove('is-selected'); });
                    syncHeader(); renderBulk();
                    return;
                }

                if (e.target.closest('#pmoBulkDel')) {
                    const ids = [...selected];
                    const running = ids.filter((id) => BY_ID.get(id)?.status === 'running').length;
                    sysDelete({
                        title: 'Xác nhận xoá hàng loạt',
                        message: `Bạn có chắc chắn muốn xoá ${ids.length} chương trình đã chọn?`
                            + (running ? ` ${running} chương trình đang chạy — giá của hàng trong các đợt đó sẽ về như cũ ngay lập tức.` : '')
                            + ' Hành động này không thể hoàn tác.',
                        highlightText: `Số lượng: ${ids.length} chương trình`,
                    }).then((ok) => { if (ok) postForm(URL_BULK_DEL, 'POST', idFields(ids)); });
                }
            });

            // ---------- Dropdown Tiện ích ----------
            const util = document.getElementById('pmoUtil');
            document.getElementById('pmoUtilBtn').addEventListener('click', (e) => {
                e.stopPropagation();
                const open = !util.classList.contains('open');
                util.classList.toggle('open', open);
                e.currentTarget.setAttribute('aria-expanded', open ? 'true' : 'false');
            });
            document.addEventListener('click', () => {
                util.classList.remove('open');
                document.getElementById('pmoUtilBtn').setAttribute('aria-expanded', 'false');
            });
        })();
    </script>
@endsection
