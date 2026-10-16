import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:selliotech/core/api/api_client.dart';
import 'package:selliotech/core/api/api_exception.dart';

class _FakeTokens implements TokenSource {
  _FakeTokens(this.accessToken, {this.newToken});

  @override
  String? accessToken;
  final String? newToken;
  int refreshCount = 0;
  bool expired = false;

  @override
  int? get chiNhanhId => 5;

  @override
  Future<String?> refresh() async {
    refreshCount++;
    accessToken = newToken;
    return newToken;
  }

  @override
  Future<void> onSessionExpired() async => expired = true;
}

/// Máy chủ giả: chỉ nhận token "moi", ghi lại header của mỗi lượt gọi.
class _FakeServer implements HttpClientAdapter {
  final seen = <Map<String, dynamic>>[];

  @override
  Future<ResponseBody> fetch(RequestOptions o, Stream<Uint8List>? _, Future<void>? _) async {
    seen.add(Map.of(o.headers));
    final ok = o.headers['Authorization'] == 'Bearer moi';
    final body = ok
        ? {
            'success': true,
            'data': {'username': 'admin'},
          }
        : {'success': false, 'message': 'Phiên hết hạn'};
    return ResponseBody.fromString(
      jsonEncode(body),
      ok ? 200 : 401,
      headers: {
        Headers.contentTypeHeader: ['application/json'],
      },
    );
  }

  @override
  void close({bool force = false}) {}
}

void main() {
  test('gắn Bearer + X-Chi-Nhanh, gặp 401 thì làm mới một lần rồi gửi lại', () async {
    final tokens = _FakeTokens('cu', newToken: 'moi');
    final server = _FakeServer();
    final api = ApiClient(tokens, dio: Dio()..httpClientAdapter = server);

    final me = await api.get<Map<String, dynamic>>('/admin/me');

    expect(me['username'], 'admin');
    expect(tokens.refreshCount, 1);
    expect(server.seen.first['Authorization'], 'Bearer cu');
    expect(server.seen.first['X-Chi-Nhanh'], '5');
    expect(server.seen.last['Authorization'], 'Bearer moi');
  });

  test('refresh bị từ chối thì báo hết phiên và ném ApiException 401', () async {
    final tokens = _FakeTokens('cu');
    final api = ApiClient(tokens, dio: Dio()..httpClientAdapter = _FakeServer());

    await expectLater(
      api.get('/admin/me'),
      throwsA(isA<ApiException>().having((e) => e.statusCode, 'statusCode', 401)),
    );
    expect(tokens.expired, isTrue);
  });

  test('401 lúc đăng nhập không kích hoạt làm mới token', () async {
    final tokens = _FakeTokens('cu', newToken: 'moi');
    final api = ApiClient(tokens, dio: Dio()..httpClientAdapter = _FakeServer());

    await expectLater(api.post('/auth/shop-login', body: {}), throwsA(isA<ApiException>()));
    expect(tokens.refreshCount, 0);
  });

  test('/auth/me cũng được làm mới token như mọi đường khác', () async {
    final tokens = _FakeTokens('cu', newToken: 'moi');
    final api = ApiClient(tokens, dio: Dio()..httpClientAdapter = _FakeServer());

    final me = await api.get<Map<String, dynamic>>('/auth/me');
    expect(me['username'], 'admin');
    expect(tokens.refreshCount, 1);
  });

  test('ApiException đọc errors.ma và lỗi từng trường', () {
    final req = RequestOptions(path: '/x');
    final e = ApiException.fromDio(
      DioException(
        requestOptions: req,
        response: Response(
          requestOptions: req,
          statusCode: 409,
          data: {
            'success': false,
            'message': 'Chi nhánh chưa mở ca',
            'errors': {'ma': 'CHUA_MO_CA', 'so_tien': 'Bắt buộc'},
          },
        ),
      ),
    );
    expect(e.ma, MaLoi.chuaMoCa);
    expect(e.message, 'Chi nhánh chưa mở ca');
    expect(e.fieldErrors, {'so_tien': 'Bắt buộc'});
    expect(e.laMatMang, isFalse);
  });

  test('mất mạng ra câu dễ hiểu', () {
    final e = ApiException.fromDio(
      DioException.connectionError(
        requestOptions: RequestOptions(path: '/x'),
        reason: 'offline',
      ),
    );
    expect(e.laMatMang, isTrue);
  });
}
