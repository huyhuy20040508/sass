{{-- Chuông thông báo, tách từ partials/topbar.blade.php để dùng lại trong khung v2.
     Giữ nguyên id jhTbNotif* vì public/js/realtime.js bám thẳng vào chúng —
     đổi tên là mất luôn SSE, đếm chưa đọc và tiếng chuông. --}}
<div class="jh-tb-notif" id="jhTbNotif"
     data-list-url="{{ route('admin.notifications.index') }}"
     data-read-url="{{ url('/admin/notifications') }}"
     data-read-all-url="{{ route('admin.notifications.readAll') }}"
     data-stream-token-url="{{ route('admin.notifications.streamToken') }}"
     data-orders-url="{{ route('admin.orders.index') }}"
     data-returns-url="{{ route('admin.returns.index') }}"
     data-inventory-url="{{ route('admin.ton-kho-chi-nhanh.index') }}">
    <button type="button" class="jh-tb-iconbtn" id="jhTbNotifBtn" aria-label="Thông báo"
            aria-haspopup="true" aria-expanded="false">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/>
        </svg>
        <span class="jh-tb-badge" id="jhTbNotifBadge" hidden>0</span>
    </button>

    <div class="jh-tb-notifmenu">
        <div class="jh-tb-notifmenu__head">
            <span class="jh-tb-notifmenu__title">{{ __('message.notification') }}</span>
            {{-- Chấm trạng thái: xanh = nhận tức thì, cam = hỏi định kỳ, xám = mất kết nối. --}}
            <span class="jh-tb-notifdot is-off" id="jhTbNotifDot" title="Chưa kết nối"></span>

            <button type="button" class="jh-tb-notifsound" id="jhTbNotifSound"
                    aria-pressed="true" title="Tắt tiếng chuông">
                <svg class="jh-tb-notifsound__on" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M11 5 6 9H3v6h3l5 4V5Z"/><path d="M15.5 8.5a5 5 0 0 1 0 7"/><path d="M18.5 5.5a9 9 0 0 1 0 13"/>
                </svg>
                <svg class="jh-tb-notifsound__off" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M11 5 6 9H3v6h3l5 4V5Z"/><path d="m17 9 4 6"/><path d="m21 9-4 6"/>
                </svg>
            </button>

            <button type="button" class="jh-tb-notifmenu__readall" id="jhTbNotifReadAll">Đánh dấu đã đọc</button>
        </div>

        <div class="jh-tb-notiflist" id="jhTbNotifList">
            <p class="jh-tb-notifempty">Đang tải…</p>
        </div>

        <div class="jh-tb-notifmenu__foot">
            <a href="{{ route('admin.orders.index') }}">Xem tất cả đơn hàng</a>
        </div>
    </div>
</div>

<style>
    /* Trong khung v2 chuông nằm trên nền xanh đậm, nên icon để trắng thay vì xám. */
    .header_menu .jh-tb-iconbtn { color: #fff; }
    .header_menu .jh-tb-iconbtn:hover { background: rgba(255, 255, 255, .15); color: #fff; }

    .jh-tb-iconbtn {
        position: relative; display: inline-flex; align-items: center; justify-content: center;
        height: 38px; width: 38px;
        border: 0; background: transparent; cursor: pointer;
        border-radius: 9999px; color: #64748b;
        transition: background .15s, color .15s;
    }
    .jh-tb-iconbtn:hover { background: #f1f5f9; color: #334155; }
    .jh-tb-iconbtn svg { height: 20px; width: 20px; }
    .jh-tb-badge {
        position: absolute; right: 5px; top: 5px;
        display: flex; align-items: center; justify-content: center;
        height: 16px; min-width: 16px; padding: 0 4px;
        border-radius: 9999px; background: #ef4444;
        font-size: 10px; font-weight: 600; line-height: 1; color: #fff;
        box-shadow: 0 0 0 2px #fff;
    }

    .jh-tb-notif { position: relative; }
    .jh-tb-notifmenu {
        position: absolute; right: 0; top: 100%; margin-top: 8px;
        width: 360px; max-width: calc(100vw - 24px);
        border: 1px solid #e2e8f0; border-radius: 8px; background: #fff;
        z-index: 30; display: none; overflow: hidden;
        box-shadow: 0 10px 15px -3px rgba(0, 0, 0, .1), 0 4px 6px -4px rgba(0, 0, 0, .1);
    }
    .jh-tb-notif.open .jh-tb-notifmenu { display: block; }

    .jh-tb-notifmenu__head {
        display: flex; align-items: center; gap: 8px;
        padding: 10px 12px; border-bottom: 1px solid #f1f5f9;
    }
    .jh-tb-notifmenu__title { font-size: 14px; font-weight: 600; color: #1e293b; }
    .jh-tb-notifmenu__readall {
        margin-left: 0; border: 0; background: transparent; cursor: pointer;
        font-size: 12px; color: #2563eb; padding: 2px 4px; border-radius: 4px;
    }
    .jh-tb-notifmenu__readall:hover { background: #eff6ff; }
    .jh-tb-notifmenu__readall[disabled] { color: #94a3b8; cursor: default; background: transparent; }

    .jh-tb-notifdot { width: 7px; height: 7px; border-radius: 9999px; background: #22c55e; flex-shrink: 0; }
    .jh-tb-notifdot.is-poll { background: #f59e0b; }
    .jh-tb-notifdot.is-off { background: #cbd5e1; }

    .jh-tb-notifsound {
        margin-left: auto; display: inline-flex; align-items: center; justify-content: center;
        width: 26px; height: 26px; padding: 0; border: 0; border-radius: 6px;
        background: transparent; color: #64748b; cursor: pointer; transition: background .15s, color .15s;
    }
    .jh-tb-notifsound:hover { background: #f1f5f9; color: #334155; }
    .jh-tb-notifsound svg { width: 15px; height: 15px; }
    .jh-tb-notifsound.is-off { color: #cbd5e1; }
    .jh-tb-notifsound .jh-tb-notifsound__off { display: none; }
    .jh-tb-notifsound.is-off .jh-tb-notifsound__on { display: none; }
    .jh-tb-notifsound.is-off .jh-tb-notifsound__off { display: block; }

    .jh-tb-notiflist { max-height: 380px; overflow-y: auto; }
    .jh-tb-notifempty { margin: 0; padding: 24px 12px; text-align: center; font-size: 13px; color: #94a3b8; }

    .jh-tb-notifitem {
        display: block; width: 100%; text-align: left;
        border: 0; border-bottom: 1px solid #f8fafc; background: #fff;
        padding: 10px 12px; cursor: pointer; text-decoration: none;
        transition: background .15s;
    }
    .jh-tb-notifitem:hover { background: #f8fafc; }
    .jh-tb-notifitem.is-unread { background: #f5f9ff; box-shadow: inset 3px 0 0 #2563eb; }
    .jh-tb-notifitem.is-unread:hover { background: #eef5ff; }

    .jh-tb-notifitem__title {
        display: block; font-size: 13px; font-weight: 600; color: #1e293b;
        margin-bottom: 2px; line-height: 1.35;
    }
    .jh-tb-notifitem__body { display: block; font-size: 12px; color: #475569; line-height: 1.4; }
    .jh-tb-notifitem__time { display: block; margin-top: 4px; font-size: 11px; color: #94a3b8; }

    .jh-tb-notifmenu__foot { border-top: 1px solid #f1f5f9; padding: 8px 12px; text-align: center; }
    .jh-tb-notifmenu__foot a { font-size: 13px; color: #2563eb; text-decoration: none; }
    .jh-tb-notifmenu__foot a:hover { text-decoration: underline; }
</style>
