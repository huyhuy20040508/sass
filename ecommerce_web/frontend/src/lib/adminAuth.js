import { api } from './api';

// Token đăng nhập quản trị. Mỗi shop một tên miền nên localStorage tự tách theo shop.
const TOKEN_KEY = 'ecw_admin_token';
// Lý do vừa bị đẩy ra (hết hạn, mất quyền…) để màn đăng nhập nói cho người dùng biết.
const NOTICE_KEY = 'ecw_admin_notice';

export const LOGIN_PATH = '/admin/dang-nhap';

function read(key) {
  try {
    return localStorage.getItem(key) || '';
  } catch {
    return '';
  }
}

function write(key, value) {
  try {
    if (value) localStorage.setItem(key, value);
    else localStorage.removeItem(key);
  } catch {
    // Trình duyệt chặn lưu: vẫn dùng được trong lượt này, tải lại trang thì phải đăng nhập lại.
  }
}

let token = read(TOKEN_KEY);

export const getAdminToken = () => token;
export function saveAdminToken(newToken) {
  token = newToken;
  write(TOKEN_KEY, newToken);
}

export function clearAdminToken() {
  token = '';
  write(TOKEN_KEY, '');
}

export function readLogoutNotice() {
  try {
    return sessionStorage.getItem(NOTICE_KEY) || '';
  } catch {
    return '';
  }
}

/** Xoá sau khi đã hiện: tải lại trang đăng nhập thì không hiện lại. */
export function clearLogoutNotice() {
  try {
    sessionStorage.removeItem(NOTICE_KEY);
  } catch {
    // như trên
  }
}

/** Gọi API quản trị kèm token; phiên hết hạn (401) thì quên token và về trang đăng nhập. */
export async function adminApi(path, options = {}) {
  try {
    return await api(path, { ...options, token });
  } catch (e) {
    if (e.status === 401) {
      clearAdminToken();
      try {
        sessionStorage.setItem(NOTICE_KEY, e.message);
      } catch {
        // không lưu được thì chỉ mất câu báo, vẫn về trang đăng nhập
      }
      window.location.replace(LOGIN_PATH);
    }
    throw e;
  }
}
