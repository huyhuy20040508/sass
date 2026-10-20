import 'package:dio/dio.dart';

import '../config.dart';
import 'api_exception.dart';

/// Những gì client cần biết về phiên mà không phụ thuộc vào tầng auth.
abstract interface class TokenSource {
  String? get accessToken;
  int? get chiNhanhId;

  /// Đổi refresh token lấy access token mới; trả null nếu phiên đã hết,
  /// ném lỗi nếu chỉ là mất mạng.
  Future<String?> refresh();

  /// Phiên hết hẳn (refresh cũng bị từ chối) — đưa về màn đăng nhập.
  Future<void> onSessionExpired();
}

/// Gọi API Go, cùng cách ApiClient.php của web_Shop: Bearer + X-Chi-Nhanh,
/// gặp 401 thì làm mới token một lần rồi gửi lại.
class ApiClient {
  ApiClient(this._tokens, {Dio? dio}) : dio = dio ?? Dio() {
    this.dio.options
      ..baseUrl = AppConfig.apiBaseUrl
      ..connectTimeout = const Duration(seconds: 10)
      ..receiveTimeout = const Duration(seconds: 30)
      ..headers['Accept'] = 'application/json';
    this.dio.interceptors.add(_AuthInterceptor(this.dio, _tokens));
  }

  final Dio dio;
  final TokenSource _tokens;

  /// Trả `data` trong khuôn `{success, data, meta}`.
  Future<T> get<T>(String path, {Map<String, dynamic>? query}) => _send(() => dio.get(path, queryParameters: query));

  Future<T> post<T>(String path, {Object? body}) => _send(() => dio.post(path, data: body));

  Future<T> put<T>(String path, {Object? body, Map<String, String>? headers}) => _send(
    () => dio.put(
      path,
      data: body,
      options: headers == null ? null : Options(headers: headers),
    ),
  );

  Future<T> delete<T>(String path) => _send(() => dio.delete(path));

  /// Cả khối phản hồi `{success, data, meta}` — khi cần đọc thêm trong `meta` (hàng tổng, số đếm).
  Future<Map<String, dynamic>> getRaw(String path, {Map<String, dynamic>? query}) async {
    try {
      final body = (await dio.get(path, queryParameters: query)).data;
      return body is Map ? Map<String, dynamic>.from(body) : <String, dynamic>{};
    } on DioException catch (e) {
      throw ApiException.fromDio(e);
    }
  }

  /// Danh sách có phân trang: `data` + `meta {page, page_size, total, total_pages}`.
  Future<ApiPage> getPage(String path, {Map<String, dynamic>? query}) async {
    try {
      final body = (await dio.get(path, queryParameters: query)).data;
      final meta = body is Map ? body['meta'] : null;
      int n(String k) => meta is Map ? ((meta[k] as num?) ?? 0).toInt() : 0;
      return ApiPage(
        data: body is Map ? (body['data'] as List?) ?? const [] : const [],
        total: n('total'),
        totalPages: n('total_pages'),
      );
    } on DioException catch (e) {
      throw ApiException.fromDio(e);
    }
  }

  Future<T> _send<T>(Future<Response> Function() call) async {
    try {
      final res = await call();
      final body = res.data;
      return (body is Map ? body['data'] : body) as T;
    } on DioException catch (e) {
      throw ApiException.fromDio(e);
    }
  }
}

class ApiPage {
  const ApiPage({required this.data, required this.total, required this.totalPages});

  final List<dynamic> data;
  final int total;
  final int totalPages;
}

class _AuthInterceptor extends QueuedInterceptor {
  _AuthInterceptor(this._dio, this._tokens);

  final Dio _dio;
  final TokenSource _tokens;

  static const _daThuLai = 'da_thu_lai';

  @override
  void onRequest(RequestOptions o, RequestInterceptorHandler h) {
    final token = _tokens.accessToken;
    if (token != null) o.headers['Authorization'] = 'Bearer $token';
    final cn = _tokens.chiNhanhId;
    if (cn != null) o.headers['X-Chi-Nhanh'] = '$cn';
    h.next(o);
  }

  @override
  Future<void> onError(DioException e, ErrorInterceptorHandler h) async {
    final o = e.requestOptions;
    // 401 ở đăng nhập là sai mật khẩu, không phải hết hạn token.
    final khongLamMoi = o.path.endsWith('/shop-login') || o.path.endsWith('/refresh');
    if (e.response?.statusCode != 401 || khongLamMoi || o.extra[_daThuLai] == true) {
      return h.next(e);
    }

    // QueuedInterceptor xếp hàng các lỗi: nếu request trước đã làm mới thì
    // token hiện tại đã khác token request này mang đi — chỉ cần gửi lại.
    final daMangDi = o.headers['Authorization'];
    var token = _tokens.accessToken;
    if (token == null || daMangDi == 'Bearer $token') {
      try {
        token = await _tokens.refresh();
      } catch (_) {
        return h.next(e);
      }
    }
    if (token == null) {
      await _tokens.onSessionExpired();
      return h.next(e);
    }

    o.headers['Authorization'] = 'Bearer $token';
    o.extra[_daThuLai] = true;
    try {
      h.resolve(await _dio.fetch(o));
    } on DioException catch (e2) {
      h.next(e2);
    }
  }
}
