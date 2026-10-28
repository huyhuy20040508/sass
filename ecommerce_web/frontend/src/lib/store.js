// Shop + chi nhánh khách đang xem. Lấy từ link (QR) trước, không có thì dùng
// lần trước đã nhớ — khách quét QR một lần, hôm sau gõ thẳng tên miền vẫn vào đúng tiệm.

const KEY = 'ecw_store';
// Cùng luật mã cửa hàng bên POS.
const STORE_RE = /^[a-z0-9][a-z0-9._-]{1,29}$/;
const BRANCH_RE = /^[A-Za-z0-9_-]{1,30}$/;

function load() {
  try {
    const v = JSON.parse(localStorage.getItem(KEY) || '{}');
    return { store: String(v.store || ''), branch: String(v.branch || '') };
  } catch {
    return { store: '', branch: '' };
  }
}

export function saveStore({ store, branch }) {
  try {
    localStorage.setItem(KEY, JSON.stringify({ store, branch }));
  } catch {
    // Trình duyệt chặn lưu (ẩn danh): vẫn bán được, chỉ là lần sau phải quét lại QR.
  }
}

export function clearStore() {
  try {
    localStorage.removeItem(KEY);
  } catch {
    // như trên
  }
}

/**
 * Nhận cả hai kiểu link: /cua-hang?slug=kimhana và /?store_id=kimhana&branch_code=CN1.
 * fromSaved = true khi link không mang mã shop và đang dùng lại shop đã nhớ.
 */
export function pickStore(search) {
  const p = new URLSearchParams(search);
  const store = (p.get('store_id') || p.get('slug') || '').trim().toLowerCase();
  const branch = (p.get('branch_code') || '').trim();
  const saved = load();

  if (!store) {
    return { ...saved, fromSaved: true };
  }
  // Quét QR của tiệm khác thì chi nhánh đã nhớ của tiệm cũ không còn nghĩa.
  const keptBranch = branch || (saved.store === store ? saved.branch : '');
  return {
    store,
    branch: BRANCH_RE.test(keptBranch) ? keptBranch : '',
    fromSaved: false,
    validFormat: STORE_RE.test(store),
  };
}

/** Thêm store_id + branch_code vào một đường dẫn trong trang. */
export function withStore(to, { store, branch }) {
  const [path, query = ''] = String(to).split('?');
  const p = new URLSearchParams(query);
  if (store) p.set('store_id', store);
  if (branch) p.set('branch_code', branch);
  const qs = p.toString();
  return qs ? `${path}?${qs}` : path;
}
