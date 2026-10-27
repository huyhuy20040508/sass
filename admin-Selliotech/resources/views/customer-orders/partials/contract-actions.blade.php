{{--
    Hai hộp thoại thao tác trên MỘT dòng của bảng hợp đồng: gia hạn và huỷ.

    Cùng khuôn với form "Thêm tài khoản dùng thử" và với form bên Shop Admin:
    đầu · thân · chân, nhãn chữ thường, nút canh giữa ở chân.

    MỘT hộp cho cả bảng, không phải một hộp cho mỗi dòng. Nút của từng dòng mang
    data-id / data-ten, và script chung trong hop-dong.blade.php nạp chúng vào
    hộp rồi mới mở — bảng 50 khách mà dựng 50 cặp hộp thì trang mang theo 100
    <form> chẳng cái nào được dùng.

    Đường gửi của form khai bằng mẫu có __ID__ trong data-mau: route của Laravel
    cần id nằm trong đường dẫn, mà id thì chỉ biết lúc bấm.

    $laDungThu chỉ đổi CÂU CHỮ, không đổi việc làm. Bên Go "gia hạn" và "chuyển
    sang chính thức" là cùng một đường: hạn được đẩy ra, trạng thái về `active`,
    mốc hết dùng thử bị xoá. Đặt tên khác nhau vì hai màn hình đang nói về hai
    tình huống khác nhau — không phải vì bên dưới có hai việc.
--}}

<dialog class="sheet" id="sheet-giahan" style="width:min(460px,calc(100vw - 32px))">
    <div class="sheet-head">
        <div style="flex:1">
            <h3>{{ $laDungThu ? 'Chuyển sang chính thức' : 'Gia hạn hợp đồng' }}</h3>
            <p><strong data-nap="ten">—</strong> · gói <span data-nap="goi">—</span></p>
        </div>
        <button type="button" class="sheet-x" data-dong aria-label="Đóng">
            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor"
                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M18 6 6 18M6 6l12 12"/>
            </svg>
        </button>
    </div>

    <form method="POST" data-mau="{{ route('platform.khach-hang-order.hop-dong.gia-han', ['hopDong' => '__ID__']) }}">
        @csrf

        <div class="sheet-body">
            <div>
                <label class="f-label" for="gh-thang">Gia hạn thêm <span class="req">*</span></label>
                {{-- Bộ chọn chứ không ô số tự do: bốn mốc dưới đây là toàn bộ những
                     lần gia hạn có thật, và một ô trống mời người ta gõ "120" vào rồi
                     tặng khách mười năm mà không màn hình nào báo động. --}}
                {{-- autofocus: thiếu nó thì <dialog> đặt tiêu điểm vào thứ bấm
                     được đầu tiên, tức cái nút ✕ — mở hộp ra mà Enter là đóng nó. --}}
                <select class="form-select" id="gh-thang" name="so_thang" required autofocus>
                    <option value="1">1 tháng</option>
                    <option value="3">3 tháng</option>
                    <option value="6">6 tháng</option>
                    <option value="12" selected>12 tháng</option>
                </select>
            </div>

            {{-- Hai câu DUY NHẤT còn lại trong hộp này, và cả hai đều nói thứ người
                 bấm không đoán ra được: mốc cộng hạn không phải ngày hết hạn cũ, và
                 nút này không đụng tới sổ thu. Tưởng "gia hạn" đã gồm ghi nhận tiền
                 thì sổ thu thiếu một khoản mà không ai đi tìm. --}}
            <p class="sheet-note">
                Hạn mới cộng từ ngày hết hạn <strong>hoặc hôm nay</strong>, lấy mốc muộn hơn.
                Việc này <strong>không</strong> ghi tiền vào sổ thu.
            </p>
        </div>

        <div class="sheet-foot">
            <button type="button" class="btn-ghost" data-dong>Đóng</button>
            <button type="submit" class="btn btn-plum">
                {{ $laDungThu ? 'Chuyển sang chính thức' : 'Gia hạn' }}
            </button>
        </div>
    </form>
</dialog>

<dialog class="sheet" id="sheet-huy" style="width:min(460px,calc(100vw - 32px))">
    <div class="sheet-head">
        <div style="flex:1">
            <h3>Huỷ hợp đồng</h3>
            <p><strong data-nap="ten">—</strong></p>
        </div>
        <button type="button" class="sheet-x" data-dong aria-label="Đóng">
            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor"
                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M18 6 6 18M6 6l12 12"/>
            </svg>
        </button>
    </div>

    <form method="POST" data-mau="{{ route('platform.khach-hang-order.hop-dong.huy', ['hopDong' => '__ID__']) }}">
        @csrf

        <div class="sheet-body">
            <div>
                <label class="f-label" for="huy-lydo">Lý do huỷ</label>
                <input type="text" class="form-control" id="huy-lydo" name="ly_do" maxlength="300"
                       placeholder="khách không tiếp tục" autocomplete="off" autofocus>
            </div>

            {{-- Câu này là toàn bộ thứ người bấm cần biết: cái gì mất và cái gì còn.
                 Không có bước xác nhận thứ hai — chính hộp này đã là bước xác nhận,
                 và hỏi hai lần thì lần thứ hai không ai đọc. --}}
            <p class="sheet-note is-bad">
                Dữ liệu của khách <strong>không</strong> bị xoá, nhưng họ sẽ không còn hợp đồng nào
                còn hiệu lực. Muốn dùng lại thì phải ký hợp đồng mới.
            </p>
        </div>

        <div class="sheet-foot">
            <button type="button" class="btn-ghost" data-dong>Đóng</button>
            {{-- Nút nguy hiểm vẫn đứng ở vị trí của nút chính: đổi chỗ nó đi cho
                 "an toàn" chỉ làm người ta bấm nhầm ở những hộp còn lại. Màu đỏ đã
                 là dấu hiệu. --}}
            <button type="submit" class="btn btn-plum" style="background:var(--bad);border-color:var(--bad)">
                Huỷ hợp đồng
            </button>
        </div>
    </form>
</dialog>
