import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../../core/api/api_exception.dart';
import '../../core/config.dart';
import '../../core/storage/session_store.dart';
import 'session.dart';

final sessionStoreProvider = Provider<SessionStore>((_) => SessionStore());

final authProvider = AsyncNotifierProvider<AuthController, Session?>(AuthController.new);

final apiClientProvider = Provider<ApiClient>((ref) => ApiClient(ref.read(authProvider.notifier)));

final rememberedLoginProvider = FutureProvider<RememberedLogin?>(
  (ref) => ref.read(sessionStoreProvider).readRemembered(),
);

class AuthController extends AsyncNotifier<Session?> implements TokenSource {
  SessionStore get _store => ref.read(sessionStoreProvider);

  Future<String?>? _dangLamMoi;

  /// Tắt "Ghi nhớ đăng nhập" thì phiên chỉ sống trong bộ nhớ, tắt app là mất.
  bool _luuPhien = true;

  @override
  Future<Session?> build() => _store.read();

  @override
  String? get accessToken => state.value?.accessToken;

  @override
  int? get chiNhanhId => state.value?.chiNhanhId;

  /// Đăng nhập và cất phiên, nhưng CHƯA chuyển màn — màn đăng nhập còn chạy
  /// hiệu ứng "Đăng nhập thành công" rồi mới gọi [publish].
  Future<Session> login({
    required String shopCode,
    required String username,
    required String password,
    bool remember = true,
  }) async {
    final api = ref.read(apiClientProvider);
    final data = await api.post<Map<String, dynamic>>(
      '/auth/shop-login',
      body: {'shop_code': shopCode, 'username': username, 'password': password},
    );
    final s = Session.fromLogin(data);
    _luuPhien = remember;
    if (remember) {
      await _store.write(s);
      await _store.writeRemembered(
        RememberedLogin(shopCode: shopCode, username: username, displayName: s.user.tenHienThi),
      );
    } else {
      await _store.clear();
      await _store.clearRemembered();
    }
    ref.invalidate(rememberedLoginProvider);
    return s;
  }

  void publish(Session s) => state = AsyncData(s);

  /// Ghi chi nhánh đang làm — từ đây mọi lượt gọi API mang X-Chi-Nhanh này.
  Future<void> setChiNhanh(int? id) async {
    final cur = state.value;
    if (cur == null || cur.chiNhanhId == id) return;
    final s = cur.withChiNhanh(id);
    if (_luuPhien) await _store.write(s);
    state = AsyncData(s);
  }

  Future<void> logout() async {
    await _store.clear();
    _luuPhien = true;
    state = const AsyncData(null);
  }

  @override
  Future<String?> refresh() => _dangLamMoi ??= _refresh().whenComplete(() => _dangLamMoi = null);

  Future<String?> _refresh() async {
    final cur = state.value;
    if (cur == null) return null;
    // Dio riêng, không qua interceptor: refresh bị 401 mà đi qua hàng đợi lỗi
    // của chính interceptor đang chờ nó thì treo vĩnh viễn.
    final dio = Dio(BaseOptions(baseUrl: AppConfig.apiBaseUrl, connectTimeout: const Duration(seconds: 10)));
    try {
      final res = await dio.post('/auth/refresh', data: {'refresh_token': cur.refreshToken});
      final d = (res.data as Map)['data'] as Map;
      final s = cur.withTokens(d['access_token'] as String, d['refresh_token'] as String);
      if (_luuPhien) await _store.write(s);
      state = AsyncData(s);
      return s.accessToken;
    } on DioException catch (e) {
      // Mất mạng thì ném tiếp để giữ phiên; chỉ trả null (đăng xuất) khi máy
      // chủ thật sự từ chối refresh token.
      final loi = ApiException.fromDio(e);
      if (loi.laMatMang) throw loi;
      return null;
    }
  }

  @override
  Future<void> onSessionExpired() async {
    if (state.value != null) await logout();
  }
}
