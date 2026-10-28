// Chi nhánh khách đang xem. Lấy từ QR chi nhánh (?branch_code=CN1) trước, không có
// thì dùng lần trước đã nhớ — khách quét QR một lần, hôm sau gõ thẳng địa chỉ shop
// vẫn đúng chi nhánh. Mỗi shop một tên miền nên localStorage tự tách theo shop.

const KEY = 'ecw_branch';
const BRANCH_RE = /^[A-Za-z0-9_-]{1,30}$/;

export function pickBranch(search) {
  const fromLink = (new URLSearchParams(search).get('branch_code') || '').trim();
  if (BRANCH_RE.test(fromLink)) {
    try {
      localStorage.setItem(KEY, fromLink);
    } catch {
      // Trình duyệt chặn lưu (ẩn danh): vẫn bán được, chỉ là lần sau phải quét lại QR.
    }
    return fromLink;
  }
  try {
    const saved = localStorage.getItem(KEY) || '';
    return BRANCH_RE.test(saved) ? saved : '';
  } catch {
    return '';
  }
}
