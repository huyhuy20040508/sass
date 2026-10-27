import 'dart:async';
import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:selliotech/app/theme.dart';
import 'package:selliotech/core/api/api_client.dart';
import 'package:selliotech/core/net/online_provider.dart';
import 'package:selliotech/core/storage/session_store.dart';
import 'package:selliotech/features/auth/auth_controller.dart';
import 'package:selliotech/features/auth/login_screen.dart';
import 'package:selliotech/features/auth/session.dart';
import 'package:selliotech/features/workspace/choose_workspace_screen.dart';
import 'package:selliotech/main.dart';

class _MemoryStore extends SessionStore {
  _MemoryStore({this.session, this.remembered});

  Session? session;
  RememberedLogin? remembered;

  @override
  Future<Session?> read() async => session;
  @override
  Future<void> write(Session s) async => session = s;
  @override
  Future<void> clear() async => session = null;
  @override
  Future<RememberedLogin?> readRemembered() async => remembered;
  @override
  Future<void> writeRemembered(RememberedLogin r) async => remembered = r;
  @override
  Future<void> clearRemembered() async => remembered = null;
}

final _loginJson = {
  'access_token': 'a',
  'refresh_token': 'r',
  'token_type': 'Bearer',
  'expires_in': 900,
  'user': {
    'id': 1,
    'username': 'admin',
    'full_name': 'Quản trị viên',
    'role': {'id': 1, 'name': 'super_admin', 'display_name': 'Chủ cửa hàng'},
    'access_areas': 'quan_ly,thu_ngan',
  },
  'tenant': {'id': 2, 'code': 'test', 'name': 'Shop Test', 'status': 'active'},
  'chi_nhanh_id': 5,
  'chi_nhanh_ids': [5, 7],
};

/// Máy chủ giả cho /auth/shop-login: mật khẩu 12345678 đúng, "nhieu" bị chặn 429, còn lại sai.
class _FakeServer implements HttpClientAdapter {
  final bodies = <Map<String, dynamic>>[];
  Completer<void>? hold;

  @override
  Future<ResponseBody> fetch(RequestOptions o, Stream<Uint8List>? _, Future<void>? _) async {
    // Màn chọn nơi làm việc sau đăng nhập đọc hai đường này.
    if (o.path.endsWith('/auth/me')) return _json(200, {'success': true, 'data': _loginJson['user']});
    if (o.path.endsWith('/admin/chi-nhanh')) return _json(200, {'success': true, 'data': _branchesJson});
    final body = Map<String, dynamic>.from(o.data as Map);
    bodies.add(body);
    if (hold != null) await hold!.future;
    final (int code, Map<String, dynamic> res, Map<String, List<String>> extra) = switch (body['password']) {
      '12345678' => (200, {'success': true, 'data': _loginJson}, {}),
      'nhieu' => (
        429,
        {'success': false, 'message': 'Bạn thao tác quá nhanh. Vui lòng thử lại sau ít phút.'},
        {
          'retry-after': ['90'],
        },
      ),
      _ => (401, {'success': false, 'message': 'Mã cửa hàng, tên đăng nhập hoặc mật khẩu không đúng'}, {}),
    };
    return ResponseBody.fromString(
      jsonEncode(res),
      code,
      headers: {
        Headers.contentTypeHeader: ['application/json'],
        ...extra,
      },
    );
  }

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

const _branchesJson = [
  {'id': 5, 'name': 'Cửa hàng Lê Lợi', 'branch_type': 2},
  {'id': 7, 'name': 'Chi nhánh Quận 1', 'branch_type': 1},
];

const _remembered = RememberedLogin(shopCode: 'test', username: 'admin', displayName: 'Quản trị viên');

Future<void> _open(
  WidgetTester t, {
  required _MemoryStore store,
  _FakeServer? server,
  bool online = true,
  Size size = const Size(390, 844),
}) async {
  t.view.physicalSize = size;
  t.view.devicePixelRatio = 1;
  addTearDown(t.view.reset);
  await t.pumpWidget(
    ProviderScope(
      overrides: [
        sessionStoreProvider.overrideWithValue(store),
        onlineProvider.overrideWith((ref) => Stream.value(online)),
        apiClientProvider.overrideWith(
          (ref) => ApiClient(ref.read(authProvider.notifier), dio: Dio()..httpClientAdapter = server ?? _FakeServer()),
        ),
      ],
      child: const SelliotechApp(),
    ),
  );
  await _settle(t);
}

/// Màn cao có minh hoạ trôi lặp vô hạn nên không dùng pumpAndSettle được.
Future<void> _settle(WidgetTester t) async {
  for (var i = 0; i < 8; i++) {
    await t.pump(const Duration(milliseconds: 100));
  }
}

Finder get _submit => find.byKey(const Key('submit'));

Future<void> _type(WidgetTester t, String key, String text) async {
  await t.enterText(find.descendant(of: find.byKey(Key(key)), matching: find.byType(TextField)), text);
  await t.pump();
}

String _fieldText(WidgetTester t, String key) =>
    t.widget<TextField>(find.descendant(of: find.byKey(Key(key)), matching: find.byType(TextField))).controller!.text;

void main() {
  test('Session lưu rồi đọc lại giữ nguyên', () {
    final s = Session.fromLogin(_loginJson);
    final back = Session.decode(s.encode());
    expect(back.user.tenHienThi, 'Quản trị viên');
    expect(back.user.roleName, 'Chủ cửa hàng');
    expect(back.tenant.name, 'Shop Test');
    expect(back.chiNhanhId, 5);
    expect(back.chiNhanhIds, [5, 7]);
    expect(back.withTokens('a2', 'r2').tenant.code, 'test');
  });

  testWidgets('Lần đầu: đủ 3 ô, lời chào chung, minh hoạ hiện trên máy cao, Ghi nhớ bật sẵn', (t) async {
    await _open(t, store: _MemoryStore());

    expect(find.byKey(const Key('shop_code')), findsOneWidget);
    expect(find.text('Chào mừng trở lại'), findsOneWidget);
    expect(find.text('Đăng nhập để quản lý cửa hàng của bạn'), findsOneWidget);
    expect(find.text('Doanh thu hôm nay'), findsOneWidget);
    expect(find.text('Ghi nhớ đăng nhập'), findsOneWidget);
    expect(find.text('Quên mật khẩu?'), findsOneWidget);
    expect(find.text('Liên hệ Sellio'), findsOneWidget);

    // Màn này giữ bộ màu tím + vàng của logo, dù theme gốc của app nay là navy
    // của khu làm việc (lỗi 94).
    final mau = Theme.of(t.element(find.byKey(const Key('shop_code')))).colorScheme;
    expect(mau.primary, AppColors.brand);
  });

  testWidgets('Đã nhớ cửa hàng: thẻ cửa hàng + lời chào theo tên; Đổi thì về ô nhập mã', (t) async {
    await _open(t, store: _MemoryStore(remembered: _remembered));

    expect(find.byKey(const Key('shop_code')), findsNothing);
    expect(find.text('TEST'), findsOneWidget);
    expect(find.text('TE'), findsOneWidget);
    expect(find.text('Chào Quản trị viên,'), findsOneWidget);
    expect(find.text('Mừng trở lại — nhập mật khẩu để tiếp tục'), findsOneWidget);
    expect(_fieldText(t, 'username'), 'admin');

    await t.tap(find.text('Đổi'));
    await _settle(t);
    expect(find.byKey(const Key('shop_code')), findsOneWidget);
    expect(_fieldText(t, 'shop_code'), '');
    FocusManager.instance.primaryFocus?.unfocus();
    await _settle(t);
    expect(find.text('Chào mừng trở lại'), findsOneWidget);
  });

  testWidgets('Bàn phím hiện thì thu đầu tím; tắt bàn phím (Back) thì về bố cục thường, bỏ focus', (t) async {
    await _open(t, store: _MemoryStore(remembered: _remembered));
    final heroH = t.getTopLeft(find.byKey(const Key('username'))).dy;

    await t.tap(find.byKey(const Key('password')));
    t.view.viewInsets = const FakeViewPadding(bottom: 300);
    await _settle(t);
    expect(find.text('Chào Quản trị viên,'), findsNothing);

    // Back trên Android: bàn phím tắt nhưng ô vẫn giữ focus.
    t.view.viewInsets = FakeViewPadding.zero;
    await _settle(t);
    expect(find.text('Chào Quản trị viên,'), findsOneWidget);
    expect(t.getTopLeft(find.byKey(const Key('username'))).dy, heroH, reason: 'không chừa khoảng trống');
    expect(FocusManager.instance.primaryFocus?.context?.findAncestorWidgetOfExactType<TextField>(), isNull);
  });

  testWidgets('Chỉ focus mà không có bàn phím mềm (bàn phím rời) thì không thu đầu tím', (t) async {
    await _open(t, store: _MemoryStore(remembered: _remembered));
    await t.tap(find.byKey(const Key('password')));
    await _settle(t);
    expect(find.text('Chào Quản trị viên,'), findsOneWidget);
  });

  testWidgets('Thiếu thông tin: báo dưới từng ô, gõ vào thì hết báo, không gửi API', (t) async {
    final server = _FakeServer();
    await _open(t, store: _MemoryStore(), server: server);

    await t.tap(_submit);
    await _settle(t);
    expect(find.text('Nhập mã cửa hàng'), findsOneWidget);
    expect(find.text('Nhập tên đăng nhập'), findsNWidgets(2), reason: 'placeholder + câu báo');
    expect(find.text('Nhập mật khẩu'), findsNWidgets(2));
    expect(server.bodies, isEmpty);

    await _type(t, 'shop_code', 'abc');
    expect(find.text('Nhập mã cửa hàng'), findsNothing);
  });

  testWidgets('Mã cửa hàng tự viết hoa và bỏ khoảng trắng', (t) async {
    await _open(t, store: _MemoryStore());
    await _type(t, 'shop_code', 'te st');
    expect(_fieldText(t, 'shop_code'), 'TEST');
  });

  testWidgets('Sai mật khẩu: hiện câu của máy chủ, xoá ô mật khẩu, minh hoạ ẩn đi', (t) async {
    final server = _FakeServer();
    await _open(
      t,
      store: _MemoryStore(remembered: _remembered),
      server: server,
    );

    await _type(t, 'password', 'sai');
    await t.tap(_submit);
    await _settle(t);

    expect(server.bodies.single, {'shop_code': 'TEST', 'username': 'admin', 'password': 'sai'});
    expect(find.text('Không đăng nhập được'), findsOneWidget);
    expect(find.text('Mã cửa hàng, tên đăng nhập hoặc mật khẩu không đúng'), findsOneWidget);
    expect(_fieldText(t, 'password'), '');
    expect(find.text('Doanh thu hôm nay'), findsNothing);
  });

  testWidgets('Bị khoá tạm (429): đếm ngược theo Retry-After, nút khoá không gửi được', (t) async {
    final server = _FakeServer();
    await _open(
      t,
      store: _MemoryStore(remembered: _remembered),
      server: server,
    );

    await _type(t, 'password', 'nhieu');
    await t.tap(_submit);
    await _settle(t);

    expect(find.text('Thử sai quá nhiều lần'), findsOneWidget);
    expect(find.text('Thử lại sau 01:30'), findsOneWidget);
    await t.pump(const Duration(seconds: 1));
    expect(find.text('Thử lại sau 01:29'), findsOneWidget);

    await _type(t, 'password', '12345678');
    await t.tap(_submit);
    await _settle(t);
    expect(server.bodies, hasLength(1));

    await t.pump(const Duration(seconds: 90));
    expect(find.text('Thử sai quá nhiều lần'), findsNothing);
    expect(find.text('Đăng nhập'), findsOneWidget);
  });

  testWidgets('Mất mạng: dải vàng báo, bấm đăng nhập không gửi API', (t) async {
    final server = _FakeServer();
    await _open(
      t,
      store: _MemoryStore(remembered: _remembered),
      server: server,
      online: false,
    );

    expect(find.text('Mất kết nối — kiểm tra Wi-Fi hoặc 4G'), findsOneWidget);
    await _type(t, 'password', '12345678');
    await t.tap(_submit);
    await _settle(t);
    expect(find.text('Chưa có kết nối mạng. Kết nối lại rồi thử lần nữa.'), findsOneWidget);
    expect(server.bodies, isEmpty);
  });

  testWidgets('Quên mật khẩu: mở ngăn kéo, Đóng thì tắt', (t) async {
    await _open(t, store: _MemoryStore());

    await t.tap(find.text('Quên mật khẩu?'));
    await _settle(t);
    expect(find.text('Gửi email hỗ trợ'), findsOneWidget);
    expect(find.textContaining('quản trị viên (Super Admin)', findRichText: true), findsOneWidget);

    await t.tap(find.text('Đóng'));
    await _settle(t);
    expect(find.text('Gửi email hỗ trợ'), findsNothing);
  });

  testWidgets('Đang đăng nhập: nút đổi chữ, gửi lần nữa không tạo request thứ hai', (t) async {
    final server = _FakeServer()..hold = Completer();
    await _open(
      t,
      store: _MemoryStore(remembered: _remembered),
      server: server,
    );

    await _type(t, 'password', '12345678');
    await t.tap(_submit);
    await _settle(t);
    expect(find.text('Đang đăng nhập…'), findsOneWidget);
    await t.tap(_submit);
    await _settle(t);
    expect(server.bodies, hasLength(1));

    server.hold!.complete();
    await _settle(t);
    await t.pump(LoginScreen.successDelay);
    await _settle(t);
  });

  testWidgets('Thành công: màn chúc mừng rồi vào màn chính, lưu phiên + nhớ cửa hàng', (t) async {
    final store = _MemoryStore();
    await _open(t, store: store);

    await _type(t, 'shop_code', 'test');
    await _type(t, 'username', 'admin');
    await _type(t, 'password', '12345678');
    await t.tap(_submit);
    await _settle(t);

    expect(find.text('Đăng nhập thành công'), findsOneWidget);
    expect(find.text('Đang vào Quản trị…'), findsOneWidget);
    await t.pump(LoginScreen.successDelay);
    await _settle(t);

    expect(find.text('Shop Test'), findsOneWidget);
    expect(store.session?.accessToken, 'a');
    expect(store.remembered?.shopCode, 'TEST');
    expect(store.remembered?.displayName, 'Quản trị viên');
  });

  testWidgets('Tắt Ghi nhớ: không lưu phiên, quên luôn cửa hàng đã nhớ', (t) async {
    final store = _MemoryStore(remembered: _remembered);
    await _open(t, store: store);

    await t.tap(find.text('Ghi nhớ đăng nhập'));
    await _type(t, 'password', '12345678');
    await t.tap(_submit);
    await _settle(t);
    await t.pump(LoginScreen.successDelay);
    await _settle(t);

    expect(find.text('Shop Test'), findsOneWidget);
    expect(store.session, isNull);
    expect(store.remembered, isNull);
  });

  testWidgets('Máy thấp 360×640: bỏ minh hoạ và dòng phụ, không tràn', (t) async {
    await _open(t, store: _MemoryStore(), size: const Size(360, 640));
    expect(find.text('Doanh thu hôm nay'), findsNothing);
    expect(find.text('Đăng nhập để quản lý cửa hàng của bạn'), findsNothing);
    expect(find.text('Chào mừng trở lại'), findsOneWidget);
    expect(t.takeException(), isNull);
  });

  testWidgets('Bàn phím mở trên máy thấp: cuộn được tới nút, không tràn', (t) async {
    await _open(t, store: _MemoryStore(), size: const Size(360, 640));
    await t.tap(find.byKey(const Key('username')));
    t.view.viewInsets = const FakeViewPadding(bottom: 300);
    await _settle(t);
    expect(t.takeException(), isNull);
    await t.ensureVisible(_submit);
    await t.pump();
    expect(t.getRect(_submit).bottom, lessThanOrEqualTo(640 - 300));
  });

  testWidgets('Đã có phiên: vào thẳng màn chính, đăng xuất thì về đăng nhập với cửa hàng đã nhớ', (t) async {
    final store = _MemoryStore(session: Session.fromLogin(_loginJson), remembered: _remembered);
    await _open(t, store: store);

    // Có phiên thì vào màn chọn nơi làm việc trước, chọn khu rồi mới tới màn chính.
    expect(find.text('Vào khu vực'), findsOneWidget);
    await t.tap(find.byKey(const Key('area_thu_ngan')));
    await t.pump(ChooseWorkspaceScreen.goingDelay);
    await _settle(t);
    // Màn chờ của khu Thu ngân: tên cửa hàng + TÊN chi nhánh đang làm ở đầu
    // màn, người đang đăng nhập ghi một dòng ở cuối (lỗi 88).
    expect(find.text('Shop Test'), findsOneWidget);
    expect(find.text('Cửa hàng Lê Lợi'), findsOneWidget);
    expect(find.text('Đang đăng nhập: Quản trị viên · Chủ cửa hàng'), findsOneWidget);

    await t.tap(find.byTooltip('Đăng xuất'));
    await _settle(t);
    expect(find.text('Chào Quản trị viên,'), findsOneWidget);
    expect(store.session, isNull);
  });
}
