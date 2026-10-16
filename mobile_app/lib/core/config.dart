/// Cấu hình lúc build, truyền bằng `--dart-define`.
///
/// Mặc định trỏ API thật vì bản dev chỉ nghe trong máy chủ (127.0.0.1:8091),
/// điện thoại không gọi tới được.
class AppConfig {
  static const apiBaseUrl = String.fromEnvironment('API_BASE_URL', defaultValue: 'https://api.selliotech.store/api/v1');
}
