/* Sellio — trang giới thiệu
   Chỉ có một việc: nhận tên cửa hàng và số điện thoại rồi báo lại cho người điền.
   Chưa có nơi nhận, nên form dừng ở bước xác nhận — đặt địa chỉ vào ENDPOINT
   là nó gửi thật. */

// Đường ĐĂNG KÝ THẬT: form này không còn là phiếu để lại số nữa, nó dựng luôn
// cửa hàng + tài khoản đăng nhập + hợp đồng dùng thử 14 ngày.
//
// Suy ra từ chính tên miền đang mở, không nhốt cứng một địa chỉ: trang giới
// thiệu chạy ở cả máy cục bộ, máy thử và máy thật, mà ba nơi đó ba địa chỉ API
// khác nhau. Ghi cứng thì hai môi trường kia âm thầm gửi đơn đăng ký sang máy
// thật.
const ENDPOINT = (location.hostname === 'localhost' || location.hostname === '127.0.0.1')
  ? 'http://localhost:8080/api/v1/dang-ky'
  : `${location.protocol}//api.${location.hostname.replace(/^www\./, '')}/api/v1/dang-ky`;
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
  const code = form.elements.code;
  const contact = form.elements.contact;
  const phone = form.elements.phone;
  const pass = form.elements.pass;

  if (!shop.value.trim()) {
    return nhacDong(shop, 'Điền tên cửa hàng để in lên hoá đơn và trang bán hàng của bạn.');
  }
  // Cùng khuôn mã cửa hàng mà máy chủ nhận (chữ thường không dấu). Kiểm ở đây chỉ
  // để nói sớm; máy chủ vẫn kiểm lại và nó mới là nơi quyết định.
  if (!/^[a-z0-9][a-z0-9-]{2,29}$/.test(code.value.trim().toLowerCase())) {
    return nhacDong(code, 'Mã cửa hàng gồm chữ thường không dấu, số hoặc gạch ngang — ví dụ minhanh.');
  }
  if (!contact.value.trim()) {
    return nhacDong(contact, 'Điền tên người phụ trách để chúng tôi biết liên hệ với ai.');
  }
  if (!laSoDienThoai(phone.value)) {
    return nhacDong(phone, 'Số điện thoại chưa đúng. Cần 10 số bắt đầu bằng 0, ví dụ 0912345678.');
  }
  if (pass.value.length < 6) {
    return nhacDong(pass, 'Mật khẩu tối thiểu 6 ký tự — đây là mật khẩu bạn dùng để đăng nhập.');
  }

  const nut = form.querySelector('button[type="submit"]');
  const chuCu = nut.querySelector('.nut__chu').textContent;
  nut.disabled = true;
  nut.querySelector('.nut__chu').textContent = 'Máy đang đọc phiếu…';
  may.classList.add('dang-chay');

  const duLieu = {
    ma_cua_hang: code.value.trim().toLowerCase(),
    ten_cua_hang: shop.value.trim(),
    // Tên đăng nhập cố định 'admin' cho tài khoản đầu tiên: bớt một ô phải điền,
    // và nó là thứ người ta đoán ra được khi quên. Thêm người khác thì đặt tên
    // đăng nhập riêng ở trang Người dùng trong phần mềm.
    ten_dang_nhap: 'admin',
    mat_khau: pass.value,
    nguoi_lien_he: contact.value.trim(),
    dien_thoai: phone.value.trim(),
    website: form.elements.website.value,   // ô bẫy, người thật luôn để trống
  };

  try {
    const res = await fetch(ENDPOINT, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(duLieu),
    });
    const body = await res.json().catch(() => ({}));
    if (!res.ok) {
      may.classList.remove('dang-chay');
      nut.disabled = false;
      nut.querySelector('.nut__chu').textContent = chuCu;

      // Máy chủ nói rõ ô nào sai thì chỉ thẳng vào ô đó — người dùng sửa được
      // ngay thay vì đọc một câu chung chung rồi đoán.
      const loiO = body.errors || {};
      if (loiO.ma_cua_hang) return nhacDong(code, loiO.ma_cua_hang);
      if (loiO.ten_dang_nhap) return nhacDong(code, loiO.ten_dang_nhap);
      if (loiO.mat_khau) return nhacDong(pass, loiO.mat_khau);
      if (res.status === 429) {
        return bao('Máy đang nhận quá nhiều phiếu từ đường truyền này. Thử lại sau ít phút, '
          + `hoặc gọi ${HOTLINE} để mở tài khoản ngay.`, 'loi');
      }

      return bao(body.message || `Máy chưa nhận được phiếu. Gọi ${HOTLINE} để mở tài khoản ngay.`, 'loi');
    }

    await inBienLai(body.data || {});
  } catch {
    may.classList.remove('dang-chay');
    nut.disabled = false;
    nut.querySelector('.nut__chu').textContent = chuCu;
    bao(`Máy chưa nhận được phiếu. Gọi ${HOTLINE} hoặc nhắn hello@selliotech.store, chúng tôi mở tài khoản ngay.`, 'loi');
  }
});

/** Nuốt tờ phiếu vào khe rồi nhả biên nhận ra, khay co giãn theo. */
async function inBienLai(kq) {
  const dat = (id, chu) => { document.getElementById(id).textContent = chu; };

  // In THÔNG TIN ĐĂNG NHẬP THẬT do máy chủ trả về, không phải mã phiếu tự bịa:
  // biên nhận này giờ là thứ người ta chép lại để vào phần mềm.
  dat('bl-shop', document.getElementById('form-dang-ky').elements.shop.value.trim());
  dat('bl-ma', kq.ma_cua_hang || '—');
  dat('bl-user', kq.ten_dang_nhap || 'admin');
  dat('bl-goi', kq.goi || 'Khởi đầu');
  dat('bl-han', kq.het_han ? new Date(kq.het_han).toLocaleDateString('vi-VN') : '—');

  // Nút vào thẳng phần mềm: địa chỉ do máy chủ cấp (mỗi môi trường một Shop
  // Admin khác nhau), nên trang này không đoán lấy.
  const nutVao = document.getElementById('bl-vao');
  if (kq.dia_chi_dang_nhap) {
    nutVao.href = kq.dia_chi_dang_nhap;
    nutVao.hidden = false;
  }

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
