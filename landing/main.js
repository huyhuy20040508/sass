/* Sellio — trang giới thiệu
   Chỉ có một việc: nhận tên cửa hàng và số điện thoại rồi báo lại cho người điền.
   Chưa có nơi nhận, nên form dừng ở bước xác nhận — đặt địa chỉ vào ENDPOINT
   là nó gửi thật. */

const ENDPOINT = ''; // ví dụ: 'https://api.selliotech.store/v1/leads'
const HOTLINE = '0900 000 000';

const may = document.getElementById('may');
const khay = document.getElementById('khay');
const form = document.getElementById('form-dang-ky');
const bienlai = document.getElementById('bienlai');
const note = document.getElementById('form-note');

const NOTE_MAC_DINH = note ? note.textContent.trim() : '';
const NHANH = matchMedia('(prefers-reduced-motion: reduce)').matches;

const cho = (ms) => new Promise((r) => setTimeout(r, NHANH ? 0 : ms));
const hai = (n) => String(n).padStart(2, '0');

/** Số điện thoại Việt Nam: 10 số bắt đầu bằng 0, hoặc dạng +84. */
function laSoDienThoai(giaTri) {
  const so = giaTri.replace(/[\s.\-()]/g, '');
  return /^(0\d{9}|\+84\d{9})$/.test(so);
}

function hopLe(input) {
  if (input.name === 'phone') return laSoDienThoai(input.value);
  return input.value.trim().length > 1;
}

function bao(loi, tone) {
  note.textContent = loi;
  if (tone) note.dataset.tone = tone;
  else delete note.dataset.tone;
}

/* ── ngày lập in sẵn trên phiếu ──────────────────────────────────────── */

const homNay = new Date();
const oNgay = document.getElementById('hom-nay');
if (oNgay) {
  oNgay.textContent = `${hai(homNay.getDate())}/${hai(homNay.getMonth() + 1)}/${homNay.getFullYear()}`;
}

/* ── dấu tích khi một dòng đã điền đúng ──────────────────────────────── */

form?.addEventListener('input', (e) => {
  const dong = e.target.closest('.o');
  if (!dong) return;
  dong.classList.toggle('o--xong', hopLe(e.target));
  if (dong.classList.contains('o--loi')) {
    dong.classList.remove('o--loi');
    e.target.removeAttribute('aria-invalid');
    bao(NOTE_MAC_DINH, '');
  }
});

/** Báo lỗi ngay tại dòng: viền đỏ, rung nhẹ một cái, con trỏ nhảy vào. */
function nhacDong(input, loi) {
  const dong = input.closest('.o');
  dong.classList.remove('o--loi');
  void dong.offsetWidth;          // ép trình duyệt chạy lại hoạt ảnh rung
  dong.classList.add('o--loi');
  input.setAttribute('aria-invalid', 'true');
  input.focus();
  bao(loi, 'loi');
}

/* ── đưa phiếu vào máy ───────────────────────────────────────────────── */

form?.addEventListener('submit', async (e) => {
  e.preventDefault();

  const shop = form.elements.shop;
  const phone = form.elements.phone;

  if (!shop.value.trim()) {
    return nhacDong(shop, 'Điền tên cửa hàng để chúng tôi biết gọi cho ai.');
  }
  if (!laSoDienThoai(phone.value)) {
    return nhacDong(phone, 'Số điện thoại chưa đúng. Cần 10 số bắt đầu bằng 0, ví dụ 0912345678.');
  }

  const nut = form.querySelector('button[type="submit"]');
  const chuCu = nut.querySelector('.nut__chu').textContent;
  nut.disabled = true;
  nut.querySelector('.nut__chu').textContent = 'Máy đang đọc phiếu…';
  may.classList.add('dang-chay');

  const duLieu = { shop: shop.value.trim(), phone: phone.value.trim() };

  try {
    if (ENDPOINT) {
      const res = await fetch(ENDPOINT, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(duLieu),
      });
      if (!res.ok) throw new Error(res.status);
    } else {
      await cho(700);   // giữ nhịp cho máy có vẻ đang làm việc
    }
    await inBienLai(duLieu);
  } catch {
    may.classList.remove('dang-chay');
    nut.disabled = false;
    nut.querySelector('.nut__chu').textContent = chuCu;
    bao(`Máy chưa nhận được phiếu. Gọi ${HOTLINE} hoặc nhắn hello@selliotech.store, chúng tôi mở tài khoản ngay.`, 'loi');
  }
});

/** Nuốt tờ phiếu vào khe rồi nhả biên nhận ra, khay co giãn theo. */
async function inBienLai({ shop, phone }) {
  document.getElementById('bl-ma').textContent = taoMaPhieu();
  document.getElementById('bl-shop').textContent = shop;
  document.getElementById('bl-phone').textContent = phone;
  document.getElementById('bl-luc').textContent =
    `${hai(homNay.getHours())}:${hai(new Date().getMinutes())} · ${hai(homNay.getDate())}/${hai(homNay.getMonth() + 1)}`;

  khay.style.height = `${khay.offsetHeight}px`;

  form.classList.add('dang-nuot');
  await cho(620);

  form.hidden = true;
  bienlai.hidden = false;
  may.classList.remove('dang-chay');

  const cao = bienlai.offsetHeight;
  requestAnimationFrame(() => {
    khay.style.height = `${cao}px`;
    bienlai.classList.add('dang-in');
  });

  await cho(1200);
  khay.style.height = 'auto';     // trả lại cho trình duyệt, đổi cỡ màn hình vẫn đúng

  bienlai.setAttribute('tabindex', '-1');
  bienlai.focus({ preventScroll: true });
}

/** Mã phiếu kiểu SL-1108-4712 — đủ để nhắc lại khi gọi điện. */
function taoMaPhieu() {
  const so = Math.floor(1000 + Math.random() * 9000);
  return `SL-${hai(homNay.getDate())}${hai(homNay.getMonth() + 1)}-${so}`;
}
