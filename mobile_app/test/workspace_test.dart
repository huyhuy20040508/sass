import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:selliotech/core/api/api_client.dart';
import 'package:selliotech/core/net/online_provider.dart';
import 'package:selliotech/core/storage/session_store.dart';
import 'package:selliotech/features/auth/auth_controller.dart';
import 'package:selliotech/features/auth/session.dart';
import 'package:selliotech/features/workspace/choose_workspace_screen.dart';
import 'package:selliotech/features/workspace/workspace.dart';
import 'package:selliotech/features/workspace/workspace_widgets.dart';
import 'package:selliotech/main.dart';

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

/// API giả cho /auth/me và /admin/chi-nhanh; ghi lại header X-Chi-Nhanh của mỗi lượt.
class _Api implements HttpClientAdapter {
  _Api({this.accessAreas = 'quan_ly,thu_ngan', this.branches = _ba, this.branchCode = 200, this.meCode = 200});

  String? accessAreas;
  List<Map<String, Object>> branches;
  int branchCode;
  int meCode;
  final calls = <String>[];

  @override
  Future<ResponseBody> fetch(RequestOptions o, Stream<Uint8List>? _, Future<void>? _) async {
    calls.add(o.path);
    if (o.path.endsWith('/auth/me')) {
      if (meCode == 0) throw DioException.connectionError(requestOptions: o, reason: 'offline');
      if (meCode != 200) return _err(meCode);
      return _ok({..._user, 'access_areas': accessAreas});
    }
    if (o.path.endsWith('/admin/chi-nhanh')) {
      if (branchCode != 200) return _err(branchCode);
      return _ok(branches);
    }
    return _err(404);
  }

  ResponseBody _ok(Object data) => _json(200, {'success': true, 'data': data});

  ResponseBody _err(int code) => _json(code, {
    'success': false,
    'message': code == 403 && meCode == 403
        ? 'Cửa hàng đã hết hạn sử dụng — vui lòng gia hạn để tiếp tục làm việc'
        : 'Bạn không có quyền',
    if (meCode == 403) 'errors': {'ma': 'CUA_HANG_KHOA'},
  });

  ResponseBody _json(int code, Object body) => ResponseBody.fromString(
    jsonEncode(body),
    code,
    headers: {
      Headers.contentTypeHeader: ['application/json'],
    },
  );

  @override
  void close({bool force = false}) {}
}

const _user = {
  'id': 1,
  'username': 'an',
  'full_name': 'Nguyễn Văn An',
  'role': {'id': 1, 'name': 'super_admin', 'display_name': 'Chủ cửa hàng'},
};

const _ba = [
  {'id': 3, 'name': 'Chi nhánh Thủ Đức', 'branch_type': 1},
  {'id': 1, 'name': 'Cửa hàng Lê Lợi', 'branch_type': 2},
  {'id': 2, 'name': 'Chi nhánh Quận 1', 'branch_type': 1},
];

Session _session({int? chiNhanhId, List<int> assigned = const [], String role = 'super_admin'}) => Session.fromLogin({
  'access_token': 'a',
  'refresh_token': 'r',
  'user': {
    ..._user,
    'role': {'id': 1, 'name': role, 'display_name': 'Chủ cửa hàng'},
  },
  'tenant': {'id': 2, 'code': 'mebe', 'name': 'Cửa hàng Mẹ & Bé Hương Thảo'},
  'chi_nhanh_id': chiNhanhId,
  'chi_nhanh_ids': assigned,
});

Future<_MemoryStore> _open(WidgetTester t, _Api api, {Session? session, Size size = const Size(390, 844)}) async {
  t.view.physicalSize = size;
  t.view.devicePixelRatio = 1;
  addTearDown(t.view.reset);
  final store = _MemoryStore(session ?? _session());
  await t.pumpWidget(
    ProviderScope(
      overrides: [
        sessionStoreProvider.overrideWithValue(store),
        onlineProvider.overrideWith((ref) => Stream.value(true)),
        apiClientProvider.overrideWith(
          (ref) => ApiClient(ref.read(authProvider.notifier), dio: Dio()..httpClientAdapter = api),
        ),
      ],
      child: const SelliotechApp(),
    ),
  );
  await _settle(t);
  return store;
}

Future<void> _settle(WidgetTester t) async {
  for (var i = 0; i < 10; i++) {
    await t.pump(const Duration(milliseconds: 100));
  }
}

void main() {
  group('dữ liệu', () {
    test('trụ sở = id nhỏ nhất trong các chi nhánh loại Công ty, đứng đầu; còn lại giữ thứ tự API', () {
      final b = parseBranches([
        {'id': 3, 'name': 'C', 'branch_type': 1},
        {'id': 4, 'name': 'D', 'branch_type': 2},
        {'id': 1, 'name': 'A', 'branch_type': 1},
        {'id': 2, 'name': 'B', 'branch_type': 2},
      ]);
      expect(b.map((x) => x.id), [2, 3, 4, 1]);
      expect(b.where((x) => x.isHq).map((x) => x.id), [2]);
    });

    test('không có chi nhánh loại Công ty thì trụ sở là id nhỏ nhất', () {
      final b = parseBranches([
        {'id': 9, 'name': 'X'},
        {'id': 4, 'name': 'Y'},
      ]);
      expect(b.first.id, 4);
      expect(b.first.isHq, isTrue);
    });

    test('nhân viên được phân chi nhánh chỉ thấy chi nhánh của mình', () {
      expect(parseBranches(_ba, assigned: [2, 3]).map((x) => x.id), [2, 3]);
    });

    test('cửa vào đọc access_areas; thiếu thì suy từ vai trò như web_Shop', () {
      expect(WorkspaceArea.forUser(accessAreas: 'quan_ly,thu_ngan'), WorkspaceArea.values);
      expect(WorkspaceArea.forUser(accessAreas: ' thu_ngan '), [WorkspaceArea.thuNgan]);
      expect(WorkspaceArea.forUser(roleCode: 'admin'), WorkspaceArea.values);
      expect(WorkspaceArea.forUser(roleCode: 'staff'), [WorkspaceArea.thuNgan]);
      expect(WorkspaceArea.forUser(roleCode: 'khach'), isEmpty);
    });

    test('chữ viết tắt, lời chào và ca theo giờ', () {
      expect(initialsOf('Nguyễn Văn An'), 'NA');
      expect(initialsOf('admin'), 'AD');
      expect(greetingFor(DateTime(2026, 1, 1, 9)), 'Chào buổi sáng');
      expect(greetingFor(DateTime(2026, 1, 1, 12)), 'Chào buổi trưa');
      expect(greetingFor(DateTime(2026, 1, 1, 15)), 'Chào buổi chiều');
      expect(greetingFor(DateTime(2026, 1, 1, 20)), 'Chào buổi tối');
      expect(clockFor(DateTime(2026, 1, 1, 7, 5)), '07:05 · Ca sáng');
      expect(clockFor(DateTime(2026, 1, 1, 20, 41)), '20:41 · Ca tối');
      expect(clockFor(DateTime(2026, 1, 1, 23, 0)), '23:00 · Ca khuya');
    });
  });

  testWidgets('2 khu + 1 chi nhánh: không có thẻ chi nhánh, hai thẻ khu, tên tiệm + người', (t) async {
    await _open(t, _Api(branches: [_ba[1]]));

    expect(find.text('Cửa hàng Mẹ & Bé Hương Thảo'), findsOneWidget);
    expect(find.textContaining('Nguyễn Văn An'), findsOneWidget);
    expect(find.text('NA'), findsOneWidget);
    expect(find.byKey(const Key('branch_card')), findsNothing);
    expect(find.byKey(const Key('area_quan_ly')), findsOneWidget);
    expect(find.byKey(const Key('area_thu_ngan')), findsOneWidget);
    expect(find.textContaining('Chọn nhầm cũng không sao'), findsOneWidget);
    expect(t.takeException(), isNull);
  });

  testWidgets('nhiều chi nhánh: thẻ hiện trụ sở; Đổi → chọn chi nhánh khác → vào Thu ngân mang chi nhánh đó', (
    t,
  ) async {
    final api = _Api();
    final store = await _open(t, api);

    expect(find.text('Cửa hàng Lê Lợi'), findsOneWidget);
    expect(find.text('Trụ sở'), findsOneWidget);

    await t.tap(find.byKey(const Key('branch_card')));
    await _settle(t);
    expect(find.text('Chọn chi nhánh'), findsOneWidget);
    expect(find.byType(BranchOption), findsNWidgets(3));

    await t.tap(find.byKey(const Key('branch_2')));
    await _settle(t);
    expect(find.text('Chọn chi nhánh'), findsNothing);
    expect(find.text('Chi nhánh Quận 1'), findsOneWidget);

    await t.tap(find.byKey(const Key('area_thu_ngan')));
    await t.pump();
    expect(find.text('Đang vào Thu ngân'), findsOneWidget);
    expect(find.text('Chi nhánh Quận 1'), findsWidgets);
    await t.pump(ChooseWorkspaceScreen.goingDelay);
    await _settle(t);

    expect(store.session?.chiNhanhId, 2);
    expect(find.text('Thu ngân'), findsOneWidget, reason: 'màn chính của khu Thu ngân');
  });

  testWidgets('giữ chi nhánh đang làm trong phiên nếu còn trong danh sách', (t) async {
    await _open(t, _Api(), session: _session(chiNhanhId: 3));
    expect(find.text('Chi nhánh Thủ Đức'), findsOneWidget);
  });

  testWidgets('thu ngân 1 khu, không xem được chi nhánh (403): vào thẳng Thu ngân, không qua màn chọn', (t) async {
    await _open(
      t,
      _Api(accessAreas: 'thu_ngan', branchCode: 403),
      session: _session(role: 'staff'),
    );
    expect(find.text('Vào khu vực'), findsNothing);
    expect(find.text('Thu ngân'), findsOneWidget);
  });

  testWidgets('1 khu nhưng nhiều chi nhánh: vẫn hỏi chi nhánh, chỉ một thẻ khu + câu đăng xuất', (t) async {
    await _open(t, _Api(accessAreas: 'thu_ngan'));
    expect(find.byKey(const Key('branch_card')), findsOneWidget);
    expect(find.byKey(const Key('area_quan_ly')), findsNothing);
    expect(find.text('Sai tài khoản hay sai tiệm thì đăng xuất rồi vào lại.'), findsOneWidget);
  });

  testWidgets('nhân viên chỉ được phân 1 chi nhánh: không có thẻ chi nhánh', (t) async {
    await _open(t, _Api(), session: _session(assigned: [2]));
    expect(find.byKey(const Key('branch_card')), findsNothing);
  });

  testWidgets('đăng xuất: hỏi lại; Ở lại thì đóng, Đăng xuất thì về màn đăng nhập', (t) async {
    final store = await _open(t, _Api());

    await t.tap(find.byTooltip('Đăng xuất'));
    await _settle(t);
    expect(find.text('Đăng xuất?'), findsOneWidget);
    await t.tap(find.text('Ở lại'));
    await _settle(t);
    expect(find.text('Đăng xuất?'), findsNothing);
    expect(store.session, isNotNull);

    await t.tap(find.byTooltip('Đăng xuất'));
    await _settle(t);
    await t.tap(find.widgetWithText(SheetButton, 'Đăng xuất'));
    await _settle(t);
    expect(store.session, isNull);
    expect(find.text('Đăng nhập'), findsOneWidget);
  });

  testWidgets('cửa hàng hết hạn (CUA_HANG_KHOA): báo rõ, không hiện thẻ khu', (t) async {
    await _open(t, _Api(meCode: 403, branchCode: 403));
    expect(find.textContaining('Cửa hàng đã hết hạn sử dụng'), findsOneWidget);
    expect(find.byKey(const Key('area_quan_ly')), findsNothing);
  });

  testWidgets('mất mạng: báo lỗi + Thử lại tải lại được', (t) async {
    final api = _Api(meCode: 0);
    await _open(t, api);
    expect(find.text('Thử lại'), findsOneWidget);

    api.meCode = 200;
    await t.tap(find.text('Thử lại'));
    await _settle(t);
    expect(find.byKey(const Key('area_quan_ly')), findsOneWidget);
  });

  testWidgets('màn chính: nút đổi khu vực đưa về màn chọn', (t) async {
    await _open(t, _Api(branches: [_ba[1]]));
    await t.tap(find.byKey(const Key('area_quan_ly')));
    await t.pump(ChooseWorkspaceScreen.goingDelay);
    await _settle(t);
    expect(find.text('Vào khu vực'), findsNothing);

    await t.tap(find.widgetWithText(TextButton, 'Quản trị'));
    await _settle(t);
    expect(find.text('Vào khu vực'), findsOneWidget);
  });

  testWidgets('máy thấp 360×640, nhiều chi nhánh, tên dài: không tràn, cuộn tới thẻ cuối', (t) async {
    await _open(
      t,
      _Api(
        branches: [
          {'id': 1, 'name': 'Chi nhánh Biên Hoà – Đồng Nai, cơ sở 2 khu công nghiệp Amata', 'branch_type': 2},
          ..._ba,
        ],
      ),
      size: const Size(360, 640),
    );
    expect(t.takeException(), isNull);
    await t.ensureVisible(find.byKey(const Key('area_thu_ngan')));
    await t.pump();
    expect(t.takeException(), isNull);
  });
}
