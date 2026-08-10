{{--
    Tự nạp lại trang khi SANG NGÀY MỚI — dùng chung cho Tổng quan và Báo cáo.

    Vì sao cần: hai trang này gần như luôn được mở suốt ngày trên một máy ở quầy.
    Qua nửa đêm mà không nạp lại thì tiêu đề vẫn ghi "hôm nay" trong khi con số là
    của ngày hôm trước — đọc nhầm mà không có dấu hiệu nào báo là đang nhầm.

    CHỈ nạp lại khi kỳ đang xem NEO vào hôm nay (bấm nút xem nhanh: Hôm nay, Hôm
    qua, 7/30/90 ngày). Người dùng tự chọn một khoảng cố định trên lịch thì qua
    ngày mới con số không đổi nghĩa, tự nạp lại lúc đó chỉ làm mất chỗ họ đang đọc.

    So sánh theo NGÀY ĐỊA PHƯƠNG của trình duyệt với ngày máy chủ đã dựng trang.
    Kèm cả mốc kiểm tra lúc tab được xem lại: máy ngủ qua đêm thì hẹn giờ không
    chạy đúng nhịp, mở nắp ra vẫn phải thấy số của ngày mới.

    Tham số:
      - date     : ngày máy chủ đã dựng trang (YYYY-MM-DD, bắt buộc)
      - anchored : kỳ đang xem có neo vào hôm nay không (bắt buộc)
--}}
@if($anchored)
    <script>
        (function () {
            var renderedOn = @json($date);

            function localDate() {
                var d = new Date();
                var m = String(d.getMonth() + 1).padStart(2, '0');
                var day = String(d.getDate()).padStart(2, '0');
                return d.getFullYear() + '-' + m + '-' + day;
            }

            var reloading = false;
            function check() {
                if (reloading || localDate() === renderedOn) return;
                reloading = true;   // chặn gọi chồng: nạp lại mất vài giây
                window.location.reload();
            }

            // Một phút một lần là đủ: sai lệch tối đa một phút quanh nửa đêm, mà
            // không tạo ra một vòng lặp chạy liên tục suốt ngày.
            setInterval(check, 60000);
            document.addEventListener('visibilitychange', function () {
                if (!document.hidden) check();
            });
            window.addEventListener('focus', check);
        })();
    </script>
@endif
