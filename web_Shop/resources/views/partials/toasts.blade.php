{{--
    Toast dùng chung cho toàn hệ thống admin.
    - session('success')  → toast xanh
    - session('error')    → toast đỏ
    - lỗi validation ($errors) → mỗi lỗi 1 toast đỏ
    Hiển thị góc phải dưới màn hình, tự ẩn sau vài giây.
    Yêu cầu: đã nạp Bootstrap JS bundle + Bootstrap Icons.
--}}
<div class="toast-container position-fixed bottom-0 end-0 p-3" id="jhToastContainer" style="z-index: 1090">
    @if(session('success'))
        <div class="toast align-items-center text-bg-success border-0" role="alert"
             data-bs-delay="4000">
            <div class="d-flex">
                <div class="toast-body">
                    <i class="bi bi-check-circle-fill me-2"></i>{{ session('success') }}
                </div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto"
                        data-bs-dismiss="toast" aria-label="Đóng"></button>
            </div>
        </div>
    @endif

    @if(session('error'))
        <div class="toast align-items-center text-bg-danger border-0" role="alert"
             data-bs-delay="6000">
            <div class="d-flex">
                <div class="toast-body">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>{{ session('error') }}
                </div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto"
                        data-bs-dismiss="toast" aria-label="Đóng"></button>
            </div>
        </div>
    @endif

    @foreach($errors->all() as $message)
        <div class="toast align-items-center text-bg-danger border-0" role="alert"
             data-bs-delay="6000">
            <div class="d-flex">
                <div class="toast-body">
                    <i class="bi bi-exclamation-circle-fill me-2"></i>{{ $message }}
                </div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto"
                        data-bs-dismiss="toast" aria-label="Đóng"></button>
            </div>
        </div>
    @endforeach
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.toast-container .toast').forEach(function (el) {
            new bootstrap.Toast(el).show();
        });
    });

    /**
     * adminToast — bật một toast từ JavaScript, dùng chung toàn hệ thống.
     *
     * Các toast ở trên đến từ session (sau khi tải lại trang); hàm này dành cho
     * việc xảy ra NGAY trong lúc đang xem trang — hiện tại là thông báo realtime
     * (đơn mới về). Dùng lại đúng markup Bootstrap ở trên để hai nguồn toast trông
     * giống hệt nhau.
     *
     * Tham số opts: title (tiêu đề), body (mô tả), href (link "Xem ngay"),
     * tone (info | success | warning | danger), delay (mili-giây tự ẩn, mặc định 6000).
     */
    window.adminToast = function (opts) {
        opts = opts || {};
        var box = document.getElementById('jhToastContainer');
        if (!box || typeof bootstrap === 'undefined') return;

        var tones = {
            info: 'text-bg-primary', success: 'text-bg-success',
            warning: 'text-bg-warning', danger: 'text-bg-danger',
        };
        var el = document.createElement('div');
        el.className = 'toast align-items-center border-0 ' + (tones[opts.tone] || tones.info);
        el.setAttribute('role', 'alert');

        // Nội dung do server sinh nhưng vẫn gán qua textContent: tên người nhận và
        // ghi chú đơn là dữ liệu khách nhập, không được phép thành HTML ở đây.
        var wrap = document.createElement('div');
        wrap.className = 'd-flex';
        var body = document.createElement('div');
        body.className = 'toast-body';

        var strong = document.createElement('div');
        strong.className = 'fw-semibold';
        strong.textContent = opts.title || 'Thông báo';
        body.appendChild(strong);

        if (opts.body) {
            var small = document.createElement('div');
            small.className = 'small';
            small.textContent = opts.body;
            body.appendChild(small);
        }

        if (opts.href) {
            var link = document.createElement('a');
            link.href = opts.href;
            link.className = 'small text-white text-decoration-underline';
            link.textContent = 'Xem ngay';
            body.appendChild(link);
        }

        var close = document.createElement('button');
        close.type = 'button';
        close.className = 'btn-close btn-close-white me-2 m-auto';
        close.setAttribute('data-bs-dismiss', 'toast');
        close.setAttribute('aria-label', 'Đóng');

        wrap.appendChild(body);
        wrap.appendChild(close);
        el.appendChild(wrap);
        box.appendChild(el);

        var toast = new bootstrap.Toast(el, { delay: opts.delay || 6000 });
        el.addEventListener('hidden.bs.toast', function () { el.remove(); });
        toast.show();
    };
</script>
