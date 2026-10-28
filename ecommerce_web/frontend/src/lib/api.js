let currentStore = '';

/** ShopLayout gọi sau khi backend xác nhận shop; từ đó mọi lượt gọi tự mang mã shop. */
export function setApiStore(code) {
  currentStore = code;
}

export class ApiError extends Error {
  constructor(message, status, errors) {
    super(message);
    this.status = status;
    this.errors = errors || {};
  }
}

/** Gọi backend; trả {data, meta, message} hoặc ném ApiError mang câu báo lỗi của máy chủ. */
export async function api(path, { method = 'GET', body, store } = {}) {
  const headers = { Accept: 'application/json' };
  const storeCode = store ?? currentStore;
  if (storeCode) headers['X-Store-Id'] = storeCode;
  if (body !== undefined) headers['Content-Type'] = 'application/json';

  let res;
  try {
    res = await fetch(`/api${path}`, { method, headers, body: body === undefined ? undefined : JSON.stringify(body) });
  } catch {
    throw new ApiError('Không kết nối được máy chủ, kiểm tra mạng rồi thử lại', 0);
  }
  const json = await res.json().catch(() => ({}));
  if (!res.ok || !json.success) {
    throw new ApiError(json.message || `Lỗi ${res.status}`, res.status, json.errors);
  }
  return { data: json.data, meta: json.meta || {}, message: json.message || '' };
}
