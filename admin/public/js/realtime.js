/**
 * realtime.js — chuông thông báo + cập nhật tức thì cho trang quản trị.
 *
 * Ba việc:
 *   1. Nạp và vẽ danh sách thông báo trong chuông ở topbar (fetch qua Laravel).
 *   2. Giữ một kết nối SSE tới Go API để đơn mới hiện lên ngay, không cần F5.
 *   3. Kêu một tiếng chuông khi có thông báo mới (tắt/bật được, nhớ lựa chọn).
 *
 * Kết nối SSE đi THẲNG từ trình duyệt sang Go chứ không qua Laravel: `php artisan
 * serve` chỉ chạy một tiến trình nên một kết nối giữ mở sẽ chặn mọi request khác
 * của trang quản trị. Đổi lại trình duyệt cần access token — nó xin ở
 * /admin/notifications/stream-token và xin lại mỗi lần kết nối rớt, nên token hết
 * hạn không buộc người dùng tải lại trang.
 *
 * SSE nối không được (trình duyệt cũ, proxy/antivirus cắt kết nối giữ mở, mạng
 * công ty chặn text/event-stream) thì TỰ CHUYỂN sang hỏi định kỳ. Chậm hơn vài
 * giây nhưng vẫn không phải F5 — tính năng không được phép chết hẳn chỉ vì môi
 * trường mạng của một máy.
 *
 * Bật log chi tiết để soi lỗi: localStorage.setItem('rtDebug', '1') rồi tải lại.
 */
(function () {
    'use strict';

    var root = document.getElementById('jhTbNotif');
    if (!root) return;

    var URLS = {
        list: root.dataset.listUrl,
        read: root.dataset.readUrl,          // + /{id}/read
        readAll: root.dataset.readAllUrl,
        streamToken: root.dataset.streamTokenUrl,
        orders: root.dataset.ordersUrl,
        returns: root.dataset.returnsUrl,
        inventory: root.dataset.inventoryUrl,
    };

    var $btn = document.getElementById('jhTbNotifBtn');
    var $badge = document.getElementById('jhTbNotifBadge');
    var $list = document.getElementById('jhTbNotifList');
    var $dot = document.getElementById('jhTbNotifDot');
    var $readAll = document.getElementById('jhTbNotifReadAll');
    var $sound = document.getElementById('jhTbNotifSound');

    var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
    var items = [];
    var unread = 0;
    var lastId = 0;   // id thông báo mới nhất đã biết — mốc để nhận ra cái mới khi hỏi định kỳ

    var DEBUG = false;
    try { DEBUG = localStorage.getItem('rtDebug') === '1'; } catch (e) { /* trình duyệt chặn storage */ }
    function log() {
        if (!DEBUG) return;
        var a = Array.prototype.slice.call(arguments);
        console.log.apply(console, ['[realtime]'].concat(a));
    }

    // ---------- Tiện ích ----------

    function post(url) {
        return fetch(url, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
        });
    }

    /** "5 phút trước" — mốc thời gian tuyệt đối vô ích với thông báo vừa xảy ra. */
    function timeAgo(iso) {
        var t = Date.parse(iso);
        if (isNaN(t)) return '';
        var s = Math.max(0, Math.floor((Date.now() - t) / 1000));
        if (s < 60) return 'vừa xong';
        if (s < 3600) return Math.floor(s / 60) + ' phút trước';
        if (s < 86400) return Math.floor(s / 3600) + ' giờ trước';
        if (s < 604800) return Math.floor(s / 86400) + ' ngày trước';
        return new Date(t).toLocaleDateString('vi-VN');
    }

    /** Cột `data` là chuỗi JSON trong database — chuỗi hỏng thì bỏ qua, không nổ. */
    function parseData(n) {
        if (!n || !n.data) return null;
        if (typeof n.data === 'object') return n.data;
        try { return JSON.parse(n.data); } catch (e) { return null; }
    }

    /** Thông báo về đơn nào thì lọc thẳng tới đơn đó trong danh sách. */
    function targetUrl(n) {
        var d = parseData(n);
        if (!d) return URLS.orders;
        // Thông báo tự khai chỗ cần mở (vd nhắc hạn hợp đồng → trang Gói dịch vụ).
        // Chỉ nhận ĐƯỜNG DẪN NỘI BỘ bắt đầu bằng "/admin/": dữ liệu này đi qua
        // database, và nhận một địa chỉ đầy đủ ở đây là biến cái chuông thành chỗ
        // đưa người dùng sang trang bất kỳ.
        if (typeof d.duong_dan === 'string' && d.duong_dan.indexOf('/admin/') === 0) {
            return d.duong_dan;
        }
        // Thông báo trả hàng phải mở đúng trang Trả hàng: gói tin của phiếu có kèm
        // cả order_code nên nếu chỉ nhìn order_code sẽ đưa nhầm sang trang Đơn hàng.
        if (d.return_code && URLS.returns) {
            return URLS.returns + '?keyword=' + encodeURIComponent(d.return_code);
        }
        // Cảnh báo tồn kho cũng kèm order_code (đơn nào làm hàng cạn), nhưng việc
        // cần làm là nhập hàng chứ không phải xem đơn — mở thẳng trang Tồn kho.
        // Một biến thể thì lọc đúng SKU đó, nhiều biến thể thì lọc theo mức tồn.
        if (d.stock_level && URLS.inventory) {
            return d.variant_sku
                ? URLS.inventory + '?keyword=' + encodeURIComponent(d.variant_sku)
                : URLS.inventory + '?stock=' + encodeURIComponent(d.stock_level);
        }
        return d.order_code
            ? URLS.orders + '?keyword=' + encodeURIComponent(d.order_code)
            : URLS.orders;
    }

    function safeParse(raw) {
        try { return JSON.parse(raw); } catch (e) { return null; }
    }

    // ---------- Tiếng chuông ----------
    //
    // Dựng bằng Web Audio thay vì tải file mp3: không thêm asset, không thêm
    // request, và không bao giờ 404 sau này khi ai đó dọn thư mục public.
    //
    // Trình duyệt chặn phát tiếng cho tới khi người dùng có thao tác đầu tiên
    // (chính sách autoplay). Vì vậy AudioContext chỉ được dựng/đánh thức trong
    // một sự kiện thao tác thật; trước đó thông báo vẫn hiện, chỉ là im tiếng.

    var audioCtx = null;
    var soundOn = true;
    try { soundOn = localStorage.getItem('rtMuted') !== '1'; } catch (e) { /* bỏ qua */ }

    function primeAudio() {
        try {
            var Ctx = window.AudioContext || window.webkitAudioContext;
            if (!Ctx) return;
            if (!audioCtx) audioCtx = new Ctx();
            if (audioCtx.state === 'suspended') audioCtx.resume();
        } catch (e) {
            log('không dựng được AudioContext', e);
        }
    }
    // Thao tác đầu tiên bất kỳ là lúc hợp lệ để xin quyền phát tiếng.
    ['click', 'keydown'].forEach(function (evt) {
        document.addEventListener(evt, primeAudio, { once: true, capture: true });
    });

    /** Hai nốt ngắn đi lên — đủ để nhận ra mà không giật mình. */
    function chime() {
        if (!soundOn || !audioCtx || audioCtx.state !== 'running') return;
        try {
            [[880, 0], [1174.7, 0.12]].forEach(function (pair) {
                var osc = audioCtx.createOscillator();
                var gain = audioCtx.createGain();
                var at = audioCtx.currentTime + pair[1];
                osc.type = 'sine';
                osc.frequency.setValueAtTime(pair[0], at);
                // Vào/ra mượt: đổi biên độ đột ngột nghe thành tiếng "cạch".
                gain.gain.setValueAtTime(0.0001, at);
                gain.gain.exponentialRampToValueAtTime(0.16, at + 0.02);
                gain.gain.exponentialRampToValueAtTime(0.0001, at + 0.22);
                osc.connect(gain).connect(audioCtx.destination);
                osc.start(at);
                osc.stop(at + 0.24);
            });
        } catch (e) {
            log('không phát được tiếng chuông', e);
        }
    }

    function renderSoundBtn() {
        if (!$sound) return;
        $sound.classList.toggle('is-off', !soundOn);
        $sound.title = soundOn ? 'Tắt tiếng chuông' : 'Bật tiếng chuông';
        $sound.setAttribute('aria-pressed', soundOn ? 'true' : 'false');
    }

    if ($sound) {
        $sound.addEventListener('click', function (e) {
            e.stopPropagation();
            soundOn = !soundOn;
            try { localStorage.setItem('rtMuted', soundOn ? '0' : '1'); } catch (err) { /* bỏ qua */ }
            renderSoundBtn();
            // Bấm bật là một thao tác thật — nghe thử luôn để biết nó kêu thế nào.
            if (soundOn) { primeAudio(); setTimeout(chime, 60); }
        });
        renderSoundBtn();
    }

    // ---------- Vẽ giao diện ----------

    function renderBadge() {
        if (unread > 0) {
            $badge.textContent = unread > 99 ? '99+' : String(unread);
            $badge.hidden = false;
        } else {
            $badge.hidden = true;
        }
        $readAll.disabled = unread === 0;
    }

    function renderList() {
        $list.textContent = '';

        if (!items.length) {
            var empty = document.createElement('p');
            empty.className = 'jh-tb-notifempty';
            empty.textContent = 'Chưa có thông báo nào.';
            $list.appendChild(empty);
            return;
        }

        items.forEach(function (n) {
            var a = document.createElement('a');
            a.className = 'jh-tb-notifitem' + (n.is_read ? '' : ' is-unread');
            a.href = targetUrl(n);
            a.dataset.id = n.id;

            var title = document.createElement('span');
            title.className = 'jh-tb-notifitem__title';
            title.textContent = n.title || '';
            a.appendChild(title);

            if (n.content) {
                var body = document.createElement('span');
                body.className = 'jh-tb-notifitem__body';
                body.textContent = n.content;
                a.appendChild(body);
            }

            var time = document.createElement('span');
            time.className = 'jh-tb-notifitem__time';
            time.textContent = timeAgo(n.created_at);
            a.appendChild(time);

            $list.appendChild(a);
        });
    }

    /** mode: 'live' (SSE) | 'poll' (hỏi định kỳ) | 'off'. */
    function setMode(mode) {
        $dot.classList.toggle('is-off', mode === 'off');
        $dot.classList.toggle('is-poll', mode === 'poll');
        $dot.title = mode === 'live' ? 'Đang nhận thông báo tức thì'
            : mode === 'poll' ? 'Đang cập nhật định kỳ (không nối được luồng tức thì)'
                : 'Mất kết nối — đang thử nối lại';
        log('trạng thái:', mode);
    }

    /** Cảnh báo kho phải nhìn ra ngay là khác đơn mới: đỏ khi hết sạch, vàng khi sắp hết. */
    function toneOf(n) {
        var d = parseData(n);
        if (!d || !d.stock_level) return 'info';
        return d.stock_level === 'out' ? 'danger' : 'warning';
    }

    function announce(n) {
        if (window.adminToast) {
            window.adminToast({ title: n.title, body: n.content, href: targetUrl(n), tone: toneOf(n) });
        }
        chime();
    }

    // ---------- Dữ liệu ----------

    /**
     * Nạp lại danh sách thông báo.
     * quiet = true: chỉ đồng bộ, không kêu chuông (lần đầu vào trang, hoặc nối lại).
     */
    function load(quiet) {
        return fetch(URLS.list, { headers: { Accept: 'application/json' } })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (d) {
                if (!d) return null;
                var fresh = (d.items || []).filter(function (n) { return n.id > lastId; });

                items = d.items || [];
                unread = d.unread_count || 0;
                if (items.length) lastId = Math.max(lastId, items[0].id);
                renderBadge();
                renderList();

                // Ở chế độ hỏi định kỳ, đây là nơi duy nhất phát hiện cái mới.
                if (!quiet && fresh.length) {
                    announce(fresh[0]);
                    if (typeof window.onOrderRealtime === 'function') window.onOrderRealtime(parseData(fresh[0]));
                }
                return fresh;
            })
            .catch(function () { return null; });  // chuông hỏng thì thôi, không chặn trang
    }

    /** Thêm một thông báo vừa nhận qua SSE vào đầu danh sách. */
    function prepend(n) {
        // Cùng một thông báo có thể tới hai lần (nối lại rồi tải lại danh sách)
        // — lọc theo id để không hiện trùng.
        if (items.some(function (x) { return x.id === n.id; })) return false;
        items.unshift(n);
        if (items.length > 15) items.pop();
        if (n.id > lastId) lastId = n.id;
        if (!n.is_read) unread += 1;
        renderBadge();
        renderList();
        return true;
    }

    // ---------- Thao tác ----------

    $btn.addEventListener('click', function (e) {
        e.stopPropagation();
        var open = root.classList.toggle('open');
        $btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (open) load(true);
    });
    document.addEventListener('click', function (e) {
        if (!root.contains(e.target)) {
            root.classList.remove('open');
            $btn.setAttribute('aria-expanded', 'false');
        }
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') root.classList.remove('open');
    });

    $readAll.addEventListener('click', function (e) {
        e.stopPropagation();
        post(URLS.readAll).then(function () {
            items.forEach(function (n) { n.is_read = true; });
            unread = 0;
            renderBadge();
            renderList();
        });
    });

    // Bấm vào một thông báo: đánh dấu đã đọc rồi mới đi tiếp. Không chặn điều
    // hướng chờ server trả lời — đánh dấu đã đọc hỏng thì cùng lắm badge sai một
    // nhịp, còn chặn link lại làm người dùng tưởng bấm hụt.
    $list.addEventListener('click', function (e) {
        var el = e.target.closest('.jh-tb-notifitem');
        if (!el || !el.dataset.id) return;
        if (el.classList.contains('is-unread')) {
            post(URLS.read + '/' + el.dataset.id + '/read');
        }
    });

    // ---------- Luồng SSE ----------

    var es = null;
    var retry = 0;
    var retryTimer = null;
    var pollTimer = null;
    var everConnected = false;

    // Thử SSE tối đa ngần này lần rồi mới chịu thua và chuyển sang hỏi định kỳ.
    var MAX_SSE_TRIES = 3;
    var POLL_INTERVAL = 10000;

    function connect() {
        clearTimeout(retryTimer);
        log('xin token để mở luồng…');

        fetch(URLS.streamToken, { headers: { Accept: 'application/json' } })
            .then(function (r) {
                if (r.status === 401) throw new Error('session-expired');
                if (!r.ok) throw new Error('token-failed:' + r.status);
                return r.json();
            })
            .then(function (d) {
                if (!d.token || !d.url) throw new Error('token-empty');
                openStream(d.url + '?token=' + encodeURIComponent(d.token));
            })
            .catch(function (err) {
                log('xin token thất bại:', err && err.message);
                // Phiên chết hẳn thì ngừng thử: người dùng sẽ bị đá về trang đăng
                // nhập ở request tiếp theo, gọi lại chỉ tốn request vô ích.
                if (err && err.message === 'session-expired') { setMode('off'); return; }
                failed();
            });
    }

    function openStream(url) {
        if (es) es.close();
        log('mở EventSource', url.replace(/token=[^&]*/, 'token=***'));

        try {
            es = new EventSource(url);
        } catch (e) {
            log('trình duyệt không hỗ trợ EventSource', e);
            startPolling();
            return;
        }

        es.addEventListener('ready', function () {
            retry = 0;
            everConnected = true;
            stopPolling();
            setMode('live');
            // Nối lại sau khi rớt mạng: tải lại danh sách để lấy những gì đã lỡ
            // trong lúc mất kết nối (sự kiện SSE không được phát lại).
            load(true);
        });

        es.addEventListener('notification', function (ev) {
            var n = safeParse(ev.data);
            if (!n) return;
            log('thông báo mới', n.title);
            if (prepend(n)) announce(n);
        });

        es.addEventListener('order', function (ev) {
            var o = safeParse(ev.data);
            log('đơn hàng đổi', o && o.order_code);
            // Trang nào quan tâm tới đơn hàng thì tự đăng ký hàm này (xem
            // orders/index.blade.php). Trang khác bỏ qua, chỉ chuông là đủ.
            if (o && typeof window.onOrderRealtime === 'function') window.onOrderRealtime(o);
        });

        es.addEventListener('return', function (ev) {
            var r = safeParse(ev.data);
            log('phiếu trả hàng đổi', r && r.return_code);
            // Tách khỏi sự kiện 'order' để trang Đơn hàng không phải tải lại vì một
            // phiếu trả, và ngược lại (xem returns/index.blade.php).
            if (r && typeof window.onReturnRealtime === 'function') window.onReturnRealtime(r);
        });

        es.onerror = function () {
            log('luồng đứt (readyState=' + (es && es.readyState) + ')');
            // EventSource tự nối lại được, nhưng token có hạn nên phải đi xin token
            // mới — đóng hẳn rồi tự quản lý vòng thử lại.
            if (es) { es.close(); es = null; }
            failed();
        };
    }

    /** Một lần thử hỏng: nối lại, hoặc chịu thua và chuyển sang hỏi định kỳ. */
    function failed() {
        retry += 1;
        // Đã từng nối được thì đây chỉ là rớt mạng tạm — cứ thử lại mãi.
        // Chưa nối được lần nào sau vài lần thử thì môi trường này chặn SSE thật.
        if (!everConnected && retry >= MAX_SSE_TRIES) {
            log('không nối được luồng sau ' + retry + ' lần — chuyển sang hỏi định kỳ');
            startPolling();
            return;
        }
        setMode('off');
        clearTimeout(retryTimer);
        // Giãn dần 2s → 30s: API tắt vài phút cũng không thành cơn bão request.
        retryTimer = setTimeout(connect, Math.min(2000 * Math.pow(2, Math.min(retry, 5) - 1), 30000));
    }

    // ---------- Dự phòng: hỏi định kỳ ----------

    function startPolling() {
        setMode('poll');
        if (pollTimer) return;
        pollTimer = setInterval(function () {
            // Tab đang ẩn thì không hỏi: người dùng không nhìn, để dành request.
            if (document.visibilityState === 'visible') load(false);
        }, POLL_INTERVAL);
        // Thỉnh thoảng thử lại SSE — mạng có thể chỉ chập một lúc.
        clearTimeout(retryTimer);
        retryTimer = setTimeout(function () { retry = 0; connect(); }, 60000);
    }

    function stopPolling() {
        if (!pollTimer) return;
        clearInterval(pollTimer);
        pollTimer = null;
    }

    // Tab bị ẩn lâu có thể bị trình duyệt cắt kết nối — quay lại thì nối lại ngay
    // thay vì đợi hết chu kỳ thử lại.
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState !== 'visible') return;
        load(false);
        if (!es && !pollTimer) { retry = 0; connect(); }
    });

    setMode('off');
    load(true);
    connect();
})();
