import { api, type ApiResult } from './api';

// Token đăng nhập quản trị. Mỗi shop một tên miền nên localStorage tự tách theo shop.
const TOKEN_KEY = 'ecw_admin_token';
// Lý do vừa bị đẩy ra (hết hạn, mất quyền…) để màn đăng nhập nói cho người dùng biết.
const NOTICE_KEY = 'ecw_admin_notice';

export const LOGIN_PATH = '/admin/dang-nhap';

function read(store: () => Storage, key: string): string {
  try {
    return store().getItem(key) || '';
  } catch {
    return '';
  }
}

function write(store: () => Storage, key: string, value: string) {
  try {
    if (value) store().setItem(key, value);
    else store().removeItem(key);
  } catch {
    // Trình duyệt chặn lưu: vẫn dùng được trong lượt này, tải lại trang thì phải đăng nhập lại.
  }
}

const local = () => localStorage;
const session = () => sessionStorage;

export const getAdminToken = () => read(local, TOKEN_KEY);
export const saveAdminToken = (token: string) => write(local, TOKEN_KEY, token);
export const clearAdminToken = () => write(local, TOKEN_KEY, '');

export const readLogoutNotice = () => read(session, NOTICE_KEY);
/** Xoá sau khi đã hiện: tải lại trang đăng nhập thì không hiện lại. */
export const clearLogoutNotice = () => write(session, NOTICE_KEY, '');

/** Gọi API quản trị kèm token; phiên hết hạn (401) thì quên token và về trang đăng nhập. */
export async function adminApi<T = unknown>(path: string, options: { method?: string; body?: unknown } = {}): Promise<ApiResult<T>> {
  try {
    return await api<T>(path, { ...options, token: getAdminToken() });
  } catch (e) {
    if (e instanceof Error && 'status' in e && e.status === 401) {
      clearAdminToken();
      write(session, NOTICE_KEY, e.message);
      window.location.replace(LOGIN_PATH);
    }
    throw e;
  }
}

export type AdminSession = {
  admin: { username: string; full_name: string };
  shop: { code: string; name: string };
};
