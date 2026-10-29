export class ApiError extends Error {
  status: number;
  errors: Record<string, string>;

  constructor(message: string, status: number, errors?: Record<string, string>) {
    super(message);
    this.status = status;
    this.errors = errors || {};
  }
}

export type ApiResult<T> = { data: T; meta: Record<string, unknown>; message: string };

/**
 * Gọi backend từ trình duyệt; trả {data, meta, message} hoặc ném ApiError mang câu báo lỗi của máy chủ.
 * Không cần gửi mã shop: backend nhận shop theo tên miền đang mở.
 */
export async function api<T = unknown>(
  path: string,
  { method = 'GET', body, token }: { method?: string; body?: unknown; token?: string } = {},
): Promise<ApiResult<T>> {
  const headers: Record<string, string> = { Accept: 'application/json' };
  if (token) headers.Authorization = `Bearer ${token}`;
  // FormData (tải ảnh) để trình duyệt tự đặt Content-Type kèm boundary.
  const laForm = body instanceof FormData;
  if (body !== undefined && !laForm) headers['Content-Type'] = 'application/json';

  let res: Response;
  try {
    res = await fetch(`/api${path}`, { method, headers, body: body === undefined ? undefined : laForm ? body : JSON.stringify(body) });
  } catch {
    throw new ApiError('Không kết nối được máy chủ, kiểm tra mạng rồi thử lại', 0);
  }
  const json = await res.json().catch(() => ({}));
  if (!res.ok || !json.success) {
    throw new ApiError(json.message || `Lỗi ${res.status}`, res.status, json.errors);
  }
  return { data: json.data as T, meta: json.meta || {}, message: json.message || '' };
}
