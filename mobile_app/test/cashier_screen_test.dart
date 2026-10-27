import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:selliotech/app/theme.dart';
import 'package:selliotech/features/auth/auth_controller.dart';
import 'package:selliotech/core/storage/session_store.dart';
import 'package:selliotech/features/auth/session.dart';
import 'package:selliotech/features/cashier/cashier_screen.dart';
import 'package:selliotech/features/workspace/workspace.dart';

/// Màn chờ của khu Thu ngân — lỗi 88.
///
/// Khu Thu ngân đang dùng lại khung dựng thử hồi 16/10: lưới sáu ô "Sắp có" và
/// nút "Kiểm tra kết nối API". Phát hành APK lúc ấy thì thu ngân mở app ra gặp
/// đúng màn của lập trình viên.
Session _phien({int? chiNhanh = 2, bool khoa = false}) => Session.fromLogin({
  'access_token': 'a',
  'refresh_token': 'r',
  'user': {
    'id': 7,
    'username': 'thungan',
    'full_name': 'Nguyễn Thu Hà',
    'role': {'id': 3, 'name': 'staff', 'display_name': 'Thu ngân'},
    'access_areas': 'thu_ngan',
  },
  'tenant': {'id': 1, 'code': 'test', 'name': 'Cửa hàng Lê Lợi'},
  'chi_nhanh_id': chiNhanh,
  'cua_hang_khoa': khoa,
});

class _MemoryStore extends SessionStore {
  _MemoryStore(this.session);

  Session? session;

  @override
  Future<Session?> read() async => session;
  @override
  Future<void> write(Session s) async => session = s;
  @override
  Future<void> clear() async => session = null;
  @override
  Future<RememberedLogin?> readRemembered() async => null;
  @override
  Future<void> writeRemembered(RememberedLogin r) async {}
  @override
  Future<void> clearRemembered() async {}
}

Future<void> _bay(WidgetTester t, {Session? phien, List<Branch> chiNhanh = const []}) async {
  await t.pumpWidget(
    ProviderScope(
      overrides: [
        sessionStoreProvider.overrideWithValue(_MemoryStore(phien ?? _phien())),
        workspaceOptionsProvider.overrideWith(
          (ref) async => WorkspaceOptions(areas: const [WorkspaceArea.thuNgan], branches: chiNhanh),
        ),
      ],
      child: const MaterialApp(home: CashierScreen()),
    ),
  );
  await t.pumpAndSettle();
}

void main() {
  testWidgets('không còn khung dựng thử: hết lưới "Sắp có" và nút kiểm tra API', (t) async {
    await _bay(t);

    expect(find.text('Kiểm tra kết nối API'), findsNothing);
    expect(find.text('Sắp có'), findsNothing);
    // Nói thẳng đang thiếu gì và chỉ chỗ làm tạm.
    expect(find.text('Màn Thu ngân cho điện thoại đang được làm'), findsOneWidget);
    expect(find.textContaining('bản web ở máy tính'), findsOneWidget);
    expect(t.takeException(), isNull);
  });

  testWidgets('đầu màn navy, có tên cửa hàng và TÊN chi nhánh', (t) async {
    await _bay(t, chiNhanh: const [Branch(id: 2, name: 'Chi nhánh Quận 1')]);

    expect(t.widget<AppBar>(find.byType(AppBar)).backgroundColor, DashColors.navy);
    expect(find.text('Cửa hàng Lê Lợi'), findsOneWidget);
    expect(find.text('Chi nhánh Quận 1'), findsOneWidget);
  });

  /// Thu ngân thuần bị API trả 403 khi xem danh sách chi nhánh, nên có lúc chỉ
  /// còn số hiệu — vẫn hơn là bỏ trống.
  testWidgets('không tra được tên thì ghi số hiệu chi nhánh', (t) async {
    await _bay(t);
    expect(find.text('Chi nhánh #2'), findsOneWidget);
  });

  /// Tiệm một chi nhánh hay đặt tên chi nhánh trùng tên cửa hàng.
  testWidgets('tên chi nhánh trùng tên cửa hàng thì chỉ ghi một lần', (t) async {
    await _bay(t, chiNhanh: const [Branch(id: 2, name: 'Cửa hàng Lê Lợi')]);
    expect(find.text('Cửa hàng Lê Lợi'), findsOneWidget);
  });

  testWidgets('không thuộc chi nhánh nào thì không bày dòng rỗng', (t) async {
    await _bay(t, phien: _phien(chiNhanh: null));
    expect(find.textContaining('Chi nhánh'), findsNothing);
  });

  /// Đường ra vẫn còn: đổi khu và đăng xuất — khu Thu ngân KHÔNG bị giấu đi,
  /// tài khoản chỉ có cửa thu_ngan vẫn phải có chỗ để tới.
  testWidgets('giữ nút đổi nơi làm việc và đăng xuất', (t) async {
    await _bay(t);
    expect(find.widgetWithText(TextButton, 'Thu ngân'), findsOneWidget);
    expect(find.byTooltip('Đăng xuất'), findsOneWidget);
  });

  testWidgets('cửa hàng hết hạn thì vẫn báo ngay đầu màn', (t) async {
    await _bay(t, phien: _phien(khoa: true));
    expect(find.textContaining('hết hạn hợp đồng'), findsOneWidget);
  });
}
