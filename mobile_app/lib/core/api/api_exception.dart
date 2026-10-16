import 'package:dio/dio.dart';

/// Mã lỗi máy đọc được trong `errors.ma` — khớp api/pkg/response/response.go.
abstract final class MaLoi {
  static const cuaHangKhoa = 'CUA_HANG_KHOA';
  static const ketAm = 'KET_AM';
  static const chuaMoCa = 'CHUA_MO_CA';
}

/// Lỗi đã đọc từ khuôn `{success:false, message, errors}` của API.
class ApiException implements Exception {
  ApiException({required this.message, this.statusCode, this.ma, this.fieldErrors = const {}, this.retryAfter});

  final String message;
  final int? statusCode;
  final String? ma;
  final Map<String, String> fieldErrors;

  /// Thời gian chờ khi bị chặn vì thử quá nhiều lần (429 + Retry-After).
  final Duration? retryAfter;

  bool get laMatMang => statusCode == null;

  bool get biChanTam => statusCode == 429;

  factory ApiException.fromDio(DioException e) {
    final res = e.response;
    if (res == null) {
      return ApiException(message: 'Không kết nối được máy chủ. Kiểm tra mạng rồi thử lại.');
    }
    final body = res.data;
    String message = 'Có lỗi xảy ra (mã ${res.statusCode}).';
    String? ma;
    final fields = <String, String>{};
    if (body is Map) {
      if (body['message'] is String && (body['message'] as String).isNotEmpty) {
        message = body['message'] as String;
      }
      final errors = body['errors'];
      if (errors is Map) {
        errors.forEach((k, v) {
          if (k == 'ma') {
            ma = v?.toString();
          } else if (v != null) {
            fields[k.toString()] = v is List ? v.join(', ') : v.toString();
          }
        });
      }
    }
    final giay = int.tryParse(res.headers.value('retry-after') ?? '');
    return ApiException(
      message: message,
      statusCode: res.statusCode,
      ma: ma,
      fieldErrors: fields,
      retryAfter: giay == null ? null : Duration(seconds: giay),
    );
  }

  @override
  String toString() => message;
}
