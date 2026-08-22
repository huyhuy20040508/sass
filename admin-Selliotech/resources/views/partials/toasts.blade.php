{{--
    Toast dùng chung: đọc flash `success` / `error` từ session và hiện ở góc phải.

    Cũng phơi ra window.saasToast(message, type) để mã JS sau này gọi lại được,
    khỏi mỗi trang tự dựng một kiểu thông báo riêng.

    Kiểu nhìn tự chứa trong tệp này vì partial được nhúng cả ở trang đăng nhập
    (có bảng CSS riêng) lẫn trang trong. Các biến màu có giá trị dự phòng để
    trang nào chưa khai đủ vẫn hiện đúng.
--}}
<style>
    /* Góc phải DƯỚI. Góc phải trên là chỗ của nút tài khoản trên thanh trên —
       thông báo nhảy ra đúng đó thì che mất cái nút người ta vừa bấm.
       Hộp neo ở đáy nên chồng toast nở ngược lên trên. Để `column` (không phải
       `column-reverse`) thì cái vừa hiện nằm sát góc, mấy cái cũ bị đẩy lên —
       mắt đang nhìn góc dưới phải thì cái mới phải rơi đúng vào đó. */
    .toast-stack {
        position: fixed; bottom: 18px; right: 18px; z-index: 1090;
        display: flex; flex-direction: column; gap: 10px;
        max-width: min(380px, calc(100vw - 36px));
    }
    @media (max-width: 560px) {
        .toast-stack { bottom: 12px; right: 12px; left: 12px; max-width: none; }
    }
    /* Nền tím đậm cho cả ba loại, phân biệt bằng vạch màu bên trái. Ba nền màu
       đặc (xanh/đỏ/đen) của Bootstrap đè lên nhau khi hiện liền mấy cái. */
    .toast-x {
        display: flex; align-items: flex-start; gap: 12px;
        padding: 12px 12px 12px 14px;
        background: var(--plum-deep, #2A0D4B); color: #fff;
        border-left: 3px solid var(--ink-3, #8B8394);
        border-radius: 3px;
        box-shadow: 0 10px 30px rgba(27, 16, 39, .28);
        font-family: var(--font-body, sans-serif); font-size: 13.5px; line-height: 1.5;
    }
    .toast-x.is-success { border-left-color: var(--good, #1F7A5C); }
    .toast-x.is-error   { border-left-color: var(--bad, #B42318); }
    .toast-x p { margin: 0; flex: 1; }
    .toast-x button {
        border: 0; background: none; padding: 0 2px; line-height: 1;
        font-size: 18px; color: rgba(255, 255, 255, .55); cursor: pointer;
    }
    .toast-x button:hover { color: #fff; }
</style>

<div class="toast-stack" role="status" aria-live="polite"></div>

<script>
    (function () {
        var box = document.querySelector('.toast-stack');

        window.saasToast = function (message, type) {
            if (!box || !message) return;

            var el = document.createElement('div');
            el.className = 'toast-x ' + (type === 'success' ? 'is-success' : type === 'error' ? 'is-error' : '');

            var text = document.createElement('p');
            // textContent, không innerHTML: nội dung có thể đến từ câu báo lỗi
            // của API, tức là chuỗi do bên ngoài quyết định.
            text.textContent = message;

            var close = document.createElement('button');
            close.type = 'button';
            close.setAttribute('aria-label', 'Đóng thông báo');
            close.textContent = '×';
            close.addEventListener('click', function () { el.remove(); });

            el.appendChild(text);
            el.appendChild(close);
            box.appendChild(el);

            setTimeout(function () { el.remove(); }, 5000);
        };

        @if (session('success'))
            window.saasToast(@json(session('success')), 'success');
        @endif
        @if (session('error'))
            window.saasToast(@json(session('error')), 'error');
        @endif
    })();
</script>
