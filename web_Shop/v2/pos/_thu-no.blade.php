{{--
    Hộp Thu nợ của quầy — dùng chung cho màn Lịch sử (đơn đang mở) và khung chi tiết
    khách (nút Thu từng đơn, "Thu nợ" đơn cũ nhất). Ghi qua thu-ngan.don-hang.thuNo:
    tiền mặt vào sổ quỹ của ca đang mở.

    window.posThuNo({ id, ma, tong_tien, da_thu, con_no }, xong) — xong(soTien, body)
    chạy khi API đã ghi lượt thu; hộp tự đóng trước đó. Kèm thu-no.css ở trang gọi.
--}}
<dialog class="modal tn-debt" id="hsDebt" aria-labelledby="hsDebtT" data-debt-url="{{ route('thu-ngan.don-hang.thuNo', ['id' => 0]) }}">
    <form method="dialog" data-debt-form>
        <div class="modal__head"><h2 id="hsDebtT">Thu nợ · <span data-debt-code></span></h2><button type="button" class="icon-btn" aria-label="Đóng" data-tn-close><i class="fa-solid fa-xmark"></i></button></div>
        <div class="modal__body hs-form">
            <div class="hs-sumbox">
                <div class="hs-kv"><span>Tổng đơn</span><b class="t-num" data-debt-total></b></div>
                <div class="hs-kv"><span>Đã thu</span><b class="t-num" data-debt-paid></b></div>
                <div class="hs-kv is-owe"><span>Còn nợ</span><b class="t-num" data-debt-owe></b></div>
            </div>
            <div class="field">
                <span class="label">Hình thức</span>
                <div class="hs-pm-pick" data-debt-pm>
                    <button type="button" aria-pressed="true" data-v="cash"><i class="fa-solid fa-money-bill-wave"></i>Tiền mặt</button>
                    <button type="button" aria-pressed="false" data-v="bank_transfer"><i class="fa-solid fa-building-columns"></i>Chuyển khoản</button>
                    <button type="button" aria-pressed="false" data-v="card"><i class="fa-solid fa-credit-card"></i>Quẹt thẻ</button>
                </div>
                <span class="hint">Tiền mặt vào sổ quỹ của ca đang mở.</span>
            </div>
            <div class="field">
                <label for="hsDebtAmt">Số tiền thu lần này</label>
                <input class="input t-num" id="hsDebtAmt" inputmode="numeric" autocomplete="off" data-debt-amt>
                <span class="hs-err" data-debt-err hidden></span>
            </div>
            <div class="field">
                <label for="hsDebtNote">Ghi chú</label>
                <input class="input" id="hsDebtNote" maxlength="255" autocomplete="off" placeholder="Không bắt buộc" data-debt-note>
            </div>
        </div>
        <div class="modal__foot">
            <button type="button" class="btn btn--ghost" data-tn-close>Huỷ</button>
            <button type="submit" class="btn btn--primary" data-debt-ok>Ghi lượt thu</button>
        </div>
    </form>
</dialog>
<script>
(function () {
    const d = document.getElementById('hsDebt');
    const $ = (s) => d.querySelector(s);
    const num = (n) => Math.round(Number(n) || 0).toLocaleString('vi-VN');
    const vnd = (n) => `${num(n)} đ`;
    const parseMoney = (s) => Number(String(s).replace(/\D/g, '')) || 0;
    const CSRF = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const chonPm = (v) => d.querySelectorAll('[data-debt-pm] button').forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.v === v)));
    let don = null;
    let xong = null;

    window.posThuNo = function (r, cb) {
        don = r;
        xong = cb;
        $('[data-debt-code]').textContent = r.ma;
        $('[data-debt-total]').textContent = vnd(r.tong_tien);
        $('[data-debt-paid]').textContent = vnd(r.da_thu);
        $('[data-debt-owe]').textContent = vnd(r.con_no);
        $('[data-debt-amt]').value = num(r.con_no);
        $('[data-debt-note]').value = '';
        $('[data-debt-err]').hidden = true;
        $('[data-debt-ok]').disabled = false;
        chonPm('cash');
        d.showModal();
        $('[data-debt-amt]').select();
    };

    $('[data-debt-pm]').addEventListener('click', (e) => {
        const b = e.target.closest('button');
        if (b) chonPm(b.dataset.v);
    });
    $('[data-debt-amt]').addEventListener('input', (e) => {
        const v = parseMoney(e.target.value);
        e.target.value = v ? num(v) : '';
        const owe = Number(don?.con_no) || 0;
        const err = $('[data-debt-err]');
        err.hidden = v > 0 && v <= owe;
        err.textContent = v > owe ? `Vượt số còn nợ (${vnd(owe)}).` : 'Nhập số tiền thu.';
        $('[data-debt-ok]').disabled = !err.hidden;
    });
    $('[data-debt-form]').addEventListener('submit', async (e) => {
        e.preventDefault();
        const r = don;
        const v = parseMoney($('[data-debt-amt]').value);
        if (!r || v <= 0 || v > Number(r.con_no)) return;
        const nut = $('[data-debt-ok]');
        nut.disabled = true;
        const pm = d.querySelector('[data-debt-pm] [aria-pressed="true"]')?.dataset.v || 'cash';
        try {
            const res = await fetch(d.dataset.debtUrl.replace(/\/0(?=\/|$)/, `/${r.id}`), {
                method: 'POST',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF },
                body: JSON.stringify({ amount: v, payment_method: pm, note: $('[data-debt-note]').value.trim() }),
            });
            let body = {};
            try { body = await res.json(); } catch (x) { /* trang lỗi không phải JSON */ }
            if (!res.ok) throw new Error(body.message || 'Không kết nối được máy chủ. Vui lòng thử lại.');
            d.close();
            if (xong) xong(v, body);
        } catch (err) {
            nut.disabled = false;
            const el = $('[data-debt-err]');
            el.hidden = false;
            el.textContent = err.message;
        }
    });
})();
</script>
