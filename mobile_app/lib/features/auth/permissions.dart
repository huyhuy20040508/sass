import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_exception.dart';
import 'auth_controller.dart';

/// Quyền của người đang đăng nhập (GET /admin/quyen-cua-toi). Chỉ để ẩn nút — chốt
/// thật vẫn nằm ở từng đường của API.
class Perms {
  const Perms({this.all = false, this.codes = const {}});

  /// Không đọc được quyền: cứ hiện nút, API sẽ chặn nếu thiếu quyền.
  static const unknown = Perms(all: true);

  final bool all;
  final Set<String> codes;

  bool can(String code) => all || codes.contains(code);
}

/// Mã quyền mà màn Hàng hoá dùng — khớp router.go.
abstract final class Quyen {
  static const themSanPham = 'san-pham.them';
  static const suaSanPham = 'san-pham.sua';
  static const xoaSanPham = 'san-pham.xoa';
  static const giaVon = 'ton-kho.gia-von';
  static const suaTonKho = 'ton-kho.sua';
  static const themNhom = 'danh-muc.them';
  static const suaNhom = 'danh-muc.sua';
  static const xoaNhom = 'danh-muc.xoa';
}

final permsProvider = FutureProvider<Perms>((ref) async {
  // Đổi tài khoản / đăng xuất thì đọc lại.
  final user = ref.watch(authProvider.select((s) => s.value?.user.id));
  if (user == null) return Perms.unknown;
  try {
    final d = await ref.read(apiClientProvider).get<Map<String, dynamic>>('/admin/quyen-cua-toi');
    return Perms(
      all: d['toan_quyen'] == true,
      codes: {for (final q in (d['quyen'] as List?) ?? const []) '$q'},
    );
  } on ApiException {
    return Perms.unknown;
  }
});

/// Quyền hiện có; đang tải thì coi như đủ quyền để không nháy ẩn/hiện nút.
Perms permsOf(WidgetRef ref) => ref.watch(permsProvider).value ?? Perms.unknown;
