{{--
    CSS dùng chung của NHÓM trang Báo cáo (tiền tố .rp-).

    Cùng ngôn ngữ thị giác với trang Tổng quan và các trang danh sách: nền #f5f6fa,
    thẻ trắng viền #f0f0f0, chữ #262626/#8c8c8c, xanh #1890ff. Bốn trang báo cáo
    dùng CHUNG đúng khối này — trang nào cần hình mới thì thêm vào đây, không tự
    khai style riêng trong view, nếu không bốn trang cùng nhóm sẽ dần khác nhau.

    @once để 4 trang include mà CSS chỉ nhúng một lần.
--}}
@once
    <style>
        .rp {
            /* Bù padding p-4 của <main> để nền trải hết bề ngang màn hình. */
            margin: -1.5rem;
            min-height: calc(100vh - 56px);
            padding: 20px 24px 32px;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            color: #262626;
            background: #f5f6fa;
            display: flex; flex-direction: column; gap: 16px;
        }

        /* ----- Đầu trang ----- */
        .rp-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; flex-wrap: wrap; }
        .rp-head-main { min-width: 0; }
        .rp-title { margin: 0; font-size: 21px; font-weight: 700; letter-spacing: -.01em; }
        .rp-sub { margin: 4px 0 0; font-size: 13px; color: #8c8c8c; }

        /* ----- Hàng bộ lọc ----- */
        .rp-filters {
            display: flex; align-items: center; gap: 10px; flex-wrap: wrap;
            padding: 12px 14px; background: #fff; border: 1px solid #f0f0f0; border-radius: 10px;
            box-shadow: 0 1px 2px rgba(0, 0, 0, .03);
        }
        .rp-filter-label { font-size: 12.5px; color: #8c8c8c; }
        .rp-filters-right { margin-left: auto; display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .rp-seg { display: inline-flex; border: 1px solid #d9d9d9; border-radius: 4px; background: #fff; overflow: hidden; }
        .rp-seg-btn {
            padding: 0 12px; height: 34px; display: inline-flex; align-items: center;
            font-size: 13px; color: #595959; text-decoration: none;
            border-right: 1px solid #f0f0f0; transition: background .15s, color .15s;
        }
        .rp-seg-btn:last-child { border-right: 0; }
        .rp-seg-btn:hover { background: #f5f7fa; color: #1890ff; }
        .rp-seg-btn.is-active { background: #1890ff; color: #fff; }
        .rp-select {
            height: 34px; border: 1px solid #d9d9d9; border-radius: 4px; background-color: #fff;
            padding: 0 30px 0 12px; font-size: 13px; color: #262626; cursor: pointer; outline: none;
            appearance: none; -webkit-appearance: none;
            background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%23595959' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><path d='m6 9 6 6 6-6'/></svg>");
            background-repeat: no-repeat; background-position: right 8px center;
        }
        .rp-ghostbtn {
            display: inline-flex; align-items: center; gap: 6px; height: 34px; padding: 0 12px;
            border: 1px solid #d9d9d9; border-radius: 4px; background: #fff;
            font-size: 13px; color: #595959; text-decoration: none; cursor: pointer;
            transition: border-color .15s, color .15s;
        }
        .rp-ghostbtn:hover { border-color: #1890ff; color: #1890ff; }

        .rp-alert {
            display: flex; align-items: center; gap: 8px; margin: 0; padding: 10px 14px;
            background: #fff2f0; border: 1px solid #ffccc7; border-radius: 8px; font-size: 13px; color: #cf1322;
        }
        .rp-alert svg { flex-shrink: 0; }
        .rp-note {
            display: flex; align-items: flex-start; gap: 8px; margin: 0; padding: 9px 13px;
            background: #fffbe6; border: 1px solid #ffe58f; border-radius: 8px; font-size: 12.5px; color: #874d00;
        }
        .rp-note svg { flex-shrink: 0; margin-top: 1px; }

        /* ----- Thẻ chỉ số ----- */
        .rp-kpis { display: grid; grid-template-columns: repeat(6, 1fr); gap: 14px; }
        .rp-kpis--4 { grid-template-columns: repeat(4, 1fr); }
        .rp-kpi {
            --kpi: #1890ff; --kpi-bg: #ddeeff; --kpi-soft: #f0f7ff;
            position: relative; overflow: hidden;
            display: flex; flex-direction: column; gap: 7px; padding: 15px 16px 14px 19px; min-width: 0;
            border: 1px solid #f0f0f0; border-radius: 10px;
            background: linear-gradient(118deg, var(--kpi-bg) 0%, #fff 66%);
            box-shadow: 0 1px 2px rgba(0, 0, 0, .03);
        }
        .rp-kpi::before { content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 4px; background: var(--kpi); }
        .rp-kpi--blue   { --kpi: #1890ff; --kpi-bg: #ddeeff; --kpi-soft: #f0f7ff; }
        .rp-kpi--green  { --kpi: #52c41a; --kpi-bg: #e3f8d6; --kpi-soft: #f4fded; }
        .rp-kpi--violet { --kpi: #722ed1; --kpi-bg: #ede1ff; --kpi-soft: #f7f1ff; }
        .rp-kpi--amber  { --kpi: #fa8c16; --kpi-bg: #ffedd2; --kpi-soft: #fff7e9; }
        .rp-kpi--red    { --kpi: #cf1322; --kpi-bg: #ffe2de; --kpi-soft: #fff2f0; }
        .rp-kpi--teal   { --kpi: #13c2c2; --kpi-bg: #d6f7f5; --kpi-soft: #edfbfb; }
        .rp-kpi--grey   { --kpi: #8c8c8c; --kpi-bg: #eeeeee; --kpi-soft: #f7f7f7; }

        .rp-kpi-top { display: flex; align-items: flex-start; justify-content: space-between; gap: 8px; }
        .rp-kpi-label { font-size: 11.5px; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; color: #8c8c8c; }
        .rp-kpi-icon {
            flex-shrink: 0; width: 28px; height: 28px; display: inline-flex; align-items: center; justify-content: center;
            border-radius: 8px; background: var(--kpi-soft); color: var(--kpi); border: 1px solid #fff;
        }
        .rp-kpi-main { display: flex; align-items: center; justify-content: space-between; gap: 10px; min-width: 0; }
        /* Con số giữ màu chữ, KHÔNG mượn màu thẻ — màu thẻ chỉ để tách khối cho dễ quét mắt. */
        .rp-kpi-value { font-size: 23px; font-weight: 700; line-height: 1.15; color: #262626; letter-spacing: -.02em; }
        .rp-kpi-foot { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; min-height: 18px; }
        .rp-kpi-note { font-size: 12px; color: #8c8c8c; }
        .rp-delta { display: inline-flex; align-items: center; gap: 3px; font-size: 12.5px; font-weight: 600; }
        .rp-delta.is-up { color: #389e0d; }
        .rp-delta.is-down { color: #cf1322; }
        .rp-delta.is-new { color: #8c8c8c; font-weight: 400; }
        /* Chỉ số mà TĂNG là xấu (đơn huỷ, tiền chưa thu): đảo màu, không đảo mũi tên —
           mũi tên nói hướng đi của con số, màu nói tốt hay xấu. */
        .rp-delta.is-bad-up { color: #cf1322; }
        .rp-delta.is-bad-down { color: #389e0d; }
        .rp-spark { width: 74px; height: 28px; flex-shrink: 0; opacity: .9; }
        .rp-spark-area { fill: var(--kpi); opacity: .14; }
        .rp-spark-line { fill: none; stroke: var(--kpi); stroke-width: 1.8; stroke-linejoin: round; stroke-linecap: round; vector-effect: non-scaling-stroke; }
        .rp-kpi-rows {
            list-style: none; margin: 11px 0 0; padding: 10px 0 0; border-top: 1px solid rgba(0, 0, 0, .06);
            display: flex; flex-direction: column; gap: 6px;
        }
        .rp-kpi-rows li { display: flex; align-items: baseline; justify-content: space-between; gap: 10px; font-size: 12.5px; }
        .rp-kpi-rows span { color: #8c8c8c; min-width: 0; }
        .rp-kpi-rows b { font-weight: 600; color: #262626; white-space: nowrap; font-variant-numeric: tabular-nums; }

        /* ----- Lưới thẻ ----- */
        .rp-grid { display: grid; gap: 16px; align-items: stretch; }
        .rp-grid--2-1 { grid-template-columns: 2fr 1fr; }
        .rp-grid--1-1 { grid-template-columns: 1fr 1fr; }
        .rp-grid--3 { grid-template-columns: repeat(3, 1fr); }
        .rp-card {
            display: flex; flex-direction: column;
            background: #fff; border: 1px solid #f0f0f0; border-radius: 10px; padding: 16px 18px; min-width: 0;
            box-shadow: 0 1px 2px rgba(0, 0, 0, .03);
        }
        .rp-card-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; margin-bottom: 14px; }
        .rp-card-title { margin: 0; font-size: 14.5px; font-weight: 700; }
        .rp-card-sub { margin: 3px 0 0; font-size: 12px; color: #8c8c8c; }
        .rp-card-tools { display: flex; align-items: center; gap: 12px; flex-shrink: 0; }
        .rp-linkbtn {
            border: 0; background: none; padding: 0; font-size: 12.5px; font-weight: 600; color: #1890ff;
            cursor: pointer; text-decoration: none; white-space: nowrap;
        }
        .rp-linkbtn:hover { text-decoration: underline; }
        /* Ô trống chiếm hết phần thân còn lại và nằm giữa thẻ, để thẻ không có dữ
           liệu trông có chủ đích chứ không như bị cắt cụt. */
        .rp-empty {
            flex: 1; margin: 0; padding: 26px 0; min-height: 90px;
            display: flex; align-items: center; justify-content: center;
            text-align: center; font-size: 13px; color: #bfbfbf;
        }
        .rp-foot-note { margin: 10px 0 0; font-size: 11.5px; color: #bfbfbf; }

        /* ----- Biểu đồ đường ----- */
        .rp-tabsmini { display: inline-flex; padding: 2px; background: #f5f6fa; border-radius: 7px; }
        .rp-tabmini {
            border: 0; background: none; padding: 5px 12px; border-radius: 5px;
            font-size: 12.5px; font-weight: 600; color: #8c8c8c; cursor: pointer; transition: background .15s, color .15s;
        }
        .rp-tabmini:hover { color: #262626; }
        .rp-tabmini.is-active { background: #fff; color: #1890ff; box-shadow: 0 1px 2px rgba(0, 0, 0, .08); }

        .rp-plot { position: relative; }
        .rp-plot[hidden] { display: none; }
        /* KHÔNG đặt max-height cho SVG có viewBox: chạm trần thì trình duyệt thu nhỏ
           cả khung vẽ rồi canh giữa, hình co lại và hở hai bên thẻ. */
        .rp-svg { display: block; width: 100%; height: auto; }
        .rp-gridline { stroke: #f0f0f0; stroke-width: 1; }
        .rp-avgline { stroke: #bfbfbf; stroke-width: 1; stroke-dasharray: 4 4; vector-effect: non-scaling-stroke; }
        .rp-line { fill: none; stroke: #1890ff; stroke-width: 2; stroke-linejoin: round; stroke-linecap: round; vector-effect: non-scaling-stroke; }
        .rp-area { fill: #1890ff; opacity: .08; }
        .rp-dot-end { fill: #1890ff; stroke: #fff; stroke-width: 2; }
        .rp-cross { stroke: #bfbfbf; stroke-width: 1; vector-effect: non-scaling-stroke; }
        .rp-dot-hover { fill: #1890ff; stroke: #fff; stroke-width: 2; }
        .rp-tick { fill: #8c8c8c; font-size: 11px; font-family: inherit; font-variant-numeric: tabular-nums; }
        .rp-tick--y { text-anchor: end; }
        .rp-tick--avg { fill: #bfbfbf; font-size: 10px; }
        .rp-hit { cursor: crosshair; }
        .rp-tip {
            position: absolute; z-index: 5; min-width: 150px; padding: 8px 10px; pointer-events: none;
            background: #262626; color: #fff; border-radius: 6px; font-size: 12px; line-height: 1.55;
            box-shadow: 0 6px 20px rgba(0, 0, 0, .18); transform: translate(-50%, -100%);
        }
        .rp-tip[hidden] { display: none; }
        .rp-tip b { display: block; font-size: 13px; margin-bottom: 2px; }
        .rp-tip span { color: #d9d9d9; }

        /* ----- Cột dọc (khung giờ, thứ trong tuần, khách mới/cũ) ----- */
        .rp-cols { display: block; width: 100%; height: auto; margin-block: auto; }
        .rp-col { fill: #69b1ff; }
        .rp-col.is-peak { fill: #1890ff; }
        .rp-col--new { fill: #1890ff; }
        .rp-col--back { fill: #b7eb8f; }

        /* ----- Cột ngang ----- */
        .rp-bars { flex: 1; list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; justify-content: center; gap: 11px; }
        .rp-bar-row { display: grid; grid-template-columns: minmax(0, 132px) 1fr 46px 66px; align-items: center; gap: 10px; }
        .rp-bar-row--slim { grid-template-columns: minmax(0, 132px) 1fr 46px; }
        .rp-bars-split { height: 1px; background: #f0f0f0; margin: 2px 0; }
        .rp-bar-label { display: flex; align-items: center; gap: 7px; font-size: 13px; color: #595959; text-decoration: none; min-width: 0; }
        a.rp-bar-label:hover { color: #1890ff; }
        .rp-bar-name { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .rp-dot { width: 7px; height: 7px; border-radius: 50%; flex-shrink: 0; background: #bfbfbf; }
        .rp-dot.tone-wait { background: #fa8c16; }
        .rp-dot.tone-info { background: #1890ff; }
        .rp-dot.tone-move { background: #722ed1; }
        .rp-dot.tone-done { background: #52c41a; }
        .rp-dot.tone-stop { background: #cf1322; }
        .rp-bar-track { height: 8px; border-radius: 9999px; background: #f0f2f5; overflow: hidden; }
        .rp-bar-fill { display: block; height: 100%; border-radius: 9999px; background: #1890ff; }
        .rp-bar-fill.is-dead { background: #d9d9d9; }
        .rp-bar-value { font-size: 13px; font-weight: 600; text-align: right; font-variant-numeric: tabular-nums; }
        .rp-bar-extra { font-size: 12px; color: #8c8c8c; text-align: right; font-variant-numeric: tabular-nums; }

        /* ----- Vòng tròn cơ cấu ----- */
        .rp-donut-wrap { flex: 1; display: flex; align-items: center; justify-content: center; gap: 20px; flex-wrap: wrap; }
        .rp-donut { width: 140px; height: 140px; flex-shrink: 0; }
        .rp-donut-num { font-size: 20px; font-weight: 700; fill: #262626; font-family: inherit; }
        .rp-donut-cap { font-size: 11px; fill: #8c8c8c; font-family: inherit; }
        .rp-legend { list-style: none; margin: 0; padding: 0; flex: 1; min-width: 180px; display: flex; flex-direction: column; gap: 9px; }
        .rp-legend-item { display: grid; grid-template-columns: 10px 1fr auto auto; align-items: center; gap: 8px; font-size: 13px; }
        .rp-legend-swatch { width: 10px; height: 10px; border-radius: 3px; }
        .rp-legend-name { color: #595959; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .rp-legend-val { font-weight: 600; font-variant-numeric: tabular-nums; }
        .rp-legend-pct { min-width: 46px; text-align: right; font-size: 12px; color: #8c8c8c; font-variant-numeric: tabular-nums; }

        /* ----- Bảng -----
           th và td của CÙNG một cột luôn khai CÙNG text-align (class .num đặt trên
           cả hai), đúng quy ước bảng dữ liệu của khu quản trị. */
        .rp-table-wrap { flex: 1; width: 100%; overflow-x: auto; scrollbar-width: thin; }
        .rp-table-wrap--tall { max-height: 420px; overflow-y: auto; }
        .rp-table-wrap[hidden] { display: none; }
        .rp-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .rp-table th, .rp-table td { padding: 11px 14px; text-align: left; border-bottom: 1px solid #f5f5f5; white-space: nowrap; }
        .rp-table th {
            position: sticky; top: 0; background: #fff; z-index: 1; padding: 10px 14px;
            font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em;
            color: #8c8c8c; border-bottom-color: #f0f0f0;
        }
        .rp-table tbody tr:hover td { background: #fafcff; }
        .rp-table tbody tr:last-child td { border-bottom: 0; }
        .rp-table tfoot td { border-top: 1px solid #f0f0f0; border-bottom: 0; font-weight: 700; background: #fafafa; }
        .rp-table .num, .rp-table th.num { text-align: right; font-variant-numeric: tabular-nums; }
        .rp-muted { color: #8c8c8c; }
        .rp-pos { color: #389e0d; font-weight: 600; }
        .rp-neg { color: #cf1322; font-weight: 600; }

        /* Ô sản phẩm trong bảng xếp hạng: ảnh + tên + dòng phụ */
        .rp-prod { display: flex; align-items: center; gap: 10px; min-width: 0; }
        .rp-prod-img {
            width: 38px; height: 38px; flex-shrink: 0; border-radius: 6px; object-fit: cover;
            background: #f5f5f5; border: 1px solid #f0f0f0;
        }
        .rp-prod-main { min-width: 0; display: flex; flex-direction: column; gap: 2px; }
        .rp-prod-name { font-weight: 500; max-width: 280px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .rp-prod-sub { font-size: 11.5px; color: #8c8c8c; max-width: 280px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .rp-rank {
            width: 22px; height: 22px; display: inline-flex; align-items: center; justify-content: center;
            border-radius: 6px; background: #f0f2f5; font-size: 11.5px; font-weight: 700; color: #8c8c8c;
        }
        .rp-rank.is-first { background: #e6f7ff; color: #096dd9; }

        .rp-avatar {
            width: 34px; height: 34px; flex-shrink: 0; display: inline-flex; align-items: center; justify-content: center;
            border-radius: 50%; background: #f0f7ff; color: #096dd9; font-size: 14px; font-weight: 700; text-transform: uppercase;
        }
        .rp-badge {
            display: inline-block; padding: 2px 9px; border-radius: 9999px; font-size: 11.5px; font-weight: 500;
            border: 1px solid #d9d9d9; color: #595959; background: #fafafa;
        }
        .rp-badge.tone-new { border-color: #b7eb8f; color: #389e0d; background: #f6ffed; }
        .rp-badge.tone-back { border-color: #91d5ff; color: #096dd9; background: #e6f7ff; }
        .rp-badge.tone-stop { border-color: #ffa39e; color: #cf1322; background: #fff1f0; }
        .rp-badge.tone-wait { border-color: #ffd591; color: #d46b08; background: #fff7e6; }

        @media (max-width: 1600px) {
            .rp-kpis { grid-template-columns: repeat(3, 1fr); }
            .rp-kpis--4 { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 1200px) {
            .rp-grid--2-1, .rp-grid--1-1, .rp-grid--3 { grid-template-columns: 1fr; }
            .rp-kpis, .rp-kpis--4 { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 640px) {
            .rp { padding: 16px 14px 24px; }
            .rp-kpis, .rp-kpis--4 { grid-template-columns: 1fr; }
            .rp-bar-row { grid-template-columns: minmax(0, 96px) 1fr 40px 56px; }
            .rp-bar-row--slim { grid-template-columns: minmax(0, 96px) 1fr 40px; }
        }
    </style>
@endonce
