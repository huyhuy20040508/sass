{{--
    Toast dùng chung: đọc flash `success` / `error` từ session và hiện ở góc phải.

    Cũng phơi ra window.saasToast(message, type) để mã JS sau này gọi lại được,
    khỏi mỗi trang tự dựng một kiểu thông báo riêng.
--}}
<div class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 1090"></div>

<script>
    (function () {
        var box = document.querySelector('.toast-container');

        var STYLE = {
            success: { cls: 'text-bg-success', icon: 'bi-check-circle' },
            error:   { cls: 'text-bg-danger',  icon: 'bi-exclamation-triangle' },
            info:    { cls: 'text-bg-dark',    icon: 'bi-info-circle' },
        };

        window.saasToast = function (message, type) {
            if (!box || !message) return;
            var s = STYLE[type] || STYLE.info;

            var el = document.createElement('div');
            el.className = 'toast align-items-center border-0 ' + s.cls;
            el.setAttribute('role', 'alert');

            var body = document.createElement('div');
            body.className = 'd-flex';

            var text = document.createElement('div');
            text.className = 'toast-body d-flex align-items-center gap-2';
            var icon = document.createElement('i');
            icon.className = 'bi ' + s.icon;
            text.appendChild(icon);
            // textContent, không innerHTML: nội dung có thể đến từ câu báo lỗi
            // của API, tức là chuỗi do bên ngoài quyết định.
            text.appendChild(document.createTextNode(message));

            var close = document.createElement('button');
            close.type = 'button';
            close.className = 'btn-close btn-close-white me-2 m-auto';
            close.setAttribute('data-bs-dismiss', 'toast');

            body.appendChild(text);
            body.appendChild(close);
            el.appendChild(body);
            box.appendChild(el);

            var t = new bootstrap.Toast(el, { delay: 4000 });
            el.addEventListener('hidden.bs.toast', function () { el.remove(); });
            t.show();
        };

        @if (session('success'))
            window.saasToast(@json(session('success')), 'success');
        @endif
        @if (session('error'))
            window.saasToast(@json(session('error')), 'error');
        @endif
    })();
</script>
