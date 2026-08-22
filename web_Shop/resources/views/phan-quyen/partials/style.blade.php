{{-- CSS của trang Phân quyền. --}}
<style>
    .pq {
        /* Phá padding p-4 của <main> để tràn viền như mọi trang quản trị khác */
        margin: -1.5rem;
        min-height: calc(100vh - 56px);
        font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        color: #262626; background: #fff;
        display: flex; flex-direction: column;
    }

    .pq-head {
        display: flex; align-items: center; justify-content: space-between;
        gap: 16px; flex-wrap: wrap;
        padding: 14px 20px 0;
    }
    .pq-head-text { min-width: 0; }
    .pq-title { margin: 0; font-size: 16px; font-weight: 700; line-height: 24px; }
    .pq-sub { margin: 2px 0 0; font-size: 13px; color: #8c8c8c; max-width: 720px; }
    .pq-head-actions { display: flex; align-items: center; gap: 8px; flex-shrink: 0; }

    .pq-btn-primary {
        height: 34px; display: inline-flex; align-items: center; gap: 6px; border: 0; border-radius: 4px;
        background: #1890ff; color: #fff; padding: 0 16px; font-size: 13px; font-weight: 500; cursor: pointer;
        transition: background .15s;
    }
    .pq-btn-primary:hover { background: #40a9ff; }
    .pq-btn-primary:disabled { background: #bfbfbf; cursor: not-allowed; }
    .pq-btn-ghost {
        height: 34px; display: inline-flex; align-items: center; gap: 6px; border: 1px solid #d9d9d9;
        border-radius: 4px; background: #fff; padding: 0 14px; font-size: 13px; font-weight: 500;
        color: #595959; cursor: pointer; transition: border-color .15s, color .15s;
    }
    .pq-btn-ghost:hover { border-color: #bfbfbf; }
    .pq-btn-primary:focus-visible, .pq-btn-ghost:focus-visible {
        outline: none; box-shadow: 0 0 0 .25rem rgba(13, 110, 253, .25);
    }
    .pq-checkall {
        display: inline-flex; align-items: center; gap: 6px; height: 34px;
        font-size: 13px; color: #595959; cursor: pointer; user-select: none; margin: 0;
    }
    .pq-callout { margin: 12px 20px 0; padding: 10px 12px; border-radius: 4px; font-size: 13px; }
    .pq-callout.is-error { background: #fff2f0; border: 1px solid #ffccc7; color: #cf1322; }
    .pq-callout.is-warn { background: #fffbe6; border: 1px solid #ffe58f; color: #874d00; }
    .pq-callout.is-info { background: #e6f4ff; border: 1px solid #91caff; color: #003a8c; }
    .pq-callout a { color: inherit; }

    /* Hai cột: cây nhân viên cố định bên trái, bảng tick cuộn bên phải. */
    .pq-body { display: grid; grid-template-columns: 280px minmax(0, 1fr); flex: 1; min-height: 0; }
    @media (max-width: 900px) { .pq-body { grid-template-columns: 1fr; } }

    .pq-side { border-right: 1px solid #f0f0f0; display: flex; flex-direction: column; min-height: 0; }
    .pq-side-head {
        display: flex; align-items: center; justify-content: space-between;
        padding: 12px 16px; border-bottom: 1px solid #f0f0f0;
        font-size: 12px; font-weight: 600; color: #8c8c8c;
        text-transform: uppercase; letter-spacing: .04em;
    }
    .pq-side-count {
        background: #f5f5f5; color: #595959; border-radius: 10px;
        padding: 1px 8px; font-size: 12px; letter-spacing: 0;
    }
    .pq-side-list { flex: 1; overflow-y: auto; padding: 6px; }
    .pq-side-empty { margin: 0; padding: 16px; font-size: 13px; color: #8c8c8c; }

    .pq-chip {
        font-size: 11px; font-weight: 600; padding: 1px 6px; border-radius: 3px;
        background: #f1f3f5; color: #495057;
    }
    .pq-chip.is-off { background: #fff1f0; color: #cf1322; }

    /* ----- Cây chi nhánh → nhân viên ----- */
    .pq-search { padding: 8px; border-bottom: 1px solid #f0f0f0; }
    .pq-search input {
        width: 100%; height: 32px; box-sizing: border-box; padding: 0 10px;
        border: 1px solid #d9d9d9; border-radius: 4px; font-size: 13px; outline: none;
    }
    .pq-search input:focus { border-color: #40a9ff; }

    .pq-branch { margin-bottom: 2px; }
    .pq-branch-btn {
        width: 100%; display: flex; align-items: center; gap: 6px;
        padding: 7px 8px; border: 0; border-radius: 4px; background: transparent;
        font-family: inherit; font-size: 13px; font-weight: 600; color: #262626;
        text-align: left; cursor: pointer;
    }
    .pq-branch-btn:hover { background: #fafafa; }
    .pq-branch-btn .pq-caret { color: #bfbfbf; flex-shrink: 0; }
    .pq-branch:not(.is-open) .pq-caret { transform: rotate(-90deg); }
    .pq-branch-num { margin-left: auto; font-size: 12px; font-weight: 400; color: #8c8c8c; }
    .pq-branch-emps { padding-left: 18px; }
    .pq-branch:not(.is-open) .pq-branch-emps { display: none; }

    .pq-emp {
        display: flex; flex-direction: column; gap: 1px;
        padding: 6px 8px; border-radius: 4px; text-decoration: none;
        border-left: 3px solid transparent;
    }
    .pq-emp:hover { background: #fafafa; }
    .pq-emp.is-active { background: #e6f4ff; border-left-color: #1890ff; }
    .pq-emp.is-hidden { display: none; }
    .pq-emp-name { font-size: 13px; font-weight: 500; color: #262626; }
    .pq-emp-sub { font-size: 11.5px; color: #8c8c8c; }

    .pq-main { display: flex; flex-direction: column; min-width: 0; min-height: 0; }
    .pq-main-head {
        display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap;
        padding: 14px 20px; border-bottom: 1px solid #f0f0f0;
    }
    .pq-main-text { min-width: 0; }
    .pq-main-title { margin: 0; font-size: 14.5px; font-weight: 700; }
    .pq-main-note { margin: 2px 0 0; font-size: 12.5px; color: #8c8c8c; }
    .pq-main-note code { background: #f5f5f5; border-radius: 3px; padding: 1px 5px; color: #595959; }
    /* ----- Bảng ma trận ----- */
    .pq-form { flex: 1; min-height: 0; display: flex; }
    .pq-matrix-wrap { flex: 1; overflow: auto; }
    .pq-matrix { width: 100%; min-width: 780px; border-collapse: collapse; font-size: 13.5px; }
    .pq-matrix th {
        position: sticky; top: 0; z-index: 1;
        text-align: left; font-weight: 600; color: #595959; background: #fafafa;
        padding: 10px 12px; border-bottom: 1px solid #f0f0f0; white-space: nowrap;
    }
    .pq-matrix td { padding: 0 12px; border-bottom: 1px solid #f5f5f5; vertical-align: middle; }
    .pq-col-name { width: 32%; }
    .pq-col-viec { width: 84px; text-align: center; }
    .pq-matrix th.pq-col-viec { text-align: center; }

    /* MỤC LỚN — một khu làm việc (Quản trị / Thu ngân), gập sẵn.
       Nền trắng như bản ERP cũ; thứ tách nó khỏi hàng nhóm bên dưới là ĐỘ THỤT
       ĐẦU DÒNG, không phải màu. Ba tầng mà tầng nào cũng tô một màu thì bảng
       thành cái cầu vồng và mắt vẫn phải đếm để biết mình đang ở đâu. */
    .pq-khu td { background: #fff; padding: 11px 12px; border-bottom: 1px solid #f0f0f0; }
    .pq-khu-btn, .pq-sec-btn {
        border: 0; background: transparent; padding: 0 6px 0 0; cursor: pointer;
        color: #bfbfbf; vertical-align: middle;
    }
    .pq-khu:not(.is-open) .pq-caret { transform: rotate(-90deg); }
    .pq-khu-label { display: inline-flex; align-items: center; gap: 8px; margin: 0; cursor: pointer; }
    .pq-khu-ten { font-size: 13.5px; font-weight: 700; color: #262626; }
    .pq-khu-mota { margin-left: 10px; font-size: 12px; color: #bfbfbf; }
    .pq-khu.is-khoa .pq-khu-ten { color: #8c8c8c; }

    /* Hàng nhóm — tầng giữa, bấm vào để gập cả nhóm. Thụt vào một nấc so với mục lớn. */
    .pq-sec.is-hidden { display: none; }
    .pq-sec td { background: #fafafa; padding: 8px 12px 8px 34px; border-bottom: 1px solid #f0f0f0; }
    .pq-caret { transition: transform .18s ease; }
    .pq-sec:not(.is-open) .pq-caret { transform: rotate(-90deg); }
    .pq-sec-label {
        display: inline-flex; align-items: center; gap: 8px; margin: 0; cursor: pointer;
        font-size: 12.5px; font-weight: 700; color: #262626;
        text-transform: uppercase; letter-spacing: .03em;
    }

    .pq-row.is-hidden { display: none; }
    .pq-row td { padding: 9px 12px; }
    /* Tầng trong cùng, thụt thêm một nấc nữa. */
    .pq-row td.pq-col-name { padding-left: 56px; }
    .pq-row:hover td { background: #fafafa; }
    .pq-row-label { display: inline-flex; align-items: center; gap: 8px; margin: 0; cursor: pointer; }
    .pq-le {
        display: inline-flex; align-items: center; gap: 6px; margin: 0 14px 0 0;
        font-size: 13px; color: #595959; cursor: pointer;
    }

    .pq-cb { width: 15px; height: 15px; accent-color: #1890ff; cursor: pointer; margin: 0; }
    /* Bảng chỉ đọc: vẫn thấy rõ tick nhưng không mời bấm. */
    .pq-matrix.is-readonly .pq-cb { cursor: default; opacity: .9; }

    /* Ô KHOÁ — việc của khu quản trị, người chỉ có cửa quầy không tick được.
       Làm nhạt chứ không giấu đi: giấu thì bảng của hai người trông khác nhau và
       không ai đoán ra vì sao, còn để nhạt thì đọc ra ngay "có mục này, chỉ là
       chưa tới lượt anh". Con trỏ cấm cộng title nói nốt phần vì sao. */
    .pq-cb:disabled { cursor: not-allowed; }
    .pq-row.is-khoa td, .pq-sec.is-khoa td { color: #bfbfbf; }
    .pq-row.is-khoa:hover td { background: transparent; }
    .pq-le.is-khoa { color: #bfbfbf; cursor: not-allowed; }

    .pq-foot {
        padding: 10px 20px; border-top: 1px solid #f0f0f0;
        font-size: 12.5px; color: #8c8c8c;
    }

    .pq-empty { padding: 48px 20px; text-align: center; color: #8c8c8c; }
    .pq-empty-title { margin: 0; font-size: 14px; font-weight: 600; color: #595959; }
    .pq-empty-sub { margin: 4px 0 0; font-size: 13px; }
</style>
