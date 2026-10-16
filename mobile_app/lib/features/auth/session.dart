import 'dart:convert';

/// Phiên đăng nhập của nhân viên một cửa hàng (đường /auth/shop-login).
class Session {
  const Session({
    required this.accessToken,
    required this.refreshToken,
    required this.user,
    required this.tenant,
    this.chiNhanhId,
    this.chiNhanhIds = const [],
    this.cuaHangKhoa = false,
  });

  final String accessToken;
  final String refreshToken;
  final SessionUser user;
  final SessionTenant tenant;
  final int? chiNhanhId;
  final List<int> chiNhanhIds;
  final bool cuaHangKhoa;

  /// Đọc từ AuthResponse lúc đăng nhập.
  factory Session.fromLogin(Map<String, dynamic> d) => Session(
    accessToken: d['access_token'] as String,
    refreshToken: d['refresh_token'] as String,
    user: SessionUser.fromJson(d['user'] as Map<String, dynamic>),
    tenant: SessionTenant.fromJson(d['tenant'] as Map<String, dynamic>),
    chiNhanhId: (d['chi_nhanh_id'] as num?)?.toInt(),
    chiNhanhIds: [for (final v in (d['chi_nhanh_ids'] as List? ?? const [])) (v as num).toInt()],
    cuaHangKhoa: d['cua_hang_khoa'] == true,
  );

  /// Làm mới token không trả lại tenant/chi nhánh nên giữ nguyên phần đó của phiên cũ.
  Session withTokens(String access, String refresh) => Session(
    accessToken: access,
    refreshToken: refresh,
    user: user,
    tenant: tenant,
    chiNhanhId: chiNhanhId,
    chiNhanhIds: chiNhanhIds,
    cuaHangKhoa: cuaHangKhoa,
  );

  Map<String, dynamic> toJson() => {
    'access_token': accessToken,
    'refresh_token': refreshToken,
    'user': user.toJson(),
    'tenant': tenant.toJson(),
    'chi_nhanh_id': chiNhanhId,
    'chi_nhanh_ids': chiNhanhIds,
    'cua_hang_khoa': cuaHangKhoa,
  };

  String encode() => jsonEncode(toJson());

  static Session decode(String raw) => Session.fromLogin(jsonDecode(raw) as Map<String, dynamic>);
}

class SessionUser {
  const SessionUser({required this.id, required this.username, required this.fullName, this.roleName});

  final int id;
  final String username;
  final String fullName;
  final String? roleName;

  factory SessionUser.fromJson(Map<String, dynamic> j) {
    final role = j['role'];
    return SessionUser(
      id: (j['id'] as num).toInt(),
      username: (j['username'] ?? '') as String,
      fullName: (j['full_name'] ?? '') as String,
      roleName: role is Map ? (role['display_name'] ?? role['name']) as String? : j['role_name'] as String?,
    );
  }

  Map<String, dynamic> toJson() => {'id': id, 'username': username, 'full_name': fullName, 'role_name': roleName};

  String get tenHienThi => fullName.isNotEmpty ? fullName : username;
}

class SessionTenant {
  const SessionTenant({required this.id, required this.code, required this.name});

  final int id;
  final String code;
  final String name;

  factory SessionTenant.fromJson(Map<String, dynamic> j) =>
      SessionTenant(id: (j['id'] as num).toInt(), code: (j['code'] ?? '') as String, name: (j['name'] ?? '') as String);

  Map<String, dynamic> toJson() => {'id': id, 'code': code, 'name': name};
}
