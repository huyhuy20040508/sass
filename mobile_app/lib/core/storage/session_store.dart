import 'dart:convert';

import 'package:flutter_secure_storage/flutter_secure_storage.dart';

import '../../features/auth/session.dart';

/// Cửa hàng + tài khoản đã đăng nhập lần trước, để màn đăng nhập chỉ hỏi mật khẩu.
class RememberedLogin {
  const RememberedLogin({required this.shopCode, required this.username, required this.displayName});

  final String shopCode;
  final String username;
  final String displayName;

  Map<String, dynamic> toJson() => {'shop_code': shopCode, 'username': username, 'display_name': displayName};

  factory RememberedLogin.fromJson(Map<String, dynamic> j) => RememberedLogin(
    shopCode: (j['shop_code'] ?? '') as String,
    username: (j['username'] ?? '') as String,
    displayName: (j['display_name'] ?? '') as String,
  );
}

/// Cất phiên vào Keychain/Keystore — refresh token sống 7 ngày, không để ở chỗ đọc được.
class SessionStore {
  SessionStore([FlutterSecureStorage? storage]) : _s = storage ?? const FlutterSecureStorage();

  final FlutterSecureStorage _s;

  static const _kSession = 'session';
  static const _kRemembered = 'remembered_login';

  Future<Session?> read() async {
    final raw = await _s.read(key: _kSession);
    if (raw == null) return null;
    try {
      return Session.decode(raw);
    } catch (_) {
      await _s.delete(key: _kSession);
      return null;
    }
  }

  Future<void> write(Session s) => _s.write(key: _kSession, value: s.encode());

  Future<void> clear() => _s.delete(key: _kSession);

  /// Giữ lại cả sau khi đăng xuất; chỉ xoá khi người dùng tắt "Ghi nhớ đăng nhập".
  Future<RememberedLogin?> readRemembered() async {
    final raw = await _s.read(key: _kRemembered);
    if (raw == null) return null;
    try {
      return RememberedLogin.fromJson(jsonDecode(raw) as Map<String, dynamic>);
    } catch (_) {
      return null;
    }
  }

  Future<void> writeRemembered(RememberedLogin r) => _s.write(key: _kRemembered, value: jsonEncode(r.toJson()));

  Future<void> clearRemembered() => _s.delete(key: _kRemembered);
}
