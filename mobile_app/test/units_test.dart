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
import 'package:selliotech/features/admin/units/units_data.dart';
import 'package:selliotech/features/admin/units/units_screen.dart';
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

Map<String, dynamic> _unit(int id, String code, String name, {bool on = true}) => {
  'id': id,
  'code': code,
  'name': name,
  'is_active': on,
};

/// API giả: Cái là đơn vị chính của 2 sản phẩm, Hộp là đơn vị quy đổi của 1, Thùng đang tắt, Lít chưa dùng.
class _Api implements HttpClientAdapter {
  _Api({this.perms, this.takenCode});

  final List<String>? perms;
  final String? takenCode;
  final calls = <RequestOptions>[];
  var _next = 4;

  final units = [
    _unit(1, 'DV001', 'Cái'),
    _unit(2, 'DV002', 'Hộp'),
    _unit(3, 'DV003', 'Thùng', on: false),
    _unit(4, 'DV004', 'Lít'),
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
        {
          'id': 1,
          'name': 'Nước suối',
          'unit_id': 1,
          'unit_conversions': [
            {'unit_id': 2, 'quantity': 24},
          ],
        },
        {'id': 2, 'name': 'Ốp lưng', 'unit_id': 1, 'unit_conversions': null},
        {'id': 3, 'name': 'Dịch vụ', 'unit_id': 0},
      ];
      return _json(200, {
        'success': true,
        'data': rows,
        'meta': {'page': 1, 'page_size': 100, 'total': rows.length, 'total_pages': 1},
      });
    }
    if (p.endsWith('/admin/don-vi-tinh') && o.method == 'GET') return _ok(units);
    if (p.endsWith('/admin/don-vi-tinh') && o.method == 'POST') {
      final b = Map<String, dynamic>.from(o.data as Map);
      if (b['code'] == takenCode) {
        return _json(422, {
          'success': false,
          'message': 'Dữ liệu không hợp lệ',
          'errors': {'code': 'Mã đơn vị này đã có trong cửa hàng (tính cả đơn vị đã xoá)'},
        });
      }
      final code = '${b['code']}'.isEmpty ? 'DV${'${++_next}'.padLeft(3, '0')}' : '${b['code']}';
      final u = _unit(100 + _next, code, '${b['name']}', on: b['is_active'] != false);
      units.insert(0, u);
      return _json(201, {'success': true, 'data': u});
    }
    final m = RegExp(r'/admin/don-vi-tinh/(\d+)(/trang-thai)?$').firstMatch(p);
    if (m != null) {
      final id = int.parse(m.group(1)!);
      final i = units.indexWhere((u) => u['id'] == id);
      if (m.group(2) != null) {
        units[i] = {...units[i], 'is_active': (o.data as Map)['is_active']};
        return _ok(units[i]);
      }
      if (o.method == 'PUT') {
        final b = Map<String, dynamic>.from(o.data as Map);
        units[i] = {...units[i], 'name': b['name'], 'is_active': b['is_active']};
        return _ok(units[i]);
      }
      if (o.method == 'DELETE') {
        units.removeAt(i);
        return _ok(null);
      }
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
        home: Scaffold(body: UnitsScreen(host: h.host)),
      ),
    ),
  );
  await _settle(t);
  return (a, h);
}

Finder _row(int id) => find.byKey(Key('unit_$id'));

Future<void> _more(WidgetTester t, int id, String action) async {
  await t.tap(find.byKey(Key('unit_more_$id')));
  await _settle(t, 5);
  await t.tap(find.byKey(Key('unit_act_$action')));
  await _settle(t);
}

String _chip(WidgetTester t, String st) => t
    .widgetList<Text>(find.descendant(of: find.byKey(Key('unit_st_$st')), matching: find.byType(Text)))
    .map((w) => w.data)
    .join(' ');

String _title(WidgetTester t) => t.widget<Text>(find.byKey(const Key('unit_f_title'))).data!;

Map _body(RequestOptions r) => r.data as Map;

void main() {
  test('gợi ý đơn vị hay dùng: bỏ cái đã có, lọc theo chữ đầu đang gõ', () {
    final have = [const Unit(id: 1, code: 'DV001', name: 'Cái', active: true)];
    expect(unitSuggestions(have, ''), isNot(contains('Cái')));
    expect(unitSuggestions(have, '').length, 10);
    expect(unitSuggestions(have, 'th'), ['Thùng']);
    expect(unitSuggestions(have, 'thùng'), isEmpty);
  });

  testWidgets('danh sách: đếm theo trạng thái; ghi rõ đơn vị chính / quy đổi của bao nhiêu sản phẩm', (t) async {
    await _open(t);
    expect(_chip(t, 'all'), 'Tất cả 4');
    expect(_chip(t, 'on'), 'Đang bật 3');
    expect(_chip(t, 'off'), 'Đã tắt 1');
    expect(find.textContaining('chính 2 sp', findRichText: true), findsOneWidget);
    expect(find.textContaining('quy đổi 1 sp', findRichText: true), findsOneWidget);
    expect(find.textContaining('chưa dùng', findRichText: true), findsNWidgets(2));
    await t.tap(find.byKey(const Key('unit_st_off')));
    await _settle(t, 3);
    expect(_row(3), findsOneWidget);
    expect(_row(1), findsNothing);
  });

  testWidgets('tìm không dấu; không ra thì "Thêm “…”" điền sẵn tên vào hộp', (t) async {
    await _open(t);
    await t.enterText(find.byKey(const Key('unit_search')), 'hop');
    await _settle(t, 4);
    expect(find.text('1 ĐƠN VỊ KHỚP “hop”'), findsOneWidget);
    expect(_row(2), findsOneWidget);
    await t.enterText(find.byKey(const Key('unit_search')), 'Bịch');
    await _settle(t, 4);
    expect(find.text('Không có đơn vị nào khớp “Bịch”'), findsOneWidget);
    await t.tap(find.byKey(const Key('unit_empty_add')));
    await _settle(t);
    expect(_title(t), 'Thêm đơn vị tính');
    expect(find.text('Bịch'), findsWidgets);
  });

  testWidgets('thêm: gợi ý hay dùng, Lưu & thêm tiếp giữ hộp mở + đếm, trùng tên báo tại ô, Lưu thì đóng', (t) async {
    final (api, _) = await _open(t);
    await t.tap(find.byTooltip('Thêm đơn vị'));
    await _settle(t);
    expect(find.byKey(const Key('unit_sugg_Chiếc')), findsOneWidget);
    expect(find.byKey(const Key('unit_sugg_Cái')), findsNothing);
    await t.tap(find.byKey(const Key('unit_sugg_Chiếc')));
    await _settle(t, 3);
    await t.tap(find.byKey(const Key('unit_f_more')));
    await _settle(t);
    expect(_body(api.hit('POST', '/admin/don-vi-tinh').single), {'code': '', 'name': 'Chiếc', 'is_active': true});
    expect(_title(t), 'Thêm đơn vị tính · đã thêm 1');
    expect(find.byKey(const Key('unit_sugg_Chiếc')), findsNothing);
    expect(find.text('Đã thêm đơn vị "Chiếc" (DV005)'), findsOneWidget);

    await t.enterText(find.byKey(const Key('unit_f_name')), ' chiếc ');
    await t.tap(find.byKey(const Key('unit_f_save')));
    await _settle(t, 3);
    expect(find.text('Tên đơn vị tính đã tồn tại.'), findsOneWidget);
    await t.enterText(find.byKey(const Key('unit_f_name')), 'Kg');
    await t.tap(find.byKey(const Key('unit_f_save')));
    await _settle(t);
    expect(api.hit('POST', '/admin/don-vi-tinh'), hasLength(2));
    expect(find.byKey(const Key('unit_f_title')), findsNothing);
    expect(find.text('Đã thêm đơn vị "Kg" (DV006)'), findsOneWidget);
    expect(_chip(t, 'all'), 'Tất cả 6');
  });

  testWidgets('mã sai báo ngay; API báo trùng mã (422) thì báo tại ô Mã', (t) async {
    final (api, _) = await _open(t, api: _Api(takenCode: 'BICH'));
    await t.tap(find.byTooltip('Thêm đơn vị'));
    await _settle(t);
    await t.enterText(find.byKey(const Key('unit_f_name')), 'Bịch');
    await t.enterText(find.byKey(const Key('unit_f_code')), 'B 1');
    await t.tap(find.byKey(const Key('unit_f_save')));
    await _settle(t, 3);
    expect(find.text('Mã đơn vị chỉ gồm chữ không dấu và số, không khoảng trắng.'), findsOneWidget);
    expect(api.hit('POST', '/admin/don-vi-tinh'), isEmpty);
    await t.enterText(find.byKey(const Key('unit_f_code')), 'bich');
    await t.tap(find.byKey(const Key('unit_f_save')));
    await _settle(t);
    expect(_body(api.hit('POST', '/admin/don-vi-tinh').single)['code'], 'BICH');
    expect(find.text('Mã đơn vị này đã có trong cửa hàng (tính cả đơn vị đã xoá)'), findsOneWidget);
  });

  testWidgets('sửa: chạm dòng mở hộp sửa (ghi đang dùng), không có "Lưu & thêm tiếp"; PUT gửi mã, tên, trạng thái', (
    t,
  ) async {
    final (api, _) = await _open(t);
    await t.tap(_row(1));
    await _settle(t);
    expect(_title(t), 'Sửa đơn vị tính');
    expect(find.text('Đang dùng: đơn vị chính của 2 sp'), findsOneWidget);
    expect(find.byKey(const Key('unit_f_more')), findsNothing);
    expect(find.byKey(const Key('unit_sugg_Chiếc')), findsNothing);
    await t.enterText(find.byKey(const Key('unit_f_name')), 'Cái (chiếc)');
    await t.tap(find.byKey(const Key('unit_f_save')));
    await _settle(t);
    expect(_body(api.hit('PUT', '/admin/don-vi-tinh/1').single), {
      'code': 'DV001',
      'name': 'Cái (chiếc)',
      'is_active': true,
    });
    expect(find.text('Đã cập nhật đơn vị "Cái (chiếc)" (DV001)'), findsOneWidget);
  });

  testWidgets('nhân bản: tên thêm " copy", bỏ mã để API đặt', (t) async {
    final (api, _) = await _open(t);
    await _more(t, 2, 'copy');
    expect(_title(t), 'Nhân bản đơn vị');
    expect(find.text('Hộp copy'), findsOneWidget);
    await t.tap(find.byKey(const Key('unit_f_save')));
    await _settle(t);
    expect(_body(api.hit('POST', '/admin/don-vi-tinh').single), {'code': '', 'name': 'Hộp copy', 'is_active': true});
  });

  testWidgets('tắt có Hoàn tác; xoá chặn đơn vị đang dùng (kể cả chỉ là quy đổi), cái chưa dùng thì hỏi rồi xoá', (
    t,
  ) async {
    final (api, _) = await _open(t);
    await _more(t, 1, 'toggle');
    expect(_body(api.hit('PUT', '/admin/don-vi-tinh/1/trang-thai').single), {'is_active': false});
    await t.tap(find.text('Hoàn tác'));
    await _settle(t);
    expect(_body(api.hit('PUT', '/admin/don-vi-tinh/1/trang-thai').last), {'is_active': true});

    await _more(t, 2, 'del');
    expect(find.text('Không xoá được: Hộp đang dùng ở 1 sản phẩm. Tắt đi để thôi dùng.'), findsOneWidget);
    expect(api.hit('DELETE', '/admin/don-vi-tinh/2'), isEmpty);

    await _more(t, 4, 'del');
    expect(find.text('Xoá đơn vị “Lít”?'), findsOneWidget);
    await t.tap(find.byKey(const Key('unit_del_yes')));
    await _settle(t);
    expect(api.hit('DELETE', '/admin/don-vi-tinh/4'), hasLength(1));
    expect(_row(4), findsNothing);
  });

  testWidgets('chọn nhiều → Xoá: bỏ qua đơn vị đang có hàng dùng', (t) async {
    final (api, _) = await _open(t);
    await t.tap(find.byKey(const Key('unit_select')));
    await _settle(t, 3);
    await t.tap(_row(1));
    await t.tap(_row(3));
    await t.tap(_row(4));
    await _settle(t, 3);
    expect(t.widget<Text>(find.byKey(const Key('unit_sel_count'))).data, 'Đã chọn 3');
    await t.tap(find.byKey(const Key('unit_sel_delete')));
    await _settle(t, 5);
    expect(find.text('Xoá 2 đơn vị?'), findsOneWidget);
    expect(find.textContaining('Bỏ qua 1 đơn vị đang có hàng dùng: Cái (2 sp)'), findsOneWidget);
    await t.tap(find.byKey(const Key('unit_del_yes')));
    await _settle(t);
    expect(api.hit('DELETE', '/admin/don-vi-tinh/3'), hasLength(1));
    expect(api.hit('DELETE', '/admin/don-vi-tinh/4'), hasLength(1));
    expect(api.hit('DELETE', '/admin/don-vi-tinh/1'), isEmpty);
  });

  testWidgets('chỉ có quyền xem: không có +, Chọn; chạm dòng mở ngăn xem, không có Sửa / Xoá', (t) async {
    await _open(t, api: _Api(perms: ['don-vi-tinh.xem']));
    expect(find.byTooltip('Thêm đơn vị'), findsNothing);
    expect(find.byKey(const Key('unit_select')), findsNothing);
    await t.tap(_row(1));
    await _settle(t, 5);
    expect(find.byKey(const Key('unit_f_title')), findsNothing);
    expect(find.text('DV001 · đơn vị chính của 2 sp'), findsOneWidget);
    expect(find.byKey(const Key('unit_act_edit')), findsNothing);
    expect(find.byKey(const Key('unit_act_del')), findsNothing);
  });

  testWidgets('khung app: Thêm → Hàng hoá → Đơn vị tính mở trong khung, tab Thêm sáng', (t) async {
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
    await t.tap(find.text('Đơn vị tính'));
    await _settle(t);
    expect(find.byType(UnitsScreen), findsOneWidget);
    expect(t.widget<AdminTabBar>(find.byType(AdminTabBar)).moreActive, isTrue);
  });

  testWidgets('máy hẹp 360×640: danh sách và hộp Thêm (3 nút chân hộp, gợi ý) không tràn', (t) async {
    await _open(t, size: const Size(360, 640));
    await t.tap(find.byTooltip('Thêm đơn vị'));
    await _settle(t);
    expect(find.byKey(const Key('unit_f_more')), findsOneWidget);
    expect(t.takeException(), isNull);
  });
}
