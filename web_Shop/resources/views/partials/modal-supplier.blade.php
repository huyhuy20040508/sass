{{--
    Hộp thoại THÊM / SỬA NHÀ CUNG CẤP — dùng chung.

    Trang Nhà cung cấp và ô "+" trong hộp lập Phiếu mua hàng phải mở ra ĐÚNG
    một hộp thoại: cùng ô, cùng thứ tự, cùng kiểu dáng. Chép làm hai bản là
    hai bản ấy lệch nhau ngay lần sửa đầu tiên, và người dùng gặp hai màn
    "thêm nhà cung cấp" khác nhau trong cùng một phần mềm.

    Hai trang khác nhau ở chỗ GỬI ĐI, không phải ở chỗ nhìn thấy:
      - trang Nhà cung cấp để form POST thẳng rồi quay lại danh sách;
      - hộp lập phiếu chặn submit, gọi API rồi nhét bên vừa khai vào ô chọn.

    Kiểu dáng đi kèm ngay dưới đây thay vì nằm ở layout: chỉ hai trang này
    dùng, nhét vào layout là mọi trang khác cùng gánh.
--}}
    <div class="ncc-overlay" id="nccFormOverlay" style="display:none;">
        <div class="ncc-dialog">
            <div class="ncc-modal-head">
                <h4 class="ncc-modal-title" id="nccFormTitle">Thêm nhà cung cấp</h4>
                <button type="button" class="ncc-modal-x" data-close>
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </div>

            <form id="nccForm" method="POST" action="{{ route('admin.nha-cung-cap.store') }}">
                @csrf
                <input type="hidden" name="_method" id="nccFormMethod" value="POST">
                <input type="hidden" name="return" value="{{ request()->getRequestUri() }}">
                {{-- Lưu hỏng thì lượt mở lại đọc ô này để biết đang sửa ai. --}}
                <input type="hidden" name="id" id="nccId" value="">

                <div class="ncc-modal-body">
                    <div class="ncc-form-cols">
                        <div class="ncc-col-anh">
                            <label class="ncc-field-label">Hình ảnh</label>
                            <div class="ncc-anh-khung" id="nccAnhKhung">
                                <img id="nccAnhXem" alt="" style="display:none;">
                                <span class="ncc-anh-chu" id="nccAnhChu">Chưa có ảnh</span>
                            </div>
                            <input type="hidden" name="image" id="nccAnh" value="">
                            <label class="ncc-anh-nut">
                                <span id="nccAnhNutChu">Chọn ảnh</span>
                                <input type="file" id="nccAnhFile" accept="image/*" hidden>
                            </label>
                            <button type="button" class="ncc-anh-go" id="nccAnhGo" style="display:none;">Gỡ ảnh</button>

                            <div class="ncc-status-box">
                                <label class="ncc-field-label">Trạng thái</label>
                                <div class="ncc-switch-row">
                                    <button type="button" class="ncc-switch on" id="nccTrangThai"
                                            aria-pressed="true" title="Bấm để đổi trạng thái">
                                        <span class="ncc-switch-knob"></span>
                                    </button>
                                    <span class="ncc-switch-label" id="nccTrangThaiChu">Đang hợp tác</span>
                                </div>
                                <input type="hidden" name="status" id="nccTrangThaiValue" value="1">
                                <p class="ncc-hint">Tắt đi là ngừng hợp tác — bên này không còn hiện trong ô chọn khi lập phiếu.</p>
                            </div>
                        </div>

                        <div class="ncc-col-o">
                            <div class="ncc-form-grid">
                                <div class="ncc-field">
                                    <label class="ncc-field-label" for="nccMa">Mã nhà cung cấp</label>
                                    <input type="text" id="nccMa" name="code" class="ncc-input" maxlength="30"
                                           autocomplete="off" placeholder="Bỏ trống để tự sinh theo quy tắc mã">
                                </div>
                                <div class="ncc-field">
                                    <label class="ncc-field-label" for="nccTen">Tên nhà cung cấp <span class="ncc-req">*</span></label>
                                    <input type="text" id="nccTen" name="name" class="ncc-input" maxlength="150" required
                                           autocomplete="off" placeholder="VD: Công ty TNHH Thực phẩm An Bình">
                                </div>
                                <div class="ncc-field">
                                    <label class="ncc-field-label" for="nccTenTat">Tên viết tắt</label>
                                    <input type="text" id="nccTenTat" name="short_name" class="ncc-input" maxlength="100"
                                           autocomplete="off" placeholder="VD: An Bình">
                                </div>
                                <div class="ncc-field">
                                    <label class="ncc-field-label" for="nccDienThoai">Điện thoại</label>
                                    <input type="text" id="nccDienThoai" name="phone" class="ncc-input" maxlength="20"
                                           autocomplete="off" placeholder="09xxxxxxxx">
                                </div>
                                <div class="ncc-field">
                                    <label class="ncc-field-label" for="nccEmail">Email</label>
                                    <input type="email" id="nccEmail" name="email" class="ncc-input" maxlength="191"
                                           autocomplete="off" placeholder="email@congty.vn">
                                </div>
                                <div class="ncc-field">
                                    <label class="ncc-field-label" for="nccMST">Mã số thuế</label>
                                    <input type="text" id="nccMST" name="tax_code" class="ncc-input" maxlength="30"
                                           autocomplete="off" placeholder="Cần khi lấy hoá đơn VAT">
                                </div>
                                <div class="ncc-field">
                                    <label class="ncc-field-label" for="nccDaiDien">Người đại diện</label>
                                    <input type="text" id="nccDaiDien" name="representative_name" class="ncc-input" maxlength="150"
                                           autocomplete="off" placeholder="VD: Anh Tuấn — phụ trách kinh doanh">
                                </div>
                                <div class="ncc-field">
                                    <label class="ncc-field-label" for="nccDaiDienSDT">SĐT người đại diện</label>
                                    <input type="text" id="nccDaiDienSDT" name="representative_phone" class="ncc-input" maxlength="20"
                                           autocomplete="off" placeholder="09xxxxxxxx">
                                </div>

                                <div class="ncc-field is-full">
                                    <label class="ncc-field-label" for="nccDiaChi">Địa chỉ <span class="ncc-req">*</span></label>
                                    <textarea id="nccDiaChi" name="address" class="ncc-textarea" rows="2" maxlength="255"
                                              placeholder="Số nhà, đường, phường/xã, tỉnh/thành"></textarea>
                                </div>
                                <div class="ncc-field is-full">
                                    <label class="ncc-field-label" for="nccDiaChi2">Địa chỉ 2</label>
                                    <textarea id="nccDiaChi2" name="address_line2" class="ncc-textarea" rows="2" maxlength="200"
                                              placeholder="Kho hàng, chi nhánh giao dịch khác…"></textarea>
                                </div>
                                <div class="ncc-field is-full">
                                    <label class="ncc-field-label" for="nccGhiChu">Ghi chú</label>
                                    <textarea id="nccGhiChu" name="note" class="ncc-textarea" rows="2" maxlength="500"
                                              placeholder="VD: giao hàng 3-5 ngày, chiết khấu 5% từ 50 thùng"></textarea>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="ncc-modal-foot">
                    <button type="button" class="ncc-btn-ghost" data-close>Đóng</button>
                    <button type="submit" class="ncc-btn-primary" id="nccFormSubmit">Lưu</button>
                </div>
            </form>
        </div>
    </div>


<style>
        /* Nút chung */
        .ncc-btn-primary, .ncc-btn-ghost, .ncc-btn-danger {
            height: 34px; display: inline-flex; align-items: center; justify-content: center; gap: 6px;
            padding: 0 14px; border-radius: 4px; font-size: 13px; font-weight: 500; cursor: pointer;
            border: 1px solid transparent; text-decoration: none; transition: background .15s, border-color .15s, color .15s;
        }
        .ncc-btn-primary { background: #1890ff; color: #fff; }
        .ncc-btn-primary:hover:not([disabled]) { background: #0f7ae5; }
        .ncc-btn-ghost { background: #fff; border-color: #d9d9d9; color: #262626; }
        .ncc-btn-ghost:hover:not([disabled]) { border-color: #1890ff; color: #1890ff; }
        .ncc-btn-danger { background: #fff; border-color: #ffa39e; color: #ff4d4f; }
        .ncc-btn-danger:hover:not([disabled]) { background: #ff4d4f; border-color: #ff4d4f; color: #fff; }

        /* Công tắc trạng thái ngoài bảng */
        .ncc-switch {
            position: relative; display: inline-flex; align-items: center; height: 22px; width: 42px; border: 0;
            border-radius: 9999px; background: #ddd; cursor: pointer; padding: 0; transition: background .15s;
        }
        .ncc-switch.on { background: #7083b6; }
        .ncc-switch-knob {
            display: inline-block; height: 16px; width: 16px; border-radius: 50%; background: #fff;
            box-shadow: 0 0 3px rgba(0,0,0,.3); transform: translateX(3px); transition: transform .15s;
        }
        .ncc-switch.on .ncc-switch-knob { transform: translateX(23px); }
        .ncc-switch[disabled] { cursor: default; opacity: .75; }

        /* Modal */
        .ncc-overlay {
            position: fixed; inset: 0; z-index: 1055; background: rgba(0, 0, 0, .45);
            display: flex; align-items: center; justify-content: center; padding: 16px;
        }
        .ncc-dialog {
            width: 100%; max-width: 940px; max-height: 92vh; overflow-y: auto; background: #fff;
            border-radius: 10px; box-shadow: 0 12px 40px rgba(0, 0, 0, .2); scrollbar-width: thin;
        }
        .ncc-dialog-lg { max-width: 1080px; }
        .ncc-dialog-sm { max-width: 520px; }
        .ncc-modal-head {
            position: sticky; top: 0; z-index: 2; background: #fff;
            display: flex; align-items: center; justify-content: space-between; gap: 12px;
            padding: 14px 20px; border-bottom: 1px solid #f0f0f0;
        }
        .ncc-modal-title { margin: 0; font-size: 15px; font-weight: 700; }
        .ncc-modal-x { border: 0; background: none; color: #8c8c8c; cursor: pointer; padding: 4px; border-radius: 4px; }
        .ncc-modal-x:hover { background: #f5f5f5; color: #262626; }
        .ncc-modal-body { padding: 16px 20px; display: flex; flex-direction: column; gap: 16px; }
        /* Hàng nút chân hộp thoại luôn canh giữa */
        .ncc-modal-foot {
            position: sticky; bottom: 0; z-index: 2; display: flex; align-items: center; justify-content: center;
            gap: 12px; flex-wrap: wrap; padding: 12px 20px; border-top: 1px solid #f0f0f0; background: #fafafa;
        }

        /* Form */
        .ncc-form-cols { display: grid; grid-template-columns: 220px 1fr; gap: 20px; }
        @media (max-width: 720px) { .ncc-form-cols { grid-template-columns: 1fr; } }
        .ncc-col-anh { display: flex; flex-direction: column; gap: 8px; }
        .ncc-form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px 16px; }
        @media (max-width: 720px) { .ncc-form-grid { grid-template-columns: 1fr; } }
        .ncc-field { display: flex; flex-direction: column; gap: 4px; min-width: 0; }
        .ncc-field.is-full { grid-column: 1 / -1; }
        .ncc-field-label { font-size: 12px; font-weight: 600; color: #595959; }
        .ncc-input, .ncc-textarea {
            border: 1px solid #d9d9d9; border-radius: 4px; padding: 0 10px; height: 34px;
            font-size: 13px; color: #262626; outline: none; background: #fff; width: 100%;
        }
        .ncc-textarea { height: auto; padding: 8px 10px; resize: vertical; font-family: inherit; }
        .ncc-input:focus, .ncc-textarea:focus { border-color: #86b7fe; box-shadow: 0 0 0 .2rem rgba(13, 110, 253, .2); }
        .ncc-hint { margin: 0; font-size: 12px; color: #8c8c8c; }
        .ncc-req { color: #ff4d4f; }

        .ncc-anh-khung {
            width: 100%; aspect-ratio: 1 / 1; border: 1px dashed #d9d9d9; border-radius: 8px; background: #fafafa;
            display: flex; align-items: center; justify-content: center; overflow: hidden;
        }
        .ncc-anh-khung img { width: 100%; height: 100%; object-fit: cover; }
        .ncc-anh-khung.is-view { max-width: 180px; }
        .ncc-anh-chu { font-size: 12px; color: #bfbfbf; }
        .ncc-anh-nut {
            height: 32px; display: inline-flex; align-items: center; justify-content: center; border: 1px solid #d9d9d9;
            border-radius: 4px; background: #fff; font-size: 13px; color: #262626; cursor: pointer;
        }
        .ncc-anh-nut:hover { border-color: #1890ff; color: #1890ff; }
        .ncc-anh-go { border: 0; background: none; font-size: 12px; color: #ff4d4f; cursor: pointer; padding: 0; }
        .ncc-status-box { margin-top: 4px; display: flex; flex-direction: column; gap: 4px; }
        .ncc-switch-row { display: flex; align-items: center; gap: 8px; margin: 0; }
        .ncc-switch-label { font-size: 13px; }
</style>
