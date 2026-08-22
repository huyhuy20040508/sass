{{--
    JS dùng chung của NHÓM trang Báo cáo.

    Include ở CUỐI mỗi trang (cạnh partial style) — không nhét vào từng partial
    hình vẽ, vì trang nào cũng có thể chứa nhiều hình và một partial được include
    nhiều lần. Đặt ở cuối thì mọi phần tử đã có trong DOM, không cần chờ sự kiện.

    Ba việc, tất cả đều là "đọc lại số liệu đã có sẵn trên trang", không gọi API:
      1. Bộ lọc realtime: đổi select là submit ngay, không có nút "Áp dụng".
      2. Bật/tắt bảng số của một hình vẽ (data-table-toggle ↔ data-table).
      3. Biểu đồ đường: đổi tab chỉ số, rê chuột hiện đường dóng + tooltip.
--}}
@once
    <script>
        (function () {
            var money = function (v) { return (Number(v) || 0).toLocaleString('vi-VN') + '₫'; };
            var count = function (v) { return (Number(v) || 0).toLocaleString('vi-VN'); };

            // ---- 1. Bộ lọc realtime ----
            var form = document.getElementById('rpFilter');
            if (form) {
                form.querySelectorAll('select[data-auto-submit]').forEach(function (sel) {
                    sel.addEventListener('change', function () { form.submit(); });
                });
            }

            // ---- 2. Bảng số thay cho hình vẽ ----
            // Con số phải đọc và sao chép được, không khoá sau tooltip.
            document.querySelectorAll('[data-table-toggle]').forEach(function (btn) {
                var table = document.querySelector('[data-table="' + btn.dataset.tableToggle + '"]');
                if (!table) return;
                btn.addEventListener('click', function () {
                    var open = table.hidden;
                    table.hidden = !open;
                    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
                    btn.textContent = open ? 'Ẩn bảng' : 'Xem dạng bảng';
                });
            });

            // ---- 3a. Tab đổi chỉ số ----
            // Các đường đã dựng sẵn ở PHP, bấm tab chỉ đổi cái đang hiện.
            document.querySelectorAll('[data-chart-tabs]').forEach(function (box) {
                var id = box.dataset.chartTabs;
                var tabs = box.querySelectorAll('.rp-tabmini');
                var plots = document.querySelectorAll('.rp-plot[data-chart="' + id + '"]');
                tabs.forEach(function (tab) {
                    tab.addEventListener('click', function () {
                        tabs.forEach(function (t) {
                            var on = t === tab;
                            t.classList.toggle('is-active', on);
                            t.setAttribute('aria-selected', on ? 'true' : 'false');
                        });
                        plots.forEach(function (p) { p.hidden = p.dataset.metric !== tab.dataset.tab; });
                    });
                });
            });

            // ---- 3b. Rê chuột trên biểu đồ đường ----
            document.querySelectorAll('.rp-plot').forEach(function (box) {
                var pts, geo, meta;
                try {
                    pts = JSON.parse(box.dataset.points);
                    geo = JSON.parse(box.dataset.geo);
                    meta = JSON.parse(box.dataset.meta);
                } catch (e) { return; }
                if (!pts.length) return;

                var metric = box.dataset.metric;
                var svg = box.querySelector('.rp-svg');
                var cross = box.querySelector('.rp-cross');
                var dot = box.querySelector('.rp-dot-hover');
                var tip = box.querySelector('.rp-tip');
                var hit = box.querySelector('.rp-hit');
                var plotW = geo.w - geo.padL - geo.padR;

                function show(e) {
                    var r = svg.getBoundingClientRect();
                    var vx = ((e.clientX - r.left) / r.width) * geo.w;   // toạ độ trong viewBox
                    var i = Math.round(((vx - geo.padL) / plotW) * (pts.length - 1));
                    if (i < 0) i = 0;
                    if (i > pts.length - 1) i = pts.length - 1;

                    var p = pts[i];
                    var x = pts.length > 1 ? geo.padL + plotW * i / (pts.length - 1) : geo.padL + plotW / 2;
                    // Dùng ĐÚNG trần trục mà PHP đã làm tròn đẹp; tính lại từ giá trị
                    // lớn nhất sẽ ra trần khác và chấm lệch khỏi đường.
                    var y = geo.baseY - ((p.v[metric] || 0) / (geo.top || 1)) * (geo.baseY - geo.padT);

                    cross.setAttribute('x1', x); cross.setAttribute('x2', x); cross.hidden = false;
                    dot.setAttribute('cx', x); dot.setAttribute('cy', y); dot.hidden = false;

                    // Tooltip nói đủ MỌI chỉ số của mốc đó: rê một lần đọc được cả
                    // doanh thu lẫn số đơn, không phải đổi tab rồi rê lại.
                    var html = '<b>' + p.d + '</b>';
                    Object.keys(meta).forEach(function (k) {
                        var val = meta[k].money ? money(p.v[k]) : count(p.v[k]);
                        var line = meta[k].label + ': ' + val;
                        html += (k === metric ? line : '<span>' + line + '</span>') + '<br>';
                    });
                    tip.innerHTML = html;
                    tip.style.left = ((x / geo.w) * 100) + '%';
                    tip.style.top = ((y / geo.h) * 100) + '%';
                    tip.style.marginTop = '-12px';
                    tip.hidden = false;
                }

                function hide() { cross.hidden = true; dot.hidden = true; tip.hidden = true; }

                hit.addEventListener('mousemove', show);
                hit.addEventListener('mouseleave', hide);
                box.addEventListener('touchmove', function (e) {
                    if (e.touches && e.touches[0]) show(e.touches[0]);
                }, { passive: true });
            });
        })();
    </script>
@endonce
