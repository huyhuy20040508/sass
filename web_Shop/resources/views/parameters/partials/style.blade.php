{{-- CSS của khu Thông số chung. Cùng hệ màu, nút và callout với các trang quản trị khác. --}}
<style>
    .tsc {
        /* Phá padding p-4 của <main> để tràn viền như mọi trang quản trị khác */
        margin: -1.5rem;
        min-height: calc(100vh - 56px);
        font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        color: #262626; background: #fff;
        display: flex; flex-direction: column;
    }

    .tsc-head {
        display: flex; align-items: center; justify-content: space-between;
        gap: 16px; flex-wrap: wrap; padding: 14px 20px 0;
    }
    .tsc-head-text { min-width: 0; }
    .tsc-title { margin: 0; font-size: 16px; font-weight: 700; line-height: 24px; }
    .tsc-sub { margin: 2px 0 0; font-size: 13px; color: #8c8c8c; max-width: 760px; }
    .tsc-head-actions { display: flex; align-items: center; gap: 8px; flex-shrink: 0; }

    .tsc-btn-primary {
        height: 34px; display: inline-flex; align-items: center; gap: 6px; border: 0; border-radius: 4px;
        background: #1890ff; color: #fff; padding: 0 16px; font-size: 13px; font-weight: 500; cursor: pointer;
        transition: background .15s;
    }
    .tsc-btn-primary:hover { background: #40a9ff; }
    .tsc-btn-primary:disabled { background: #bfbfbf; cursor: not-allowed; }
    .tsc-btn-primary:focus-visible { outline: none; box-shadow: 0 0 0 .25rem rgba(13, 110, 253, .25); }

    .tsc-callout { margin: 12px 20px 0; padding: 10px 12px; border-radius: 4px; font-size: 13px; }
    .tsc-callout.is-error { background: #fff2f0; border: 1px solid #ffccc7; color: #cf1322; }

    /* Thanh mục bên trái + nội dung bên phải, đúng bố cục bản cũ. */
    .tsc-body { display: grid; grid-template-columns: 240px minmax(0, 1fr); flex: 1; min-height: 0; margin-top: 14px; }
    @media (max-width: 900px) { .tsc-body { grid-template-columns: 1fr; } }

    .tsc-side { border-right: 1px solid #f0f0f0; padding: 6px; }
    .tsc-nav-head {
        padding: 10px 10px 8px; font-size: 12px; font-weight: 600; color: #8c8c8c;
        text-transform: uppercase; letter-spacing: .04em;
    }
    .tsc-nav-item {
        display: block; padding: 9px 10px; border-radius: 4px; font-size: 13px; font-weight: 500;
        color: #262626; text-decoration: none;
    }
    .tsc-nav-item:hover { background: #fafafa; }
    .tsc-nav-item.is-active { background: #e6f4ff; color: #0958d9; }

    .tsc-main { min-width: 0; padding: 0 20px 24px; }

    .tsc-block { margin-top: 18px; }
    .tsc-block-head {
        padding: 0 0 8px; font-size: 13px; font-weight: 600; color: #262626;
        border-bottom: 1px solid #f0f0f0; margin-bottom: 10px;
    }
    .tsc-note { margin: 8px 0 0; font-size: 12px; color: #8c8c8c; line-height: 18px; }

    /* ----- Bảng ----- */
    .tsc-table-wrap { overflow-x: auto; }
    .tsc-table { width: 100%; table-layout: fixed; border-collapse: collapse; font-size: 13px; }
    .tsc-table th, .tsc-table td {
        text-align: center; vertical-align: middle; padding: 9px 8px;
        border-bottom: 1px solid #f0f0f0; word-break: break-word;
    }
    .tsc-table thead th {
        background: #fafafa; font-weight: 600; color: #595959; white-space: nowrap;
        border-bottom: 1px solid #e8e8e8;
    }
    .tsc-table code { background: #f5f5f5; color: #595959; padding: 1px 6px; border-radius: 3px; font-size: 12px; }

    /* Bảng chi nhánh: cả dòng là nút chọn, dòng đang mở tô nền. */
    .tsc-cn { cursor: pointer; }
    .tsc-cn:hover td { background: #fafafa; }
    .tsc-cn.is-open td { background: #e6f4ff; font-weight: 500; }
    .tsc-cn:focus-visible { outline: 2px solid #40a9ff; outline-offset: -2px; }

    .tsc-table-qt .tsc-ten { text-align: left; }
    .tsc-chip {
        margin-left: 6px; font-size: 11px; font-weight: 600; padding: 1px 6px;
        border-radius: 3px; background: #f1f3f5; color: #495057;
    }
    .tsc-mau { font-size: 12px; }

    .tsc-input {
        width: 100%; height: 32px; box-sizing: border-box; padding: 0 8px;
        border: 1px solid #d9d9d9; border-radius: 4px; font-size: 13px;
        text-align: center; outline: none; background: #fff; color: #262626;
    }
    .tsc-input:focus { border-color: #40a9ff; box-shadow: 0 0 0 2px rgba(24, 144, 255, .1); }
    .tsc-input:disabled { background: #f5f5f5; color: #bfbfbf; }
    select.tsc-input { text-align: left; }

    /* ----- Ô tick danh mục ----- */
    .tsc-ticks { display: flex; flex-wrap: wrap; gap: 10px 24px; }
    .tsc-tick {
        display: inline-flex; align-items: center; gap: 7px; margin: 0;
        font-size: 13px; color: #262626; cursor: pointer; user-select: none;
    }
    .tsc-tick input { width: 15px; height: 15px; cursor: pointer; }

    .tsc-empty { padding: 48px 16px; text-align: center; }
    .tsc-empty-title { margin: 0; font-size: 14px; font-weight: 600; }
    .tsc-empty-sub { margin: 4px 0 0; font-size: 13px; color: #8c8c8c; }
</style>
