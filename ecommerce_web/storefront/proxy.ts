import { NextResponse, type NextRequest } from 'next/server';

// Nhớ chi nhánh từ QR (?branch_code=CN1) vào cookie: khách quét QR một lần, hôm sau
// gõ thẳng địa chỉ shop vẫn đúng chi nhánh. Mỗi shop một tên miền nên cookie tự tách theo shop.
export const BRANCH_COOKIE = 'ecw_branch';
const BRANCH_RE = /^[A-Za-z0-9_-]{1,30}$/;
const XEM_TRUOC = 'x-ecw-xem-truoc';
const THU_MAU = 'x-ecw-thu-mau';

export function proxy(req: NextRequest) {
  const h = new Headers(req.headers);
  // Khung xem trước ở màn Chỉnh sửa giao diện mở trang khách kèm ?xem_truoc=<mã>; layout
  // không đọc được query nên chép sang header (xoá header khách tự gửi). PHP mới kiểm mã.
  h.delete(XEM_TRUOC);
  h.delete(THU_MAU);
  const xemTruoc = req.nextUrl.searchParams.get('xem_truoc') ?? '';
  if (/^[\w.-]{10,80}$/.test(xemTruoc)) {
    h.set(XEM_TRUOC, xemTruoc);
    // "Xem thử" một mẫu khác ở Kho giao diện (chỉ có nghĩa khi kèm mã xem trước).
    const thuMau = req.nextUrl.searchParams.get('thu_mau') ?? '';
    if (/^[a-z0-9-]{2,40}$/.test(thuMau)) h.set(THU_MAU, thuMau);
  }

  const branch = req.nextUrl.searchParams.get('branch_code')?.trim() ?? '';
  const nhoChiNhanh = BRANCH_RE.test(branch) && req.cookies.get(BRANCH_COOKIE)?.value !== branch;
  if (nhoChiNhanh) {
    // Gắn luôn vào lượt này: layout đọc chi nhánh từ cookie, lần quét QR đầu tiên cũng phải thấy.
    req.cookies.set(BRANCH_COOKIE, branch);
    h.set('cookie', req.cookies.toString());
  }

  const res = NextResponse.next({ request: { headers: h } });
  if (nhoChiNhanh) res.cookies.set(BRANCH_COOKIE, branch, { maxAge: 60 * 60 * 24 * 365, sameSite: 'lax', path: '/' });
  return res;
}

// Chỉ trang khách; /admin, /api và tệp tĩnh không cần.
export const config = { matcher: ['/((?!admin|api|_next|favicon.ico).*)'] };
