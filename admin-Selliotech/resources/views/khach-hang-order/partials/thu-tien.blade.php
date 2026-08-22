{{--
    Hộp thoại "Thu tiền" — ghi MỘT LẦN TIỀN VÀO sổ thu.

    KHÔNG đẩy hạn hợp đồng. Đó là nút "Gia hạn", và hai việc tách rời cố ý:
      · gộp lại thì mỗi lần gia hạn báo một khoản doanh thu chưa ai trả;
      · và khách trả trước mà chưa muốn đẩy hạn thì không ghi vào đâu được.

    CHU KỲ KHÔNG CÓ Ô NÀO trong hộp này, dù API vẫn nhận. Máy chủ tự tính: từ
    điểm cuối của kỳ đã thu gần nhất (hoặc ngày bắt đầu hợp đồng nếu chưa thu lần
    nào) tới hạn hiện tại. Nhờ vậy hai lần thu liên tiếp không chồng lấn và cũng
    không để hở khoảng nào — mà đó chính là thứ một ô nhập tay sẽ phá vỡ ngay lần
    đầu có người gõ đại một ngày. Cần thu cho kỳ khác thì dùng
    `cmd/thue-bao thu-tien --ky-tu ... --ky-den ...`.

    Một hợp đồng · một chu kỳ · một lần thu: ghi trùng kỳ sẽ bị API từ chối (409),
    vì đó là cách dễ nhất để doanh thu tháng đó phồng gấp đôi mà không ai thấy sai.

    MỘT hộp cho cả bảng — nút của từng dòng nạp id, tên khách và giá hợp đồng vào.
--}}
<dialog class="sheet" id="sheet-thutien" style="width:min(480px,calc(100vw - 32px))">
    <div class="sheet-head">
        <div style="flex:1">
            <h3>Ghi nhận thu tiền</h3>
            <p><strong data-nap="ten">—</strong></p>
        </div>
        <button type="button" class="sheet-x" data-dong aria-label="Đóng">
            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor"
                 stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M18 6 6 18M6 6l12 12"/>
            </svg>
        </button>
    </div>

    <form method="POST" data-mau="{{ route('platform.khach-hang-order.hop-dong.thu-tien', ['hopDong' => '__ID__']) }}">
        @csrf

        <div class="sheet-body">
            <div class="grid2">
                <div>
                    <label class="f-label" for="tt-tien">Số tiền <span class="req">*</span></label>
                    {{-- Điền sẵn ĐÚNG GIÁ HỢP ĐỒNG (nút của dòng nạp vào qua
                         data-gia), không phải giá bảng giá hiện hành: khách trả
                         theo thứ đã ký. Sửa được khi thu thiếu, thu gộp, hoặc có
                         chiết khấu một lần — con số ở đây là tiền THẬT đã nhận. --}}
                    <input type="number" class="form-control mono @error('so_tien') is-bad @enderror"
                           id="tt-tien" name="so_tien" data-nap="gia" min="1" step="1000" required>
                    @error('so_tien') <p class="field-bad">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="f-label" for="tt-hinhthuc">Hình thức</label>
                    <select class="form-select" id="tt-hinhthuc" name="hinh_thuc">
                        <option value="chuyen_khoan">Chuyển khoản</option>
                        <option value="tien_mat">Tiền mặt</option>
                        <option value="khac">Khác</option>
                    </select>
                </div>

                <div class="rong">
                    <label class="f-label" for="tt-ma">Mã giao dịch</label>
                    <input type="text" class="form-control mono @error('ma_giao_dich') is-bad @enderror"
                           id="tt-ma" name="ma_giao_dich" maxlength="100"
                           placeholder="mã ngân hàng hoặc số phiếu thu" autocomplete="off">
                    @error('ma_giao_dich')
                        <p class="field-bad">{{ $message }}</p>
                    @else
                        <p class="hint">Thiếu nó thì lần thu này không đối chiếu ngược lại được với sao kê.</p>
                    @enderror
                </div>

                <div class="rong">
                    <label class="f-label" for="tt-ghichu">Ghi chú</label>
                    <input type="text" class="form-control" id="tt-ghichu" name="ghi_chu"
                           maxlength="500" autocomplete="off">
                </div>
            </div>

            <p class="sheet-note">
                Chỉ ghi nhận <strong>tiền</strong> — <strong>không</strong> đẩy hạn hợp đồng.
                Muốn dài thêm hạn thì bấm Gia hạn. Chu kỳ được trả cho do máy chủ tính nối tiếp
                lần thu gần nhất.
            </p>
        </div>

        <div class="sheet-foot">
            <button type="button" class="btn-ghost" data-dong>Đóng</button>
            <button type="submit" class="btn btn-plum">Ghi vào sổ thu</button>
        </div>
    </form>
</dialog>
