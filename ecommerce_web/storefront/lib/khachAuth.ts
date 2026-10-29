import { api, type ApiResult } from './api';

// Token của KHÁCH MUA HÀNG. Khác hẳn token quản trị (ecw_admin_token): hai vai
// khác nhau, lưu chung một khoá là đăng xuất bên này mất luôn bên kia.
const TOKEN_KEY = 'ecw_khach_token';

export type Khach = { full_name: string; phone: string; email: string };

function doc(key: string): string {
  try {
    return localStorage.getItem(key) || '';
  } catch {
    return '';
  }
}

function ghi(key: string, value: string) {
  try {
    if (value) localStorage.setItem(key, value);
    else localStorage.removeItem(key);
  } catch {
    // Trình duyệt chặn lưu (chế độ riêng tư): vẫn mua được trong lượt này.
  }
}

export const layToken = () => doc(TOKEN_KEY);
export const luuToken = (token: string) => ghi(TOKEN_KEY, token);
export const xoaToken = () => ghi(TOKEN_KEY, '');

/** Gọi API của khách kèm token; hết phiên thì quên token để trang hỏi đăng nhập lại. */
export async function khachApi<T = unknown>(path: string, options: { method?: string; body?: unknown } = {}): Promise<ApiResult<T>> {
  try {
    return await api<T>(path, { ...options, token: layToken() });
  } catch (e) {
    if (e instanceof Error && 'status' in e && e.status === 401) xoaToken();
    throw e;
  }
}
