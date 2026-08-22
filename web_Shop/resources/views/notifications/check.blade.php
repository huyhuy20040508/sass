@extends('layouts.app')

@section('title', 'Kiểm tra realtime')

@section('content')
    {{--
    Trang tự chẩn đoán đường truyền realtime.

    Chạy lần lượt đúng các bước mà chuông thông báo vẫn làm, và in kết quả từng
    bước ra màn hình — để khi realtime "im lặng không chạy" thì biết ngay nó gãy ở
    khâu nào thay vì phải mò trong console.
    --}}
    <div class="rtc">
        <div class="rtc-head">
            <h1 class="rtc-title">Kiểm tra realtime</h1>
            <p class="rtc-sub">
                Trang này chạy đúng các bước mà chuông thông báo vẫn làm, và in kết quả từng bước.
                Bước nào đỏ chính là chỗ hỏng.
            </p>
        </div>

        <div class="rtc-grid">
            {{-- Cấu hình đang dùng --}}
            <section class="rtc-card">
                <h3 class="rtc-card__title">1. Cấu hình</h3>
                <dl class="rtc-kv">
                    <dt>Trang quản trị đang mở tại</dt>
                    <dd><code>{{ $appOrigin }}</code></dd>
                    <dt>Trình duyệt sẽ nối luồng tới</dt>
                    <dd><code>{{ $streamUrl }}</code></dd>
                </dl>
                <p class="rtc-note">
                    Hai dòng trên khác host/cổng nhau là chuyện bình thường, nhưng địa chỉ
                    <b>{{ $appOrigin }}</b> bắt buộc phải nằm trong <code>CORS_ALLOW_ORIGINS</code>
                    của <code>api/.env</code>, nếu không trình duyệt sẽ chặn kết nối.
                </p>
            </section>

            {{-- Các bước kiểm tra --}}
            <section class="rtc-card">
                <h3 class="rtc-card__title">2. Các bước</h3>
                <ol class="rtc-steps" id="rtcSteps">
                    <li data-step="token"><span class="rtc-dot"></span><b>Xin access token</b><span class="rtc-msg">đang chạy…</span></li>
                    <li data-step="open"><span class="rtc-dot"></span><b>Mở luồng EventSource</b><span class="rtc-msg">chờ bước trên</span></li>
                    <li data-step="ready"><span class="rtc-dot"></span><b>Nhận tín hiệu sẵn sàng</b><span class="rtc-msg">chờ bước trên</span></li>
                    <li data-step="event"><span class="rtc-dot"></span><b>Nhận được sự kiện thật</b><span class="rtc-msg">bấm nút bên dưới để thử</span></li>
                </ol>

                <div class="rtc-actions">
                    <button type="button" class="rtc-btn rtc-btn--primary" id="rtcTest">Bắn một thông báo thử</button>
                    <button type="button" class="rtc-btn" id="rtcRetry">Nối lại từ đầu</button>
                </div>
            </section>
        </div>

        {{-- Nhật ký --}}
        <section class="rtc-card">
            <h3 class="rtc-card__title">3. Nhật ký</h3>
            <pre class="rtc-log" id="rtcLog"></pre>
        </section>
    </div>

    <style>
        .rtc { font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
        .rtc-head { margin-bottom: 16px; }
        .rtc-title { margin: 0 0 4px; font-size: 20px; font-weight: 700; color: #1e293b; }
        .rtc-sub { margin: 0; font-size: 13px; color: #64748b; }

        .rtc-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px; }
        @media (max-width: 900px) { .rtc-grid { grid-template-columns: 1fr; } }

        .rtc-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 16px 18px; }
        .rtc-card + .rtc-card { margin-top: 0; }
        .rtc-card__title { margin: 0 0 12px; font-size: 14px; font-weight: 700; color: #334155; }

        .rtc-kv { margin: 0; display: grid; grid-template-columns: auto; gap: 2px; }
        .rtc-kv dt { font-size: 12px; color: #64748b; }
        .rtc-kv dd { margin: 0 0 10px; }
        .rtc-kv code, .rtc-note code {
            font-size: 12.5px; background: #f1f5f9; border-radius: 4px; padding: 2px 6px; color: #0f172a;
            word-break: break-all;
        }
        .rtc-note { margin: 4px 0 0; font-size: 12.5px; color: #64748b; line-height: 1.55; }

        .rtc-steps { list-style: none; margin: 0; padding: 0; }
        .rtc-steps li {
            display: flex; align-items: center; gap: 8px;
            padding: 9px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; color: #334155;
        }
        .rtc-steps li:last-child { border-bottom: 0; }
        .rtc-steps b { font-weight: 600; }
        .rtc-msg { margin-left: auto; font-size: 12px; color: #94a3b8; text-align: right; }

        /* Chấm trạng thái: xám = chờ, xanh = xong, đỏ = hỏng */
        .rtc-dot { width: 9px; height: 9px; border-radius: 9999px; background: #cbd5e1; flex-shrink: 0; }
        .rtc-steps li.is-ok .rtc-dot { background: #22c55e; }
        .rtc-steps li.is-ok .rtc-msg { color: #15803d; }
        .rtc-steps li.is-bad .rtc-dot { background: #ef4444; }
        .rtc-steps li.is-bad .rtc-msg { color: #b91c1c; font-weight: 600; }

        .rtc-actions { display: flex; gap: 8px; margin-top: 14px; flex-wrap: wrap; }
        .rtc-btn {
            height: 34px; padding: 0 14px; border: 1px solid #e2e8f0; border-radius: 6px;
            background: #fff; color: #334155; font-size: 13px; font-weight: 500; cursor: pointer;
            font-family: inherit; transition: border-color .15s, background .15s;
        }
        .rtc-btn:hover { border-color: #94a3b8; background: #f8fafc; }
        .rtc-btn--primary { background: #1890ff; border-color: #1890ff; color: #fff; }
        .rtc-btn--primary:hover { background: #0d7ae0; border-color: #0d7ae0; }

        .rtc-log {
            margin: 0; max-height: 320px; overflow: auto;
            background: #0f172a; color: #cbd5e1; border-radius: 8px; padding: 12px 14px;
            font-size: 12px; line-height: 1.65; white-space: pre-wrap; word-break: break-word;
        }
    </style>

    <script>
        (function () {
            var STREAM_TOKEN_URL = @json(route('admin.notifications.streamToken'));
            var TEST_URL = @json(route('admin.notifications.test'));
            var CSRF = @json(csrf_token());

            var $log = document.getElementById('rtcLog');
            var $steps = document.getElementById('rtcSteps');
            var es = null;

            function log(msg) {
                var t = new Date().toLocaleTimeString('vi-VN');
                $log.textContent += '[' + t + '] ' + msg + '\n';
                $log.scrollTop = $log.scrollHeight;
            }

            function mark(step, state, msg) {
                var li = $steps.querySelector('[data-step="' + step + '"]');
                if (!li) return;
                li.classList.remove('is-ok', 'is-bad');
                if (state) li.classList.add(state === 'ok' ? 'is-ok' : 'is-bad');
                li.querySelector('.rtc-msg').textContent = msg;
            }

            function reset() {
                if (es) { es.close(); es = null; }
                ['token', 'open', 'ready'].forEach(function (s) { mark(s, null, 'chờ…'); });
                mark('event', null, 'bấm nút bên dưới để thử');
            }

            function start() {
                reset();
                log('--- bắt đầu kiểm tra ---');
                log('Bước 1: xin access token ở ' + STREAM_TOKEN_URL);

                fetch(STREAM_TOKEN_URL, { headers: { Accept: 'application/json' } })
                    .then(function (r) {
                        if (r.status === 401) throw new Error('Phiên đăng nhập đã hết hạn — hãy đăng nhập lại.');
                        if (!r.ok) throw new Error('Máy chủ trả về HTTP ' + r.status);
                        return r.json();
                    })
                    .then(function (d) {
                        if (!d.token) throw new Error('API không trả về token.');
                        if (!d.url) throw new Error('Thiếu cấu hình api.public_url trong web_Shop/config/api.php.');
                        mark('token', 'ok', 'lấy được token');
                        log('Bước 1 OK. Luồng sẽ mở tới: ' + d.url);
                        openStream(d.url + '?token=' + encodeURIComponent(d.token));
                    })
                    .catch(function (err) {
                        mark('token', 'bad', err.message);
                        log('Bước 1 HỎNG: ' + err.message);
                    });
            }

            function openStream(url) {
                if (!window.EventSource) {
                    mark('open', 'bad', 'trình duyệt không hỗ trợ');
                    log('Bước 2 HỎNG: trình duyệt này không có EventSource.');
                    return;
                }

                log('Bước 2: mở EventSource…');
                es = new EventSource(url);
                mark('open', 'ok', 'đã gọi EventSource');

                // Không nhận được "ready" trong 8 giây gần như chắc chắn là bị chặn
                // đường truyền (CORS, proxy, antivirus cắt kết nối giữ mở).
                var timer = setTimeout(function () {
                    if (es && es.readyState !== 1) {
                        mark('ready', 'bad', 'quá 8 giây không nối được');
                        log('Bước 3 HỎNG: không nhận được tín hiệu sẵn sàng.');
                        log('  → Kiểm tra CORS_ALLOW_ORIGINS trong api/.env có chứa ' + window.location.origin + ' chưa.');
                        log('  → Kiểm tra API Go có đang chạy và đã khởi động lại sau khi sửa code chưa.');
                        log('  → Mở tab Network, tìm request "events", xem nó báo gì.');
                    }
                }, 8000);

                es.addEventListener('ready', function (ev) {
                    clearTimeout(timer);
                    mark('ready', 'ok', 'đã nối');
                    log('Bước 3 OK. Kênh đang nghe: ' + ev.data);
                    log('Giờ bấm "Bắn một thông báo thử" — hoặc đặt một đơn thật ở storefront.');
                });

                es.addEventListener('notification', function (ev) {
                    mark('event', 'ok', 'nhận được thông báo');
                    log('NHẬN ĐƯỢC thông báo: ' + ev.data);
                });

                es.addEventListener('order', function (ev) {
                    mark('event', 'ok', 'nhận được sự kiện đơn hàng');
                    log('NHẬN ĐƯỢC sự kiện đơn hàng: ' + ev.data);
                });

                es.onerror = function () {
                    clearTimeout(timer);
                    // readyState 0 = đang thử nối lại, 2 = đã đóng hẳn.
                    var state = es ? es.readyState : -1;
                    mark('ready', 'bad', 'luồng đứt (readyState=' + state + ')');
                    log('LỖI: luồng đứt, readyState=' + state + '.');
                    log('  → readyState=0 nghĩa là trình duyệt đang tự thử nối lại.');
                    log('  → Nếu tab Network báo lỗi CORS thì thêm ' + window.location.origin + ' vào CORS_ALLOW_ORIGINS.');
                };
            }

            document.getElementById('rtcTest').addEventListener('click', function () {
                log('Gọi API bắn thông báo thử…');
                fetch(TEST_URL, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': CSRF, 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
                })
                    .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, status: r.status, d: d }; }); })
                    .then(function (res) {
                        if (!res.ok) {
                            log('Bắn thử THẤT BẠI (HTTP ' + res.status + '): ' + (res.d.message || ''));
                            log('  → Endpoint này chỉ mở khi APP_ENV khác production, và cần khởi động lại API sau khi cập nhật code.');
                            return;
                        }
                        var n = res.d.data || {};
                        log('API đã nhận lệnh. Số client đang nối luồng: ' + (n.clients != null ? n.clients : '?'));
                        if (n.clients === 0) {
                            log('  → CẢNH BÁO: API thấy 0 client đang nối. Luồng SSE của bạn chưa tới được API.');
                        }
                    })
                    .catch(function (e) { log('Bắn thử lỗi mạng: ' + e.message); });
            });

            document.getElementById('rtcRetry').addEventListener('click', start);

            start();
        })();
    </script>
@endsection
