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
import 'package:selliotech/features/admin/attributes/attributes_data.dart';
import 'package:selliotech/features/admin/attributes/attributes_screen.dart';
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

Map<String, dynamic> _attr(
  int id,
  String code,
  String name,
  List<String> values, {
  bool on = true,
  int firstValueId = 0,
}) => {
  'id': id,
  'code': code,
  'name': name,
  'is_active': on,
  'values': [
    for (final (i, v) in values.indexed)
      {'id': firstValueId + i, 'attribute_id': id, 'code': '$code${'${i + 1}'.padLeft(2, '0')}', 'name': v},
  ],
};

/// API giả: RAM có hàng dùng (2 sản phẩm, giá trị 8GB), Màu nhiều giá trị chưa dùng, Chất liệu đang tắt.
class _Api implements HttpClientAdapter {
  _Api({this.perms, this.takenCode});

  final List<String>? perms;

  /// Mã mà API báo trùng (422) — vd trùng với thuộc tính đã xoá, máy không thấy.
  final String? takenCode;
  final calls = <RequestOptions>[];
  var _next = 100;

  final attrs = [
    _attr(15, 'RAM', 'RAM', ['4GB', '8GB', '512GB'], firstValueId: 94),
    _attr(30, 'MAU', 'Màu sắc', [
      'Đen',
      'Trắng',
      'Xanh dương đậm',
      'Đỏ',
      'Vàng',
      'Tím',
      'Hồng',
      'Xám',
      'Bạc',
      'Cam',
      'Nâu',
      'Xanh lá',
    ], firstValueId: 300),
    _attr(40, 'CL', 'Chất liệu', ['Nhựa', 'Kim loại'], on: false, firstValueId: 400),
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
          'name': 'Iphone 16',
          'variants': [
            {
              'attributes': [
                {'attribute_id': 15, 'value_id': 95},
              ],
            },
          ],
        },
        {
          'id': 2,
          'name': 'Laptop',
          'variants': [
            {
              'attributes': [
                {'attribute_id': 15, 'value_id': 95},
              ],
            },
            {'attributes': []},
          ],
        },
        {'id': 3, 'name': 'Ốp lưng', 'variants': []},
      ];
      return _json(200, {
        'success': true,
        'data': rows,
        'meta': {'page': 1, 'page_size': 100, 'total': rows.length, 'total_pages': 1},
      });
    }
    if (p.endsWith('/admin/thuoc-tinh') && o.method == 'GET') return _ok(attrs);
    if (p.endsWith('/admin/thuoc-tinh') && o.method == 'POST') {
      final b = Map<String, dynamic>.from(o.data as Map);
      if (b['code'] == takenCode) {
        return _json(422, {
          'success': false,
          'message': 'Dữ liệu không hợp lệ',
          'errors': {'code': 'Mã thuộc tính này đã có trong cửa hàng (tính cả thuộc tính đã xoá)'},
        });
      }
      final code = '${b['code']}'.isEmpty ? 'TT${++_next}' : b['code'];
      final a = _attr(
        ++_next,
        '$code',
        '${b['name']}',
        [for (final v in b['values'] as List) '${(v as Map)['name']}'],
        on: b['is_active'] != false,
        firstValueId: _next * 10,
      );
      attrs.insert(0, a);
      return _json(201, {'success': true, 'data': a});
    }
    final m = RegExp(r'/admin/thuoc-tinh/(\d+)(/trang-thai)?$').firstMatch(p);
    if (m != null) {
      final id = int.parse(m.group(1)!);
      final i = attrs.indexWhere((a) => a['id'] == id);
      if (m.group(2) != null) {
        attrs[i] = {...attrs[i], 'is_active': (o.data as Map)['is_active']};
        return _ok(attrs[i]);
      }
      if (o.method == 'PUT') {
        final b = Map<String, dynamic>.from(o.data as Map);
        attrs[i] = {...attrs[i], 'name': b['name'], 'is_active': b['is_active']};
        return _ok(attrs[i]);
      }
      if (o.method == 'DELETE') {
        attrs.removeAt(i);
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
        home: Scaffold(body: AttributesScreen(host: h.host)),
      ),
    ),
  );
  await _settle(t);
  return (a, h);
}

Finder _row(int id) => find.byKey(Key('attr_$id'));

Future<void> _tapIn(WidgetTester t, String key) async {
  await t.ensureVisible(find.byKey(Key(key)));
  await _settle(t, 3);
  await t.tap(find.byKey(Key(key)));
  await _settle(t, 3);
}

Future<void> _more(WidgetTester t, int id, String action) async {
  await t.tap(find.byKey(Key('attr_more_$id')));
  await _settle(t, 5);
  await t.tap(find.byKey(Key('attr_act_$action')));
  await _settle(t);
}

String _chip(WidgetTester t, String st) => t
    .widgetList<Text>(find.descendant(of: find.byKey(Key('attr_st_$st')), matching: find.byType(Text)))
    .map((w) => w.data)
    .join(' ');

Map _body(RequestOptions r) => r.data as Map;

void main() {
  test('dán nhiều giá trị: tách dấu phẩy / xuống dòng, bỏ trống và trùng (không phân biệt hoa thường)', () {
    expect(splitBulk('S, M, l\nL ;XL,, m'), ['S', 'M', 'l', 'XL']);
    expect(attrCodeRe.hasMatch('RAM16'), isTrue);
    expect(attrCodeRe.hasMatch('RAM 16'), isFalse);
    expect(attrCodeRe.hasMatch('MÀU'), isFalse);
  });

  testWidgets('danh sách: đếm theo trạng thái, mã · số giá trị · dùng ở n sản phẩm, xem trước một hàng có +n', (
    t,
  ) async {
    await _open(t);
    expect(_chip(t, 'all'), 'Tất cả 3');
    expect(_chip(t, 'on'), 'Đang bật 2');
    expect(_chip(t, 'off'), 'Đã tắt 1');
    expect(find.text('3 THUỘC TÍNH'), findsOneWidget);
    expect(find.textContaining('RAM · 3 giá trị · '), findsOneWidget);
    expect(find.textContaining('dùng ở 2 sản phẩm', findRichText: true), findsOneWidget);
    expect(find.textContaining('MAU · 12 giá trị · '), findsOneWidget);
    expect(find.text('Đã tắt'), findsWidgets);
    final more = find.descendant(
      of: find.byKey(const Key('attr_prev_30')),
      matching: find.byKey(const Key('attr_prev_more')),
    );
    expect(more, findsOneWidget);
    expect(t.widget<Text>(more).data, startsWith('+'));
    expect(t.getSize(find.byKey(const Key('attr_prev_30'))).height, lessThan(30));

    await t.tap(find.byKey(const Key('attr_st_off')));
    await _settle(t, 3);
    expect(_row(40), findsOneWidget);
    expect(_row(15), findsNothing);
  });

  testWidgets('tìm cả theo giá trị: gõ "512" ra RAM, giá trị khớp đưa lên đầu dãy xem trước', (t) async {
    await _open(t);
    await t.enterText(find.byKey(const Key('attr_search')), '512');
    await _settle(t, 4);
    expect(find.text('1 THUỘC TÍNH KHỚP “512”'), findsOneWidget);
    expect(_row(15), findsOneWidget);
    expect(_chip(t, 'all'), 'Tất cả 1');
    await t.enterText(find.byKey(const Key('attr_search')), 'xanh duong');
    await _settle(t, 4);
    expect(_row(30), findsOneWidget);
    await t.enterText(find.byKey(const Key('attr_search')), 'zzz');
    await _settle(t, 4);
    expect(find.text('Không có thuộc tính nào khớp “zzz”'), findsOneWidget);
  });

  testWidgets('thêm: báo thiếu tên, mã sai, trùng tên; dán nhiều giá trị; POST mã trống để API đặt', (t) async {
    final (api, _) = await _open(t);
    await t.tap(find.byTooltip('Thêm thuộc tính'));
    await _settle(t);
    expect(find.text('Thêm thuộc tính'), findsWidgets);
    await t.tap(find.byKey(const Key('attr_f_save')));
    await _settle(t, 3);
    expect(find.text('Nhập tên thuộc tính.'), findsOneWidget);

    await t.enterText(find.byKey(const Key('attr_f_code')), 'sz 1');
    await t.enterText(find.byKey(const Key('attr_f_name')), ' ram ');
    await t.tap(find.byKey(const Key('attr_f_save')));
    await _settle(t, 3);
    expect(find.text('Chỉ gồm chữ không dấu và số, không khoảng trắng.'), findsOneWidget);
    expect(find.text('Tên thuộc tính đã tồn tại.'), findsOneWidget);

    await t.enterText(find.byKey(const Key('attr_f_code')), '');
    await t.enterText(find.byKey(const Key('attr_f_name')), 'Size');
    await _tapIn(t, 'attr_v_bulk');
    await t.enterText(find.byKey(const Key('attr_bulk_in')), 'S, M, L\nm, XL');
    await t.pump();
    expect(find.text('4 giá trị'), findsOneWidget);
    await _tapIn(t, 'attr_bulk_ok');
    expect(find.text('Đã thêm 4 giá trị vào danh sách'), findsOneWidget);
    await _tapIn(t, 'attr_f_save');
    await _settle(t);

    final b = _body(api.hit('POST', '/admin/thuoc-tinh').single);
    expect(b['code'], '');
    expect(b['name'], 'Size');
    expect(b['is_active'], true);
    expect(b['values'], [
      {'code': '', 'name': 'S'},
      {'code': '', 'name': 'M'},
      {'code': '', 'name': 'L'},
      {'code': '', 'name': 'XL'},
    ]);
    expect(find.text('Đã thêm thuộc tính "Size" · 4 giá trị'), findsOneWidget);
    expect(find.text('4 THUỘC TÍNH'), findsOneWidget);
  });

  testWidgets('API báo trùng mã (422) → báo tại ô Mã, giữ hộp', (t) async {
    await _open(t, api: _Api(takenCode: 'OLD'));
    await t.tap(find.byTooltip('Thêm thuộc tính'));
    await _settle(t);
    await t.enterText(find.byKey(const Key('attr_f_code')), 'old');
    await t.enterText(find.byKey(const Key('attr_f_name')), 'Kích cỡ');
    await t.tap(find.byKey(const Key('attr_f_save')));
    await _settle(t);
    expect(find.text('Mã thuộc tính này đã có trong cửa hàng (tính cả thuộc tính đã xoá)'), findsOneWidget);
    expect(find.text('Kích cỡ'), findsOneWidget);
  });

  testWidgets(
    'sửa: giá trị đang dùng có khoá, không bỏ được; bỏ giá trị chưa dùng → PUT gửi nguyên bộ còn lại kèm id',
    (t) async {
      final (api, _) = await _open(t);
      await t.tap(_row(15));
      await _settle(t);
      expect(find.text('Sửa thuộc tính'), findsOneWidget);
      expect(find.text('Đang dùng ở 2 sản phẩm'), findsOneWidget);
      expect(find.text('dùng ở 2 sản phẩm'), findsWidgets);
      await _tapIn(t, 'attr_v_x_1');
      expect(find.textContaining('“8GB” đang gắn vào 2 sản phẩm'), findsOneWidget);
      await _tapIn(t, 'attr_v_x_2');
      await t.enterText(find.byKey(const Key('attr_v_name_0')), '4 GB');
      await _tapIn(t, 'attr_f_save');
      await _settle(t);
      final b = _body(api.hit('PUT', '/admin/thuoc-tinh/15').single);
      expect(b['code'], 'RAM');
      expect(b['values'], [
        {'id': 94, 'code': 'RAM01', 'name': '4 GB'},
        {'id': 95, 'code': 'RAM02', 'name': '8GB'},
      ]);
      expect(find.text('Đã cập nhật thuộc tính "RAM" · 2 giá trị'), findsOneWidget);
    },
  );

  testWidgets('Hiện mã: cột mã giá trị ẩn mặc định, bật lên sửa được; trùng mã giá trị báo lỗi', (t) async {
    final (api, _) = await _open(t);
    await t.tap(_row(30));
    await _settle(t);
    expect(find.byKey(const Key('attr_v_code_0')), findsNothing);
    await _tapIn(t, 'attr_v_codes');
    expect(find.byKey(const Key('attr_v_code_0')), findsOneWidget);
    await t.enterText(find.byKey(const Key('attr_v_code_1')), 'MAU01');
    await _tapIn(t, 'attr_f_save');
    expect(find.text('Mã giá trị bị trùng trong cùng thuộc tính (MAU01).'), findsOneWidget);
    expect(api.hit('PUT', '/admin/thuoc-tinh/30'), isEmpty);
  });

  testWidgets('nhân bản qua ⋯: tên thêm " copy", giữ tên giá trị, bỏ mã + id để API đặt mới', (t) async {
    final (api, _) = await _open(t);
    await _more(t, 40, 'copy');
    expect(find.text('Nhân bản thuộc tính'), findsOneWidget);
    expect(find.text('Chất liệu copy'), findsOneWidget);
    await _tapIn(t, 'attr_f_save');
    await _settle(t);
    final b = _body(api.hit('POST', '/admin/thuoc-tinh').single);
    expect(b['name'], 'Chất liệu copy');
    expect(b['code'], '');
    expect(b['is_active'], true);
    expect(b['values'], [
      {'code': '', 'name': 'Nhựa'},
      {'code': '', 'name': 'Kim loại'},
    ]);
  });

  testWidgets('tắt qua ⋯ có Hoàn tác (PUT trang-thai); xoá: chặn thuộc tính đang dùng, cái chưa dùng thì hỏi rồi xoá', (
    t,
  ) async {
    final (api, _) = await _open(t);
    await _more(t, 30, 'toggle');
    expect(_body(api.hit('PUT', '/admin/thuoc-tinh/30/trang-thai').single), {'is_active': false});
    expect(find.text('Đã tắt Màu sắc'), findsOneWidget);
    await t.tap(find.text('Hoàn tác'));
    await _settle(t);
    expect(_body(api.hit('PUT', '/admin/thuoc-tinh/30/trang-thai').last), {'is_active': true});

    await _more(t, 15, 'del');
    expect(find.text('Không xoá được: RAM đang dùng ở 2 sản phẩm. Tắt đi để thôi dùng.'), findsOneWidget);
    expect(api.hit('DELETE', '/admin/thuoc-tinh/15'), isEmpty);

    await _more(t, 40, 'del');
    expect(find.text('Xoá thuộc tính “Chất liệu”?'), findsOneWidget);
    expect(find.text('Xoá cả 2 giá trị bên trong. Không hoàn tác được.'), findsOneWidget);
    await t.tap(find.byKey(const Key('attr_del_yes')));
    await _settle(t);
    expect(api.hit('DELETE', '/admin/thuoc-tinh/40'), hasLength(1));
    expect(_row(40), findsNothing);
  });

  testWidgets('chọn nhiều → Xoá: bỏ qua thuộc tính đang có hàng dùng', (t) async {
    final (api, _) = await _open(t);
    await t.tap(find.byKey(const Key('attr_select')));
    await _settle(t, 3);
    await t.tap(_row(15));
    await t.tap(_row(40));
    await _settle(t, 3);
    expect(t.widget<Text>(find.byKey(const Key('attr_sel_count'))).data, 'Đã chọn 2');
    await t.tap(find.byKey(const Key('attr_sel_delete')));
    await _settle(t, 5);
    expect(find.textContaining('Bỏ qua 1 thuộc tính đang có hàng dùng: RAM (2 sp)'), findsOneWidget);
    await t.tap(find.byKey(const Key('attr_del_yes')));
    await _settle(t);
    expect(api.hit('DELETE', '/admin/thuoc-tinh/40'), hasLength(1));
    expect(api.hit('DELETE', '/admin/thuoc-tinh/15'), isEmpty);
  });

  testWidgets('chỉ có quyền xem: không có +, Chọn; chạm dòng mở ngăn xem (không mở hộp sửa)', (t) async {
    await _open(t, api: _Api(perms: ['thuoc-tinh.xem']));
    expect(find.byTooltip('Thêm thuộc tính'), findsNothing);
    expect(find.byKey(const Key('attr_select')), findsNothing);
    await t.tap(_row(15));
    await _settle(t, 5);
    expect(find.text('Sửa thuộc tính'), findsNothing);
    expect(find.textContaining('Đang dùng ở: '), findsOneWidget);
    expect(find.byKey(const Key('attr_act_edit')), findsNothing);
    expect(find.byKey(const Key('attr_act_del')), findsNothing);
  });

  testWidgets('khung app: Thêm → Hàng hoá → Thuộc tính mở trong khung, tab Thêm sáng', (t) async {
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
    await t.tap(find.text('Thuộc tính'));
    await _settle(t);
    expect(find.byType(AttributesScreen), findsOneWidget);
    expect(t.widget<AdminTabBar>(find.byType(AdminTabBar)).moreActive, isTrue);
  });

  testWidgets('máy hẹp 360×640: danh sách và hộp sửa (hiện mã, mở dán nhiều) không tràn', (t) async {
    await _open(t, size: const Size(360, 640));
    await t.tap(_row(30));
    await _settle(t);
    await _tapIn(t, 'attr_v_codes');
    await _tapIn(t, 'attr_v_bulk');
    expect(t.takeException(), isNull);
  });
}
