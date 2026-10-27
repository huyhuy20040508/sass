import 'dart:convert';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_riverpod/misc.dart' show Override;
import 'package:flutter_test/flutter_test.dart';
import 'package:selliotech/core/api/api_client.dart';
import 'package:selliotech/core/net/online_provider.dart';
import 'package:selliotech/core/storage/session_store.dart';
import 'package:selliotech/features/admin/taxes/taxes_data.dart';
import 'package:selliotech/features/admin/taxes/taxes_screen.dart';
import 'package:selliotech/features/admin/dashboard/dashboard_data.dart' show clockProvider;
import 'package:selliotech/features/admin/shell/more_menu.dart';
import 'package:selliotech/features/admin/shell/shell_widgets.dart';
import 'package:selliotech/features/auth/auth_controller.dart';
import 'package:selliotech/features/auth/session.dart';
import 'package:selliotech/features/workspace/choose_workspace_screen.dart';
import 'package:selliotech/main.dart';
import 'package:shared_preferences/shared_preferences.dart';

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

Session _session() => Session.fromLogin({
  'access_token': 'a',
  'refresh_token': 'r',
  'user': {
    'id': 1,
    'username': 'admin',
    'full_name': 'Quản trị viên',
    'access_areas': 'quan_ly',
    'role': {'id': 1, 'name': 'super_admin', 'display_name': 'Super Admin'},
  },
  'tenant': {'id': 2, 'code': 'test', 'name': 'Cửa hàng chính'},
});

Map<String, dynamic> _tax(int id, String loai, String ten, List<int> muc, List<(int, String)> chon, {bool on = true}) =>
    {
      'id': id,
      'loai': loai,
      'ten': ten,
      'mo_ta': 'Mô tả $ten.',
      'muc': muc,
      'chon_duoc': [
        for (final (v, n) in chon) {'gia_tri': v, 'nhan': n},
      ],
      'is_active': on,
    };

const _pct = [(0, '0%'), (5, '5%'), (8, '8%'), (10, '10%')];

/// API giả: 4 loại như dev; thuế tiêu thụ đặc biệt đang tắt. 3 sp khai 10%, 1 sp 0%; 1 nhóm 10%, 3 nhóm 0%.
class _Api implements HttpClientAdapter {
  _Api({this.perms, this.rejectId});

  final List<String>? perms;

  /// Loại mà API từ chối lưu mức (422 muc).
  final int? rejectId;
  final calls = <RequestOptions>[];

  final taxes = [
    _tax(1, 'mac-dinh', 'Thuế mặc định', [0, 8, 10], [..._pct, (-1, 'KCT'), (-2, 'KKKNT')]),
    _tax(2, 'tieu-thu-dac-biet', 'Thuế tiêu thụ đặc biệt', [12], [(12, '12%'), (15, '15%')], on: false),
    _tax(3, 'mua-hang', 'Thuế trên đơn mua hàng', [0, 5, 8, 10], _pct),
    _tax(4, 'ban-hang', 'Thuế đơn bán hàng', [0, 10], _pct),
  ];

  @override
  Future<ResponseBody> fetch(RequestOptions o, Stream<Uint8List>? _, Future<void>? _) async {
    calls.add(o);
    final p = o.path;
    if (p.endsWith('/admin/quyen-cua-toi')) {
      if (perms == null) return _json(403, {'success': false, 'message': 'Không có quyền'});
      return _ok({'toan_quyen': false, 'quyen': perms});
    }
    if (p.endsWith('/auth/me')) {
      return _ok({'id': 1, 'username': 'admin', 'full_name': 'Quản trị viên', 'access_areas': 'quan_ly'});
    }
    if (p.endsWith('/products')) {
      final rows = [
        for (final (i, v) in [10, 10, 10, 0].indexed) {'id': i + 1, 'name': 'SP $i', 'vat': v},
      ];
      return _json(200, {
        'success': true,
        'data': rows,
        'meta': {'page': 1, 'page_size': 100, 'total': rows.length, 'total_pages': 1},
      });
    }
    if (p.endsWith('/categories')) {
      return _ok([
        for (final (i, v) in [10, 0, 0, 0].indexed) {'id': i + 1, 'name': 'N $i', 'vat': v},
      ]);
    }
    if (p.endsWith('/admin/thue') && o.method == 'GET') return _ok(taxes);
    final m = RegExp(r'/admin/thue/(\d+)(/trang-thai)?$').firstMatch(p);
    if (m != null && o.method == 'PUT') {
      final id = int.parse(m.group(1)!);
      final i = taxes.indexWhere((t) => t['id'] == id);
      if (m.group(2) != null) {
        taxes[i] = {...taxes[i], 'is_active': (o.data as Map)['is_active']};
        return _ok(taxes[i]);
      }
      if (id == rejectId) {
        return _json(422, {
          'success': false,
          'message': 'Dữ liệu không hợp lệ',
          'errors': {'muc': 'Giữ lại ít nhất một mức thuế. Muốn thôi áp thuế thì tắt cả dòng'},
        });
      }
      taxes[i] = {...taxes[i], 'muc': (o.data as Map)['muc']};
      return _ok(taxes[i]);
    }
    return _json(403, {'success': false, 'message': 'Không có quyền'});
  }

  ResponseBody _ok(Object? data) => _json(200, {'success': true, 'data': data});

  ResponseBody _json(int code, Object body) => ResponseBody.fromString(
    jsonEncode(body),
    code,
    headers: {
      Headers.contentTypeHeader: ['application/json'],
    },
  );

  @override
  void close({bool force = false}) {}

  List<RequestOptions> hit(String method, String pathEnd) =>
      calls.where((c) => c.method == method && c.path.endsWith(pathEnd)).toList();
}

class _Host {
  final tabs = <AdminTab>[];
  var closed = 0;

  MoreHost get host => MoreHost(goTab: tabs.add, close: () => closed++);
}

List<Override> _overrides(_Api a) => [
  sessionStoreProvider.overrideWithValue(_MemoryStore(_session())),
  onlineProvider.overrideWith((ref) => Stream.value(true)),
  clockProvider.overrideWithValue(() => DateTime(2026, 10, 21, 9)),
  apiClientProvider.overrideWith(
    (ref) => ApiClient(ref.read(authProvider.notifier), dio: Dio()..httpClientAdapter = a),
  ),
];

Future<void> _settle(WidgetTester t, [int n = 10]) async {
  for (var i = 0; i < n; i++) {
    await t.pump(const Duration(milliseconds: 100));
  }
}

Future<(_Api, _Host)> _open(WidgetTester t, {_Api? api, Size size = const Size(390, 844)}) async {
  SharedPreferences.setMockInitialValues({});
  t.view.physicalSize = size;
  t.view.devicePixelRatio = 1;
  addTearDown(t.view.reset);
  final a = api ?? _Api();
  final h = _Host();
  final c = ProviderContainer(overrides: _overrides(a));
  addTearDown(c.dispose);
  await t.pumpWidget(
    UncontrolledProviderScope(
      container: c,
      child: MaterialApp(
        home: Scaffold(body: TaxesScreen(host: h.host)),
      ),
    ),
  );
  await _settle(t);
  return (a, h);
}

Finder _card(int id) => find.byKey(Key('tax_$id'));
Finder _in(int id, Finder f) => find.descendant(of: _card(id), matching: f);
String _count(WidgetTester t) => t.widget<Text>(find.byKey(const Key('tax_f_count'))).textSpan!.toPlainText();

Future<void> _tapIn(WidgetTester t, String key) async {
  await t.ensureVisible(find.byKey(Key(key)));
  await _settle(t, 3);
  await t.tap(find.byKey(Key(key)));
  await _settle(t, 3);
}

void main() {
  test('mức xếp theo danh mục API, nhãn KCT/KKKNT, chỉ loại mặc định có đếm nơi khai', () {
    final k = TaxKind.fromJson(_Api().taxes.first);
    expect(k.ordered({10, -1, 0, 5}), [0, 5, 10, -1]);
    expect(k.label(-2), 'KKKNT');
    final b = TaxBook([k], productVat: {10: 3}, categoryVat: {10: 1});
    expect(b.usage(k, 10), '3 sp · 1 nhóm');
    expect(b.usage(k, 10, long: true), '3 sản phẩm, 1 nhóm hàng');
    expect(b.usage(k, 8), isNull);
    expect(b.usage(TaxKind.fromJson(_Api().taxes[2]), 10), isNull);
  });

  testWidgets('4 thẻ thuế: mô tả, mức đang hiện (kèm số sp/nhóm ở loại mặc định), thẻ đang tắt ghi rõ', (t) async {
    await _open(t);
    for (final id in [1, 2, 3, 4]) {
      expect(_card(id), findsOneWidget);
    }
    expect(_in(1, find.text('3 sp · 1 nhóm')), findsOneWidget);
    expect(_in(1, find.text('1 sp · 3 nhóm')), findsOneWidget);
    expect(_in(1, find.text('3/6 mức đang hiện')), findsOneWidget);
    expect(_in(3, find.text('3 sp · 1 nhóm')), findsNothing);
    expect(_in(2, find.text('Đang tắt — màn nghiệp vụ không bày ô thuế này')), findsOneWidget);
    expect(_in(2, find.text('Đã tắt')), findsOneWidget);
    expect(find.text('Không có thuế nào'), findsNothing);
    expect(find.byTooltip('Thêm'), findsNothing);
  });

  testWidgets(
    'sửa mức: bỏ mức đang có hàng khai thì cảnh báo; PUT gửi mức theo thứ tự danh mục, không đụng trạng thái',
    (t) async {
      final (api, _) = await _open(t);
      await t.tap(_card(1));
      await _settle(t);
      expect(_count(t), 'Mức được hiện  3/6');
      expect(find.textContaining('KCT', findRichText: true), findsWidgets);
      await _tapIn(t, 'tax_pick_5');
      expect(_count(t), 'Mức được hiện  4/6');
      await _tapIn(t, 'tax_pick_10');
      expect(
        find.textContaining('Bỏ 10% (đang khai ở 3 sản phẩm, 1 nhóm hàng): hàng cũ giữ nguyên mức này'),
        findsOneWidget,
      );
      await _tapIn(t, 'tax_f_save');
      await _settle(t);
      expect((api.hit('PUT', '/admin/thue/1').single.data as Map)['muc'], [0, 5, 8]);
      expect(api.hit('PUT', '/trang-thai'), isEmpty);
      expect(find.text('Đã lưu Thuế mặc định: 0%, 5%, 8%'), findsOneWidget);
      expect(_in(1, find.text('5%')), findsOneWidget);
    },
  );

  testWidgets('Bỏ hết → không cho lưu (phải giữ ít nhất một mức); Chọn hết → đủ', (t) async {
    final (api, _) = await _open(t);
    await t.tap(_card(3));
    await _settle(t);
    expect(find.textContaining('KKKNT', findRichText: true), findsNothing);
    await _tapIn(t, 'tax_f_all');
    expect(_count(t), 'Mức được hiện  0/4');
    expect(find.byKey(const Key('tax_f_empty')), findsOneWidget);
    await _tapIn(t, 'tax_f_save');
    expect(api.hit('PUT', '/admin/thue/3'), isEmpty);
    await _tapIn(t, 'tax_f_all');
    expect(_count(t), 'Mức được hiện  4/4');
    expect(find.text('Bỏ hết'), findsOneWidget);
  });

  testWidgets('chỉ bật/tắt: gọi đúng đường trang-thai, có Hoàn tác', (t) async {
    final (api, _) = await _open(t);
    await t.tap(_card(4));
    await _settle(t);
    await t.tap(find.byKey(const Key('tax_f_on')));
    await _settle(t, 3);
    expect(find.text('Tắt: các màn nghiệp vụ thôi bày ô chọn thuế này'), findsOneWidget);
    await _tapIn(t, 'tax_f_save');
    await _settle(t);
    expect(api.hit('PUT', '/admin/thue/4'), isEmpty);
    expect(api.hit('PUT', '/admin/thue/4/trang-thai').single.data, {'is_active': false});
    expect(find.text('Đã tắt Thuế đơn bán hàng'), findsOneWidget);
    await t.tap(find.text('Hoàn tác'));
    await _settle(t);
    expect(api.hit('PUT', '/admin/thue/4/trang-thai').last.data, {'is_active': true});
  });

  testWidgets('API từ chối (422) → báo câu lỗi trong hộp, giữ hộp', (t) async {
    await _open(t, api: _Api(rejectId: 3));
    await t.tap(_card(3));
    await _settle(t);
    await _tapIn(t, 'tax_pick_10');
    await _tapIn(t, 'tax_f_save');
    await _settle(t);
    expect(find.text('Giữ lại ít nhất một mức thuế. Muốn thôi áp thuế thì tắt cả dòng'), findsOneWidget);
    expect(find.byKey(const Key('tax_f_save')), findsOneWidget);
  });

  testWidgets('chỉ có quyền xem: thẻ không có "Sửa", chạm không mở hộp', (t) async {
    await _open(t, api: _Api(perms: ['thue.xem']));
    expect(find.text('Sửa'), findsNothing);
    await t.tap(_card(1));
    await _settle(t);
    expect(find.byKey(const Key('tax_f_save')), findsNothing);
  });

  testWidgets('khung app: Thêm → Hàng hoá → Quản lý thuế mở trong khung, tab Thêm sáng', (t) async {
    SharedPreferences.setMockInitialValues({});
    t.view.physicalSize = const Size(390, 844);
    t.view.devicePixelRatio = 1;
    addTearDown(t.view.reset);
    await t.pumpWidget(ProviderScope(overrides: _overrides(_Api()), child: const SelliotechApp()));
    await _settle(t);
    if (find.byKey(const Key('area_quan_ly')).evaluate().isNotEmpty) {
      await t.tap(find.byKey(const Key('area_quan_ly')));
      await t.pump(ChooseWorkspaceScreen.goingDelay);
      await _settle(t);
    }
    await t.tap(find.byKey(const Key('tab_more')));
    await _settle(t);
    await t.tap(find.byKey(const Key('more_mod_hang_hoa')));
    await _settle(t, 3);
    await t.tap(find.text('Quản lý thuế'));
    await _settle(t);
    expect(find.byType(TaxesScreen), findsOneWidget);
    expect(t.widget<AdminTabBar>(find.byType(AdminTabBar)).moreActive, isTrue);
  });

  testWidgets('máy hẹp 360×640: thẻ và hộp sửa (6 mức, cảnh báo) không tràn', (t) async {
    await _open(t, size: const Size(360, 640));
    await t.tap(_card(1));
    await _settle(t);
    await _tapIn(t, 'tax_pick_10');
    expect(t.takeException(), isNull);
  });
}
