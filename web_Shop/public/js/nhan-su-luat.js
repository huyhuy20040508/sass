/**
 * Luật của màn hình Nhân sự — phần KHÔNG đụng tới DOM.
 *
 * Tách ra khỏi trang vì một lý do: mọi thứ nằm trong thẻ <script> của Blade đều
 * không có cách nào kiểm được. Cả module có 114 bài kiểm PHP và 15 bài kiểm API,
 * nhưng đúng mấy luật hay sai nhất lại nằm trong JavaScript và chưa ai chạm tới:
 * ô nào bị khoá khi tick "Cả ngày", ô tick nào ứng với cửa nào.
 *
 * Ở đây chỉ có hàm THUẦN: nhận dữ liệu, trả dữ liệu. Trang vẫn giữ phần gắn sự
 * kiện và sờ vào DOM — thứ đó cần trình duyệt thật mới kiểm được, còn phần dưới
 * đây thì `node --test` chạy trong nửa giây.
 *
 * Nạp bằng thẻ <script> thường (không phải module) nên nó gắn vào biến toàn cục;
 * node đọc lại qua cùng biến ấy.
 */
(function (g) {
    'use strict';

    /** Ca trực — khớp SET `employees.work_shift` và NhanSuController::CA_LAM. */
    var CA_NGAY = 'ca_ngay';

    var Luat = {
        CA_NGAY: CA_NGAY,

        /**
         * Tick "Cả ngày" thì khoá Sáng/Chiều, và ngược lại.
         *
         * Nhận danh sách {value, checked}, trả về {value: đang-bị-khoá}. KHÔNG
         * nhận phần tử DOM: hàm này trả lời một câu hỏi về dữ liệu, còn việc ghi
         * `disabled` hay gắn nhãn mờ là chuyện của trang.
         *
         * Vì sao loại trừ: "Cả ngày" ĐÃ GỒM sáng và chiều. Để lọt thì cột chứa
         * "sang,ca_ngay" — một chuỗi không trả lời được câu hỏi đơn giản nhất của
         * bảng chấm công: người này trực mấy buổi?
         */
        caLamBiKhoa: function (dsCa) {
            var chonCaNgay = false;
            var chonBuoi = false;

            (dsCa || []).forEach(function (o) {
                if (!o || !o.checked) {
                    return;
                }
                if (o.value === CA_NGAY) {
                    chonCaNgay = true;
                } else {
                    chonBuoi = true;
                }
            });

            var ra = {};
            (dsCa || []).forEach(function (o) {
                if (!o) {
                    return;
                }
                ra[o.value] = o.value === CA_NGAY ? chonBuoi : chonCaNgay;
            });

            return ra;
        },

        /**
         * Ô tick nào nên tick, cho một hồ sơ đang mở.
         *
         * `cuaDaLuu` là danh sách cửa API trả về (users.access_areas). `banDoCua`
         * map giá trị-ô-tick -> mã cửa, chính là NhanSuController::CUA.
         *
         * Đọc từ cửa ĐÃ LƯU chứ không suy từ vai trò — đó là cả bài học của lượt
         * sửa trước: suy từ `role_id` thì người chỉ được tích "Quản lý" lại hiện
         * thêm ô "Thu ngân" mà chủ tiệm chưa từng tích.
         */
        oQuyenNenTick: function (cuaDaLuu, banDoCua) {
            var co = {};
            (cuaDaLuu || []).forEach(function (c) { co[c] = true; });

            var ra = {};
            Object.keys(banDoCua || {}).forEach(function (giaTriO) {
                ra[giaTriO] = !!co[banDoCua[giaTriO]];
            });

            return ra;
        },

        /** Cột SET đọc lên là "sang,chieu" — tách thành danh sách, bỏ phần rỗng. */
        tachCa: function (chuoi) {
            return String(chuoi || '').split(',').filter(Boolean);
        },
    };

    g.NhanSuLuat = Luat;
})(typeof globalThis !== 'undefined' ? globalThis : this);
