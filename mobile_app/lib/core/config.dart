import 'package:flutter/foundation.dart';

/// Cấu hình lúc build.
class AppConfig {
  static const _prod = 'https://api.selliotech.store/api/v1';

  /// API dev (cùng dữ liệu với web huyshop), mở qua nginx của huyshop.selliotech.store.
  static const _dev = 'https://huyshop.selliotech.store/api/v1';

  /// Bản chạy thử (`flutter run`, debug/profile) đọc dữ liệu dev; APK phát hành
  /// (`flutter build apk --release`) đọc prod. Ghi đè được bằng
  /// `--dart-define=API_BASE_URL=...`.
  static const apiBaseUrl = String.fromEnvironment('API_BASE_URL', defaultValue: kReleaseMode ? _prod : _dev);
}
