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
import 'package:selliotech/features/admin/categories/categories_data.dart';
import 'package:selliotech/features/admin/categories/categories_screen.dart';
import 'package:selliotech/features/admin/dashboard/dashboard_data.dart' show clockProvider;
import 'package:selliotech/features/admin/goods/goods_data.dart';
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

Map<String, dynamic> _cat(int id, int? parent, String name, String slug, {int vat = 0, bool on = true}) => {
  'id': id,
  'parent_id': parent,
  'name': name,
  'slug': slug,
  'description': 'mô tả $id',
  'vat': vat,
  'image': '',
  'sort_order': 0,
  'is_active': on,
};

/// API giả: cây nhóm như dev (2 nhóm gốc khoá) + vài nhóm thêm; sản phẩm đếm theo nhóm.
class _Api implements HttpClientAdapter {
  _Api({this.perms, this.serverTakenName});

  final List<String>? perms;

  /// Tên mà API báo trùng (422) dù cây trên máy chưa có — vd người khác vừa thêm.
  final String? serverTakenName;
  final calls = <RequestOptions>[];
  var _next = 100;

  final cats = [
    _cat(2, null, 'Hàng bán', 'hang-ban'),
    _cat(3, null, 'Hàng hóa khác', 'hang-hoa-khac'),
    _cat(4, 2, 'Thiết bị điện tử', 'NH0001', vat: 10),
    _cat(5, 4, 'điện thoại', 'DM082601', vat: 10),
    _cat(6, 2, 'Phụ kiện', 'NH0002', on: false),
  ];

  /// category_id của từng sản phẩm: 3 cái trong "điện thoại", 1 trong "Hàng hóa khác".
  final products = [5, 5, 5, 3];

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
    if (p.endsWith('/categories') && o.method == 'GET') return _ok(cats);
    if (p.endsWith('/products')) {
      final rows = [
        for (final (i, c) in products.indexed) {'id': i + 1, 'name': 'SP $i', 'category_id': c, 'status': 'active'},
      ];
      return _json(200, {
        'success': true,
        'data': rows,
        'meta': {'page': 1, 'page_size': 100, 'total': rows.length, 'total_pages': 1},
      });
    }
    if (p.endsWith('/admin/thue')) {
      return _ok([
        {
          'loai': 'mac-dinh',
          'ten': 'Thuế mặc định',
          'muc': [0, 8, 10],
          'is_active': true,
        },
        {
          'loai': 'ban-hang',
          'ten': 'Thuế bán hàng',
          'muc': [0, 5, -1, -2],
          'is_active': true,
        },
      ]);
    }
    if (p.endsWith('/admin/categories') && o.method == 'POST') {
      final b = Map<String, dynamic>.from(o.data as Map);
      if (b['name'] == serverTakenName) {
        return _json(422, {
          'success': false,
          'message': 'Dữ liệu không hợp lệ',
          'errors': {'name': 'Tên nhóm hàng hoá này đã có trong cửa hàng'},
        });
      }
      final c = {..._cat(++_next, b['parent_id'] as int?, '${b['name']}', 'NH0$_next'), ...b, 'slug': 'NH0$_next'};
      cats.add(c);
      return _json(201, {'success': true, 'data': c});
    }
    final m = RegExp(r'/admin/categories/(\d+)$').firstMatch(p);
    if (m != null) {
      final id = int.parse(m.group(1)!);
      final i = cats.indexWhere((c) => c['id'] == id);
      if (o.method == 'PUT') {
        cats[i] = {...cats[i], ...Map<String, dynamic>.from(o.data as Map)};
        return _ok(cats[i]);
      }
      if (o.method == 'DELETE') {
        cats.removeAt(i);
        return _ok(null);
      }
    }
    // Phần khác của khung app (Tổng quan, Đơn hàng…) không cần cho màn này.
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

/// Màn đứng riêng (không qua khung app) — đủ cho mọi luồng trong màn.
Future<(_Api, _Host, ProviderContainer)> _open(WidgetTester t, {_Api? api}) async {
  SharedPreferences.setMockInitialValues({});
  t.view.physicalSize = const Size(390, 844);
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
        home: Scaffold(body: CategoriesScreen(host: h.host)),
      ),
    ),
  );
  await _settle(t);
  return (a, h, c);
}

Finder _row(int id) => find.byKey(Key('cat_$id'));
String _title(WidgetTester t) => t.widget<Text>(find.byKey(const Key('cat_title'))).data!;

/// Ô / nút trong hộp Thêm-Sửa có thể nằm dưới mép — cuộn tới rồi mới chạm.
Future<void> _tapIn(WidgetTester t, String key) async {
  await t.ensureVisible(find.byKey(Key(key)));
  await _settle(t, 3);
  await t.tap(find.byKey(Key(key)));
  await _settle(t, 3);
}

Future<void> _more(WidgetTester t, int id, String action) async {
  await t.tap(find.byKey(Key('cat_more_$id')));
  await _settle(t, 5);
  await t.tap(find.byKey(Key('cat_act_$action')));
  await _settle(t);
}

void main() {
  test('cây: đếm hàng gồm nhóm con, lý do chặn xoá, VAT dương trước KCT/KKKNT', () {
    final tree = CategoryTree([for (final c in _Api().cats) Category.fromJson(c)], {5: 3, 3: 1});
    expect(tree.roots.map((c) => c.name), ['Hàng bán', 'Hàng hóa khác']);
    expect(tree.products(2), 3);
    expect(tree.products(4), 3);
    expect(tree.whyNotDelete(tree.byId(2)!), 'nhóm gốc cố định');
    expect(tree.whyNotDelete(tree.byId(4)!), 'đang chứa 1 nhóm con');
    expect(tree.whyNotDelete(tree.byId(5)!), 'đang có 3 sản phẩm');
    expect(tree.whyNotDelete(tree.byId(6)!), isNull);
    expect(tree.pathOf(tree.byId(5)!).map((c) => c.name), ['Hàng bán', 'Thiết bị điện tử']);
    expect(tree.taken('  THIẾT bị   điện tử ', {}), isTrue);
    expect(tree.taken('Thiết bị điện tử', {4}), isFalse);
    // PUT phải gửi lại đủ mã, mô tả, nhóm cha — API ghi đè mọi trường.
    expect(tree.byId(4)!.body(name: 'X'), {
      'name': 'X',
      'slug': 'NH0001',
      'parent_id': 2,
      'description': 'mô tả 4',
      'vat': 10,
      'image': '',
      'sort_order': 0,
      'is_active': true,
    });
  });

  testWidgets('màn đầu: thẻ nhóm gốc có khoá, bày sẵn nhóm cấp 1, không nút lùi', (t) async {
    await _open(t);
    expect(_title(t), 'Nhóm hàng hoá');
    expect(find.byTooltip('Quay lại'), findsNothing);
    expect(find.text('Nhóm gốc'), findsNWidgets(2));
    expect(find.text('2 nhóm con · 3 sản phẩm'), findsOneWidget);
    expect(find.text('Chưa có nhóm con · 1 sản phẩm'), findsOneWidget);
    expect(_row(4), findsOneWidget);
    expect(_row(6), findsOneWidget);
    expect(find.text('Ngừng dùng'), findsOneWidget);
    expect(find.text('NH0001 · 1 nhóm con · 3 sản phẩm'), findsOneWidget);
    expect(find.text('5 nhóm · chạm vào một nhóm để xem nhóm con'), findsOneWidget);
  });

  testWidgets('vào nhóm: tiêu đề, đường dẫn, dải Mã·VAT; lùi bằng nút, đường dẫn và Back', (t) async {
    final (_, h, _) = await _open(t);
    await t.tap(_row(4));
    await _settle(t);
    expect(_title(t), 'Thiết bị điện tử');
    expect(find.text('Mã NH0001 · VAT 10%'), findsOneWidget);
    expect(find.text('3 sản phẩm'), findsOneWidget);
    expect(find.text('NHÓM CON (1)'), findsOneWidget);
    await t.tap(_row(5));
    await _settle(t);
    expect(_title(t), 'điện thoại');
    expect(find.text('Chưa có nhóm con'), findsOneWidget);
    expect(find.textContaining('Nhóm này đang chứa 3 sản phẩm'), findsOneWidget);

    await t.tap(find.text('Thiết bị điện tử').last);
    await _settle(t);
    expect(_title(t), 'Thiết bị điện tử');
    // Nút lùi lên đúng một bậc (nhóm cha), "Tất cả" trên đường dẫn về thẳng màn đầu.
    await t.tap(find.byTooltip('Quay lại'));
    await _settle(t);
    expect(_title(t), 'Hàng bán');
    await t.tap(find.text('Tất cả'));
    await _settle(t);
    expect(_title(t), 'Nhóm hàng hoá');

    // Back ở màn đầu = rời màn (trả về khung app).
    await t.binding.handlePopRoute();
    await _settle(t, 3);
    expect(h.closed, 1);
  });

  testWidgets('tìm theo tên không dấu / mã trên cả cây, kèm đường dẫn nhóm cha', (t) async {
    await _open(t);
    await t.enterText(find.byKey(const Key('cat_search')), 'dien');
    await _settle(t, 4);
    expect(_title(t), 'Tìm nhóm hàng');
    expect(find.text('2 KẾT QUẢ'), findsOneWidget);
    expect(find.text('Hàng bán › Thiết bị điện tử'), findsOneWidget);
    await t.enterText(find.byKey(const Key('cat_search')), 'NH0002');
    await _settle(t, 4);
    expect(find.text('1 KẾT QUẢ'), findsOneWidget);
    expect(_row(6), findsOneWidget);
    await t.enterText(find.byKey(const Key('cat_search')), 'zzz');
    await _settle(t, 4);
    expect(find.text('Không có nhóm nào khớp “zzz”'), findsOneWidget);
  });

  testWidgets('thêm ở màn đầu: hỏi nhóm gốc → hộp Thêm; báo thiếu tên, trùng tên; lưu kèm nhóm con nhận VAT', (
    t,
  ) async {
    final (api, _, _) = await _open(t);
    await t.tap(find.byTooltip('Thêm nhóm'));
    await _settle(t, 5);
    expect(find.text('Thêm nhóm vào đâu?'), findsOneWidget);
    await t.tap(find.byKey(const Key('cat_pick_2')));
    await _settle(t);
    expect(find.text('Thêm nhóm hàng hoá'), findsOneWidget);
    expect(find.text('Hàng bán'), findsWidgets);
    // Ô mức thuế xếp cạnh nhau, không giãn mỗi ô thành một dòng.
    expect(t.getSize(find.byKey(const Key('cat_vat_8'))).width, lessThan(100));
    expect(t.getTopLeft(find.byKey(const Key('cat_vat_5'))).dy, t.getTopLeft(find.byKey(const Key('cat_vat_0'))).dy);

    await t.tap(find.byKey(const Key('cat_f_save')));
    await _settle(t, 3);
    expect(find.text('Chưa nhập tên nhóm hàng hoá.'), findsOneWidget);
    await t.enterText(find.byKey(const Key('cat_f_name')), 'thiết bị ĐIỆN tử');
    await t.tap(find.byKey(const Key('cat_f_save')));
    await _settle(t, 3);
    expect(find.text('Tên nhóm hàng hoá này đã có trong cửa hàng.'), findsOneWidget);
    expect(api.hit('POST', '/admin/categories'), isEmpty);

    await t.enterText(find.byKey(const Key('cat_f_name')), 'Đồng hồ');
    await t.tap(find.byKey(const Key('cat_vat_8')));
    await _tapIn(t, 'cat_kid_add');
    await _tapIn(t, 'cat_kid_add');
    await t.enterText(find.byKey(const Key('cat_kid_0')), 'Đồng hồ cơ');
    await t.tap(find.byKey(const Key('cat_f_save')));
    await _settle(t, 3);
    expect(find.text('Còn nhóm con chưa nhập tên.'), findsOneWidget);
    await _tapIn(t, 'cat_kid_x_1');
    await t.tap(find.byKey(const Key('cat_f_save')));
    await _settle(t);

    final posts = api.hit('POST', '/admin/categories');
    expect(posts, hasLength(2));
    expect(posts[0].data, containsPair('parent_id', 2));
    expect(posts[0].data, containsPair('vat', 8));
    expect(posts[1].data, containsPair('name', 'Đồng hồ cơ'));
    expect(posts[1].data, containsPair('parent_id', 101));
    expect(posts[1].data, containsPair('vat', 8));
    expect(find.text('Đã thêm Đồng hồ + 1 nhóm con'), findsOneWidget);
    await t.tap(find.text('Mở'));
    await _settle(t);
    expect(_title(t), 'Đồng hồ');
    expect(find.text('Đồng hồ cơ'), findsOneWidget);
  });

  testWidgets('API báo trùng tên (422) → báo tại ô, giữ hộp và các dòng nhóm con', (t) async {
    final (api, _, _) = await _open(t, api: _Api(serverTakenName: 'Tai nghe'));
    await t.tap(_row(4));
    await _settle(t);
    await t.tap(find.byKey(const Key('cat_add_here')));
    await _settle(t);
    await t.enterText(find.byKey(const Key('cat_f_name')), 'Tai nghe');
    await _tapIn(t, 'cat_kid_add');
    await t.enterText(find.byKey(const Key('cat_kid_0')), 'Có dây');
    await t.tap(find.byKey(const Key('cat_f_save')));
    await _settle(t);
    expect(find.text('Tên nhóm hàng hoá này đã có trong cửa hàng'), findsOneWidget);
    expect(find.text('Có dây'), findsOneWidget);
    // Nhóm mới đặt trong nhóm đang xem, nhận VAT nhóm cha.
    expect(api.hit('POST', '/admin/categories').single.data, containsPair('parent_id', 4));
    expect(api.hit('POST', '/admin/categories').single.data, containsPair('vat', 10));
  });

  testWidgets('sửa qua ⋯: đổi tên + VAT, PUT gửi lại đủ trường; đổi tên nhóm con trong hộp', (t) async {
    final (api, _, _) = await _open(t);
    await _more(t, 4, 'edit');
    expect(find.text('Sửa nhóm hàng hoá'), findsOneWidget);
    expect(find.text('NH0001'), findsOneWidget);
    expect(
      t
          .widget<TextField>(find.descendant(of: find.byKey(const Key('cat_kid_0')), matching: find.byType(TextField)))
          .controller!
          .text,
      'điện thoại',
    );
    await t.enterText(find.byKey(const Key('cat_f_name')), 'Điện tử');
    await t.tap(find.byKey(const Key('cat_vat_5')));
    await t.enterText(find.byKey(const Key('cat_kid_0')), 'Điện thoại');
    await t.tap(find.byKey(const Key('cat_f_save')));
    await _settle(t);
    final puts = api.hit('PUT', '/admin/categories/4');
    expect(puts.single.data, {
      'name': 'Điện tử',
      'slug': 'NH0001',
      'parent_id': 2,
      'description': 'mô tả 4',
      'vat': 5,
      'image': '',
      'sort_order': 0,
      'is_active': true,
    });
    expect(api.hit('PUT', '/admin/categories/5').single.data, containsPair('name', 'Điện thoại'));
    expect(find.text('Đã cập nhật Điện tử'), findsOneWidget);
  });

  testWidgets('ngừng dùng qua ⋯ có Hoàn tác; nhóm đang tắt có dải "Dùng lại"', (t) async {
    final (api, _, _) = await _open(t);
    await _more(t, 4, 'toggle');
    expect(api.hit('PUT', '/admin/categories/4').single.data, containsPair('is_active', false));
    expect(find.text('Đã ngừng dùng Thiết bị điện tử'), findsOneWidget);
    await t.tap(find.text('Hoàn tác'));
    await _settle(t);
    expect(api.hit('PUT', '/admin/categories/4').last.data, containsPair('is_active', true));

    await t.tap(_row(6));
    await _settle(t);
    expect(find.text('Đang ngừng dùng — không chọn được khi thêm hàng.'), findsOneWidget);
    await t.tap(find.byKey(const Key('cat_reuse')));
    await _settle(t);
    expect(api.hit('PUT', '/admin/categories/6').single.data, containsPair('is_active', true));
  });

  testWidgets('xoá: chặn nhóm còn nhóm con; nhóm trống thì hỏi lại rồi DELETE', (t) async {
    final (api, _, _) = await _open(t);
    await _more(t, 4, 'del');
    expect(find.text('Nhóm đang chứa nhóm con — hãy xoá cấp dưới trước.'), findsOneWidget);
    expect(api.hit('DELETE', '/admin/categories/4'), isEmpty);

    await _more(t, 6, 'del');
    expect(find.text('Xoá nhóm “Phụ kiện”?'), findsOneWidget);
    await t.tap(find.byKey(const Key('cat_del_yes')));
    await _settle(t);
    expect(api.hit('DELETE', '/admin/categories/6'), hasLength(1));
    expect(find.text('Đã xoá Phụ kiện'), findsOneWidget);
    expect(_row(6), findsNothing);
  });

  testWidgets('chọn nhiều → Xoá: bỏ qua nhóm không xoá được, nói rõ lý do', (t) async {
    final (api, _, _) = await _open(t);
    await t.tap(find.byKey(const Key('cat_select')));
    await _settle(t, 3);
    await t.tap(_row(4));
    await t.tap(_row(6));
    await _settle(t, 3);
    expect(t.widget<Text>(find.byKey(const Key('cat_sel_count'))).data, 'Đã chọn 2');
    await t.tap(find.byKey(const Key('cat_sel_delete')));
    await _settle(t, 5);
    expect(find.text('Xoá nhóm “Phụ kiện”?'), findsOneWidget);
    expect(find.textContaining('Bỏ qua 1 nhóm: Thiết bị điện tử (đang chứa 1 nhóm con)'), findsOneWidget);
    await t.tap(find.byKey(const Key('cat_del_yes')));
    await _settle(t);
    expect(api.hit('DELETE', '/admin/categories/6'), hasLength(1));
    expect(api.hit('DELETE', '/admin/categories/4'), isEmpty);
    expect(find.byKey(const Key('cat_sel_count')), findsNothing);
  });

  testWidgets('"n sản phẩm" → sang Hàng hoá lọc sẵn nhóm này', (t) async {
    final (_, h, c) = await _open(t);
    await t.tap(_row(4));
    await _settle(t);
    await t.tap(find.byKey(const Key('cat_goods')));
    await _settle(t, 3);
    expect(h.tabs, [AdminTab.goods]);
    expect(c.read(goodsFilterProvider).cats, {4});
  });

  testWidgets('chỉ có quyền xem: không có +, Chọn; ⋯ chỉ còn Mở nhóm / Xem sản phẩm', (t) async {
    await _open(t, api: _Api(perms: ['san-pham.xem']));
    expect(find.byTooltip('Thêm nhóm'), findsNothing);
    expect(find.byKey(const Key('cat_select')), findsNothing);
    await t.tap(find.byKey(const Key('cat_more_4')));
    await _settle(t, 5);
    expect(find.byKey(const Key('cat_act_open')), findsOneWidget);
    expect(find.byKey(const Key('cat_act_goods')), findsOneWidget);
    expect(find.byKey(const Key('cat_act_edit')), findsNothing);
    expect(find.byKey(const Key('cat_act_del')), findsNothing);
  });

  testWidgets('khung app: mở từ Thêm → Hàng hoá → Nhóm hàng hoá; tab Thêm sáng; chạm tab khác là rời màn', (t) async {
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
    await t.tap(find.text('Nhóm hàng hoá'));
    await _settle(t);
    expect(find.byType(CategoriesScreen), findsOneWidget);
    expect(t.widget<AdminTabBar>(find.byType(AdminTabBar)).moreActive, isTrue);

    await t.tap(find.byKey(const Key('tab_orders')));
    await _settle(t);
    expect(find.byType(CategoriesScreen), findsNothing);
    expect(t.widget<AdminTabBar>(find.byType(AdminTabBar)).moreActive, isFalse);
  });

  testWidgets('máy hẹp 360×640: màn đầu, trong nhóm và hộp Thêm không tràn', (t) async {
    SharedPreferences.setMockInitialValues({});
    t.view.physicalSize = const Size(360, 640);
    t.view.devicePixelRatio = 1;
    addTearDown(t.view.reset);
    final c = ProviderContainer(overrides: _overrides(_Api()));
    addTearDown(c.dispose);
    await t.pumpWidget(
      UncontrolledProviderScope(
        container: c,
        child: MaterialApp(
          home: Scaffold(body: CategoriesScreen(host: _Host().host)),
        ),
      ),
    );
    await _settle(t);
    await t.tap(_row(4));
    await _settle(t);
    await t.tap(find.byKey(const Key('cat_add_here')));
    await _settle(t);
    await _tapIn(t, 'cat_kid_add');
    expect(t.takeException(), isNull);
  });
}
