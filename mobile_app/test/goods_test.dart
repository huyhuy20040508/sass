import 'dart:convert';
import 'dart:io';
import 'dart:math' as math;
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:selliotech/core/api/api_client.dart';
import 'package:selliotech/core/format/vn_text.dart';
import 'package:selliotech/core/net/online_provider.dart';
import 'package:selliotech/core/storage/session_store.dart';
import 'package:selliotech/features/admin/dashboard/dashboard_data.dart' show clockProvider;
import 'package:selliotech/features/admin/goods/goods_data.dart';
import 'package:selliotech/features/admin/goods/goods_widgets.dart';
import 'package:selliotech/features/admin/goods/product_form_screen.dart';
import 'package:image_picker/image_picker.dart';
import 'package:selliotech/features/admin/goods/scanner_screen.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:selliotech/features/auth/auth_controller.dart';
import 'package:selliotech/features/auth/session.dart';
import 'package:selliotech/features/workspace/choose_workspace_screen.dart';
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

/// Ảnh chụp API dev (design/html/data/hang-hoa-snapshot.js, đã xoá tên + ảnh) thêm
/// hai mặt hàng giả: "Ốp lưng silicon" hết hàng, "Sạc nhanh 20W" ngừng bán.
Map<String, dynamic> _snap() => jsonDecode(File('test/fixtures/hang_hoa_snapshot.json').readAsStringSync());

/// Đúng đường dẫn (không bắt nhầm /admin/reports/products của Tổng quan).
bool _is(String path, String want) => path == want || path.endsWith('/api/v1$want');

/// API giả cho màn Hàng hoá: /products, /admin/inventory, /categories, chi tiết, tồn chi nhánh.
class _Api implements HttpClientAdapter {
  _Api({this.productsCode = 200, this.autoSku = true}) {
    // Một mã vạch chỉ có ở phiên bản — tìm phải qua API mới thấy.
    (details['4']!['variants'] as List).first['barcode'] = '8930000000017';
  }

  final int productsCode;

  /// Quy tắc mã hàng hoá đang bật (SKU trống → API tự sinh).
  final bool autoSku;
  final calls = <RequestOptions>[];
  int _uploads = 0;
  final s = _snap();

  List<Map<String, dynamic>> get products => [for (final p in s['products'] as List) p as Map<String, dynamic>];
  Map<String, dynamic> get details =>
      (s['details'] as Map).cast<String, dynamic>().map((k, v) => MapEntry(k, v as Map<String, dynamic>));

  ResponseBody _page(List<Object?> rows, Map<String, dynamic> q) {
    final size = int.parse('${q['page_size'] ?? 20}'), page = int.parse('${q['page'] ?? 1}');
    return _json(200, {
      'success': true,
      'data': rows.skip((page - 1) * size).take(size).toList(),
      'meta': {
        'page': page,
        'page_size': size,
        'total': rows.length,
        'total_pages': math.max(1, (rows.length / size).ceil()),
      },
    });
  }

  @override
  Future<ResponseBody> fetch(RequestOptions o, Stream<Uint8List>? _, Future<void>? _) async {
    calls.add(o);
    final q = o.queryParameters;
    final p = o.path;
    if (p.endsWith('/auth/me')) {
      return _ok({'id': 1, 'username': 'admin', 'full_name': 'Quản trị viên', 'access_areas': 'quan_ly'});
    }
    if (p.endsWith('/admin/chi-nhanh')) {
      return _ok([
        {'id': 2, 'name': 'Cửa hàng chính', 'branch_type': 2},
        {'id': 5, 'name': 'Chi nhánh 2', 'branch_type': 1},
      ]);
    }
    if (_is(p, '/products')) {
      if (productsCode != 200) return _json(productsCode, {'success': false, 'message': 'Máy chủ đang bận'});
      final kw = q['keyword']?.toString();
      // LIKE của Postgres phân biệt hoa thường — giữ đúng như API thật.
      bool hit(Map<String, dynamic> x) {
        if (kw == null) return true;
        final vs = (details['${x['id']}']?['variants'] as List?) ?? const [];
        return '${x['name']} ${x['sku']} ${vs.map((v) => '${v['sku']} ${v['barcode'] ?? ''} ${v['name']}').join(' ')}'
            .contains(kw);
      }

      return _page([
        for (final x in products)
          if (hit(x)) {...x}..remove('variants'),
      ], q);
    }
    if (p.endsWith('/admin/inventory')) return _page(s['inventory'] as List, q);
    if (p.endsWith('/admin/don-vi-tinh')) {
      return _ok([
        {'id': 1, 'name': 'Cái', 'is_active': true},
        {'id': 2, 'name': 'Hộp', 'is_active': true},
      ]);
    }
    if (p.endsWith('/admin/thuoc-tinh')) {
      return _ok([
        {
          'id': 30,
          'name': 'Màu',
          'is_active': true,
          'values': [
            {'id': 301, 'name': 'Đen'},
            {'id': 302, 'name': 'Trắng'},
          ],
        },
        {
          'id': 31,
          'name': 'Dung lượng',
          'is_active': true,
          'values': [
            {'id': 311, 'name': '128GB'},
            {'id': 312, 'name': '256GB'},
          ],
        },
      ]);
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
          'ten': 'Thuế đơn bán hàng',
          'muc': [0, 5, -1, -2],
          'is_active': true,
        },
      ]);
    }
    if (p.endsWith('/admin/vi-tri')) return _ok(const []);
    if (p.endsWith('/admin/quy-tac-ma')) {
      return _ok({
        'quy_tac': [
          {'doc_type': 'hang-hoa', 'prefix': 'HH', 'is_active': autoSku},
        ],
      });
    }
    if (p.endsWith('/admin/products/anh')) {
      return _json(201, {
        'success': true,
        'data': {'url': '/uploads/2/a${++_uploads}.jpg'},
      });
    }
    if (p.endsWith('/admin/products') && o.method == 'POST') {
      final b = Map<String, dynamic>.from(o.data as Map);
      if (products.any((x) => '${x['name']}'.toLowerCase() == '${b['name']}'.toLowerCase())) {
        return _json(422, {
          'success': false,
          'message': 'Dữ liệu không hợp lệ',
          'errors': {'name': 'Tên mặt hàng này đã có trong cửa hàng'},
        });
      }
      if (!autoSku && '${b['sku']}'.isEmpty) {
        return _json(422, {
          'success': false,
          'message': 'Dữ liệu không hợp lệ',
          'errors': {'sku': 'Nhập mã hàng hoá'},
        });
      }
      final cat = (s['categories'] as List).cast<Map>().firstWhere((c) => c['id'] == b['category_id']);
      final sku = '${b['sku']}'.isEmpty ? 'HH000099' : b['sku'];
      final id = 50 + (s['products'] as List).length;
      final vs = [
        for (final (i, v) in (b['variants'] as List).cast<Map>().indexed)
          {
            ...v,
            'id': id * 10 + i,
            'sku': '${v['sku'] ?? ''}'.isEmpty ? sku : v['sku'],
            'is_active': true,
            'stock_quantity': 0,
          },
      ];
      final d = {
        ...b,
        'id': id,
        'sku': sku,
        'category': cat,
        'sold_count': 0,
        'created_at': '2026-10-18T08:59:00+07:00',
        'variants': vs,
      };
      (s['products'] as List).add(d);
      (s['details'] as Map)['$id'] = d;
      for (final v in vs) {
        (s['inventory'] as List).add({
          'variant_id': v['id'],
          'product_id': id,
          'product_name': b['name'],
          'variant_name': v['name'] ?? '',
          'sku': v['sku'],
          'stock_quantity': 0,
          'price': v['price'],
          'cost_price': v['cost_price'],
          'is_active': true,
          'product_active': b['status'] == 'active',
        });
      }
      return _json(201, {'success': true, 'data': d});
    }
    final adj = RegExp(r'/admin/inventory/(\d+)$').firstMatch(p);
    if (adj != null && o.method == 'PUT') {
      for (final r in (s['inventory'] as List).cast<Map>()) {
        if ('${r['variant_id']}' == adj.group(1)) {
          r['stock_quantity'] = (r['stock_quantity'] as num) + ((o.data as Map)['quantity'] as num);
        }
      }
      return _ok({'after': 1});
    }
    if (p.endsWith('/admin/inventory/cost')) {
      for (final it in ((o.data as Map)['items'] as List).cast<Map>()) {
        for (final r in (s['inventory'] as List).cast<Map>()) {
          if (r['variant_id'] == it['variant_id']) r['cost_price'] = it['cost_price'];
        }
      }
      return _ok({'changed': 1});
    }
    if (p.endsWith('/categories')) return _ok(s['categories']);
    if (p.endsWith('/admin/inventory/chi-nhanh')) {
      final kw = '${q['keyword'] ?? ''}';
      final by = s['by_branch'] as Map<String, dynamic>;
      final dong = [
        for (final r in (by['dong'] as List).cast<Map<String, dynamic>>())
          if ('${r['sku']} ${r['product_name']}'.contains(kw)) r,
      ];
      return _ok({'dong': dong, 'chi_nhanh': by['chi_nhanh'], 'total': dong.length});
    }
    final m = RegExp(r'/admin/products/(\d+)(/.*)?$').firstMatch(p);
    if (m != null) {
      final d = details[m.group(1)];
      if (d == null) return _json(404, {'success': false, 'message': 'Không thấy sản phẩm'});
      if (m.group(2) == '/status') {
        final on = (o.data as Map)['is_active'] == true;
        for (final x in [d, products.firstWhere((x) => '${x['id']}' == m.group(1))]) {
          x['status'] = on ? 'active' : 'hidden';
          x['is_active'] = on;
        }
        return _ok(d);
      }
      if (m.group(2) == null && o.method == 'PUT') {
        final body = Map<String, dynamic>.from(o.data as Map);
        for (final v in (body['variants'] as List).cast<Map>()) {
          for (final r in (s['inventory'] as List).cast<Map>()) {
            if (r['variant_id'] == v['id']) r['price'] = v['price'];
          }
        }
        return _ok(d);
      }
      if (m.group(2) == '/duplicate') {
        final src = products.firstWhere((x) => '${x['id']}' == m.group(1));
        final copy = {
          ...src,
          'id': 99,
          'name': '${src['name']} (Bản sao)',
          'sku': '${src['sku']}-COPY-1',
          'status': 'hidden',
          'is_active': false,
          'created_at': '2026-10-18T09:00:00+07:00',
        };
        (s['products'] as List).add(copy);
        return _ok(copy);
      }
      return _ok(d);
    }
    // Tổng quan / Đơn hàng chạy nền trong khung app — không cần cho màn này.
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

  List<RequestOptions> hit(String path) => calls.where((c) => _is(c.path, path)).toList();
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

Future<_Api> _open(
  WidgetTester t, {
  _Api? api,
  Size size = const Size(390, 844),
  bool openTab = true,
  Map<String, Object> prefs = const {},
}) async {
  SharedPreferences.setMockInitialValues(prefs);
  t.view.physicalSize = size;
  t.view.devicePixelRatio = 1;
  addTearDown(t.view.reset);
  final a = api ?? _Api();
  await t.pumpWidget(
    ProviderScope(
      overrides: [
        sessionStoreProvider.overrideWithValue(_MemoryStore(_session())),
        onlineProvider.overrideWith((ref) => Stream.value(true)),
        clockProvider.overrideWithValue(() => DateTime(2026, 10, 18, 9)),
        // Test không có camera — màn quét chỉ dùng ô nhập mã tay.
        scannerCameraProvider.overrideWithValue(false),
        pickImageProvider.overrideWithValue((_) async => XFile.fromData(Uint8List.fromList([1, 2, 3]), name: 'a.jpg')),
        pickImagesProvider.overrideWithValue(
          (limit) async => [
            for (var i = 0; i < (limit < 2 ? limit : 2); i++) XFile.fromData(Uint8List.fromList([i]), name: 'p$i.jpg'),
          ],
        ),
        apiClientProvider.overrideWith(
          (ref) => ApiClient(ref.read(authProvider.notifier), dio: Dio()..httpClientAdapter = a),
        ),
      ],
      child: const SelliotechApp(),
    ),
  );
  await _settle(t);
  if (find.byKey(const Key('area_quan_ly')).evaluate().isNotEmpty) {
    await t.tap(find.byKey(const Key('area_quan_ly')));
    await t.pump(ChooseWorkspaceScreen.goingDelay);
    await _settle(t);
  }
  if (openTab) {
    await t.tap(find.byKey(const Key('tab_goods')));
    await _settle(t);
  }
  return a;
}

Future<void> _settle(WidgetTester t, [int n = 10]) async {
  for (var i = 0; i < n; i++) {
    await t.pump(const Duration(milliseconds: 100));
  }
}

Future<_Api> _openForm(WidgetTester t, {_Api? api}) async {
  final a = await _open(t, api: api);
  await t.tap(find.byTooltip('Thêm hàng hoá'));
  await _settle(t);
  expect(find.text('Thêm sản phẩm'), findsOneWidget);
  return a;
}

/// Tên + nhóm "điện thoại" + giá — đủ để lưu.
Future<void> _fillMinimal(WidgetTester t, String name, {required String price}) async {
  await t.enterText(find.byKey(const Key('form_name')), name);
  await t.tap(find.byKey(const Key('form_category')));
  await _settle(t, 5);
  await t.tap(find.byKey(const Key('cat_5')));
  await _settle(t, 5);
  await t.enterText(find.byKey(const Key('form_price')), price);
  await t.pump();
}

/// Tên mặt hàng theo thứ tự đang hiện.
List<String> _names(WidgetTester t) => [for (final r in t.widgetList<GoodsRow>(find.byType(GoodsRow))) r.item.name];

Finder _chip(GoodsStatus s) => find.byKey(Key('goods_st_${s.name}'));

/// Nhãn chip + số trên huy hiệu, vd "Hết hàng 1".
String _chipText(WidgetTester t, GoodsStatus s) =>
    t.widgetList<Text>(find.descendant(of: _chip(s), matching: find.byType(Text))).map((w) => w.data).join(' ');

/// Chữ trên viên tồn của một dòng.
String _pill(WidgetTester t, int id) => t
    .widget<Text>(
      find.descendant(
        of: find.descendant(of: find.byKey(Key('goods_$id')), matching: find.byKey(const Key('stock_pill'))),
        matching: find.byType(Text),
      ),
    )
    .data!;

void main() {
  test('bỏ dấu, nhóm hàng nở cả nhóm con, giá/tồn ghép từ tồn kho', () {
    expect(foldVn('Ốp Lưng ĐẸP'), 'op lung dep');
    expect(compareVn('iphone 15', 'Iphone 16'), lessThan(0));

    final s = _snap();
    final stock = <int, List<StockRow>>{};
    for (final r in (s['inventory'] as List).cast<Map<String, dynamic>>()) {
      (stock[(r['product_id'] as num).toInt()] ??= []).add(StockRow(r));
    }
    final c = GoodsCatalog(
      items: [
        for (final p in (s['products'] as List).cast<Map<String, dynamic>>())
          GoodsItem(p, stock[(p['id'] as num).toInt()] ?? const []),
      ],
      categories: [
        for (final x in (s['categories'] as List).cast<Map>())
          GoodsCategory(id: x['id'] as int, name: '${x['name']}', parentId: x['parent_id'] as int?),
      ],
    );
    // Hàng bán (2) ⊃ Thiết bị điện tử (4) ⊃ điện thoại (5).
    expect(expandCategories({2}, c.categories), {2, 4, 5});
    expect(matchGoods(c, cats: {2}).map((x) => x.id), unorderedEquals([2, 4, 3]));
    expect(matchGoods(c, query: 'OP LUNG').map((x) => x.id), [7]);
    expect(matchGoods(c, query: '893', serverIds: {4}).map((x) => x.id), [4]);

    final ip = c.byId(2)!;
    expect((ip.minPrice, ip.stock, ip.cost), (28000000, 30, 23700000));
    expect(c.byId(4)!.cost, isNull);
    expect(GoodsStatus.out.has(c.byId(7)!), isTrue);
    final low = GoodsItem(c.byId(4)!.j, [
      StockRow({'product_id': 4, 'variant_id': 3, 'sku': 'x', 'stock_quantity': 3, 'price': 1}),
    ]);
    expect(stockPill(low).$3, 'Sắp hết · 3');
    expect(GoodsStatus.out.has(c.byId(8)!), isFalse, reason: 'ngừng bán thì không tính vào hết hàng');
  });

  testWidgets('chưa mở tab thì không tải hàng hoá', (t) async {
    final api = await _open(t, openTab: false);
    expect(api.hit('/products'), isEmpty);
    expect(api.hit('/admin/inventory'), isEmpty);
  });

  testWidgets('danh sách: số theo chip, mới nhất trước, giá + tồn tô màu, nhãn Ngừng bán', (t) async {
    final api = await _open(t);
    final q = api.hit('/products').first.queryParameters;
    expect((q['all'], q['slim'], q['page_size']), ('true', 'true', '100'));

    expect(find.text('5 sản phẩm'), findsOneWidget);
    expect(_chipText(t, GoodsStatus.all), 'Tất cả 5');
    expect(_chipText(t, GoodsStatus.active), 'Đang bán 4');
    expect(_chipText(t, GoodsStatus.out), 'Hết hàng 1');
    expect(_chipText(t, GoodsStatus.hidden), 'Ngừng bán 1');
    expect(_names(t), ['Sạc nhanh 20W', 'Ốp lưng silicon', 'iphone 15', 'laptop asus gamming', 'Iphone 16 pro max']);

    expect(find.text('28.000.000'), findsOneWidget);
    expect(find.text('Đã hiện hết 5 sản phẩm'), findsOneWidget);
    // Viên tồn: hết hàng / ngừng bán / còn; dòng phụ có nhóm hàng; thẻ IMEI.
    expect(_pill(t, 7), 'Hết hàng');
    expect(_pill(t, 8), 'Ngừng bán');
    expect(_pill(t, 4), 'Tồn 6');
    expect(_pill(t, 3), 'Tồn 179');
    expect(find.text('HH000003 · điện thoại'), findsOneWidget);
    expect(find.descendant(of: find.byKey(const Key('goods_2')), matching: find.text('IMEI')), findsOneWidget);
    expect(find.descendant(of: find.byKey(const Key('goods_4')), matching: find.text('IMEI')), findsNothing);
    final dim = t.widget<Opacity>(find.ancestor(of: find.text('Sạc nhanh 20W'), matching: find.byType(Opacity)).first);
    expect(dim.opacity, lessThan(1), reason: 'hàng ngừng bán làm mờ ảnh + tên');
  });

  testWidgets('chip Hết hàng / Ngừng bán lọc đúng', (t) async {
    await _open(t);
    await t.tap(_chip(GoodsStatus.out));
    await _settle(t, 3);
    expect(_names(t), ['Ốp lưng silicon']);
    await t.tap(_chip(GoodsStatus.hidden));
    await _settle(t, 3);
    expect(_names(t), ['Sạc nhanh 20W']);
    await t.tap(_chip(GoodsStatus.active));
    await _settle(t, 3);
    expect(_names(t), hasLength(4));
  });

  testWidgets('tìm: không phân biệt dấu/hoa thường; mã vạch phiên bản qua API; không thấy thì Xem tất cả', (t) async {
    final api = await _open(t);
    await t.enterText(find.byKey(const Key('goods_search')), 'IPHONE');
    await _settle(t, 5);
    expect(_names(t), ['iphone 15', 'Iphone 16 pro max']);
    expect(_chipText(t, GoodsStatus.all), 'Tất cả 2');

    await t.enterText(find.byKey(const Key('goods_search')), 'op lung');
    await _settle(t, 5);
    expect(_names(t), ['Ốp lưng silicon']);

    await t.enterText(find.byKey(const Key('goods_search')), '8930000');
    await _settle(t, 5);
    expect(api.hit('/products').last.queryParameters['keyword'], '8930000');
    expect(_names(t), ['iphone 15']);

    await t.enterText(find.byKey(const Key('goods_search')), 'zzz');
    await _settle(t, 5);
    expect(find.text('Không có sản phẩm nào'), findsOneWidget);
    await t.tap(find.byKey(const Key('goods_reset')));
    await _settle(t, 5);
    expect(_names(t), hasLength(5));
    expect(find.text('zzz'), findsNothing);
  });

  testWidgets('ngăn Lọc nhóm hàng: đếm trước, gồm nhóm con, huy hiệu số nhóm; Đặt lại', (t) async {
    await _open(t);
    await t.tap(find.byKey(const Key('goods_filter_btn')));
    await _settle(t, 5);
    expect(find.text('Lọc theo nhóm hàng'), findsOneWidget);
    expect(find.text('Xem 5 sản phẩm'), findsOneWidget);
    await t.tap(find.byKey(const Key('goods_cat_2')));
    await t.pump();
    expect(find.text('Xem 3 sản phẩm'), findsOneWidget);
    await t.tap(find.text('Đặt lại'));
    await t.pump();
    expect(find.text('Xem 5 sản phẩm'), findsOneWidget);
    await t.tap(find.byKey(const Key('goods_cat_3')));
    await t.pump();
    await t.tap(find.byKey(const Key('goods_apply_filter')));
    await _settle(t, 5);
    expect(_names(t), ['Sạc nhanh 20W', 'Ốp lưng silicon']);
    expect(find.descendant(of: find.byKey(const Key('goods_filter_count')), matching: find.text('1')), findsOneWidget);
    // Chip trạng thái đếm trong phạm vi nhóm đã lọc.
    expect(_chipText(t, GoodsStatus.out), 'Hết hàng 1');
  });

  testWidgets('sắp xếp: giá tăng dần, tên A→Z theo tiếng Việt', (t) async {
    await _open(t);
    await t.tap(find.byKey(const Key('goods_sort_btn')));
    await _settle(t, 5);
    await t.tap(find.byKey(const Key('goods_sort_priceAsc')));
    await _settle(t, 5);
    expect(_names(t).first, 'Ốp lưng silicon');
    expect(find.text('Giá tăng dần'), findsOneWidget);

    await t.tap(find.byKey(const Key('goods_sort_btn')));
    await _settle(t, 5);
    await t.tap(find.byKey(const Key('goods_sort_nameAsc')));
    await _settle(t, 5);
    expect(_names(t), ['iphone 15', 'Iphone 16 pro max', 'laptop asus gamming', 'Ốp lưng silicon', 'Sạc nhanh 20W']);
  });

  testWidgets('chi tiết: giá, tồn theo chi nhánh + Tổng, phiên bản, thông tin', (t) async {
    final api = await _open(t);
    await t.tap(find.byKey(const Key('goods_2')));
    await _settle(t);
    expect(find.text('Chi tiết sản phẩm'), findsOneWidget);
    expect(find.text('SKU: HH000001'), findsOneWidget);
    expect(find.text('23.700.000'), findsOneWidget);
    // Lãi / sp = 28.000.000 − 23.700.000, biên 15,4%.
    expect(find.text('4.300.000'), findsOneWidget);
    expect(find.text('15,4%'), findsOneWidget);
    expect(find.text('IMEI'), findsOneWidget);
    for (final q in ['Nhập hàng', 'In mã vạch', 'Sao chép', 'Ngừng bán']) {
      expect(find.text(q), findsOneWidget, reason: 'nút nhanh $q');
    }
    expect(api.hit('/admin/inventory/chi-nhanh').single.queryParameters['keyword'], 'HH000001');
    expect(find.text('Cửa hàng chính'), findsOneWidget);
    expect(find.text('Chi nhánh 2'), findsOneWidget);
    expect(find.text('Tổng'), findsOneWidget);
    expect(find.text('Phiên bản (1)'), findsOneWidget, reason: 'hàng nhiều phiên bản thì liệt kê phiên bản');
    await t.scrollUntilVisible(find.text('Ngày tạo'), 200);
    expect(find.text('10%'), findsOneWidget);
    expect(find.text('Có'), findsOneWidget);
    expect(find.text('20/08/2026'), findsOneWidget);
    expect(find.text('Sửa sản phẩm'), findsOneWidget);

    await t.tap(find.byTooltip('Quay lại'));
    await _settle(t);
    await t.tap(find.byKey(const Key('goods_4')));
    await _settle(t);
    expect(find.text('+ Nhập'), findsOneWidget, reason: 'chưa khai giá vốn');
    expect(
      find.descendant(of: find.byKey(const Key('gain_cell')), matching: find.text('—')),
      findsOneWidget,
      reason: 'chưa có giá vốn thì chưa tính được lãi',
    );
    expect(find.textContaining('Phiên bản ('), findsNothing, reason: 'hàng một phiên bản không lặp khối phiên bản');
    expect(find.text('Tổng'), findsOneWidget);
  });

  testWidgets('chi tiết: Ngừng bán có xác nhận, gửi is_active=false; danh sách cập nhật; sao chép gửi POST', (t) async {
    final api = await _open(t);
    await t.tap(find.byKey(const Key('goods_2')));
    await _settle(t);
    await t.tap(find.byKey(const Key('product_toggle')));
    await _settle(t, 5);
    expect(find.text('Ngừng bán sản phẩm?'), findsOneWidget);
    await t.tap(find.byKey(const Key('product_confirm')));
    await _settle(t);
    final put = api.calls.where((c) => c.method == 'PUT').single;
    expect(put.path, endsWith('/admin/products/2/status'));
    expect(put.data, {'is_active': false});
    expect(find.text('Đã ngừng bán Iphone 16 pro max'), findsOneWidget);
    expect(find.text('Ngừng bán'), findsWidgets);

    expect(find.text('Mở bán'), findsOneWidget, reason: 'nút nhanh đổi thành Mở bán');
    await t.tap(find.byKey(const Key('product_duplicate')));
    await _settle(t, 5);
    await t.tap(find.byKey(const Key('product_confirm')));
    await _settle(t);
    expect(api.calls.where((c) => c.method == 'POST').single.path, endsWith('/admin/products/2/duplicate'));

    await t.tap(find.byTooltip('Quay lại'));
    await _settle(t);
    expect(_chipText(t, GoodsStatus.hidden), 'Ngừng bán 3');
    expect(_names(t).first, 'Iphone 16 pro max (Bản sao)');
  });

  testWidgets('sửa giá vốn: tính lãi khi gõ, gửi PUT /admin/inventory/cost cho phiên bản', (t) async {
    final api = await _open(t);
    await t.tap(find.byKey(const Key('goods_2')));
    await _settle(t);
    await t.tap(find.byKey(const Key('edit_cost')));
    await _settle(t, 5);
    expect(find.text('Giá vốn'), findsWidgets);
    await t.enterText(find.byKey(const Key('money_input')), '24000000');
    await t.pump();
    expect(find.text('24.000.000'), findsOneWidget, reason: 'tự chấm ngăn nghìn');
    expect(
      t.widget<Text>(find.byKey(const Key('money_hint'))).textSpan!.toPlainText(),
      'Lãi mỗi sản phẩm: 4.000.000đ · 14,3%',
    );
    await t.tap(find.byKey(const Key('money_save')));
    await _settle(t);
    final put = api.calls.where((c) => c.method == 'PUT').single;
    expect(put.path, endsWith('/admin/inventory/cost'));
    expect(put.data, {
      'items': [
        {'variant_id': 1, 'cost_price': 24000000},
      ],
    });
    expect(find.text('Đã lưu giá vốn 24.000.000 đ'), findsOneWidget);
    expect(find.text('24.000.000'), findsOneWidget, reason: 'tải lại danh mục → ô giá vốn mới');
    expect(find.text('4.000.000'), findsOneWidget);
  });

  testWidgets('sửa giá bán: chặn 0đ; gửi lại cả mặt hàng, giữ đủ phiên bản + thuộc tính', (t) async {
    final api = await _open(t);
    await t.tap(find.byKey(const Key('goods_2')));
    await _settle(t);
    await t.tap(find.byKey(const Key('edit_price')));
    await _settle(t, 5);
    expect(find.text('Sửa giá bán'), findsOneWidget);
    await t.enterText(find.byKey(const Key('money_input')), '');
    await t.pump();
    await t.tap(find.byKey(const Key('money_save')));
    await t.pump();
    expect(find.text('Giá bán phải lớn hơn 0.'), findsOneWidget);
    expect(api.calls.where((c) => c.method == 'PUT'), isEmpty);

    await t.enterText(find.byKey(const Key('money_input')), '28500000');
    await t.pump();
    await t.tap(find.byKey(const Key('money_save')));
    await _settle(t);
    final put = api.calls.where((c) => c.method == 'PUT').single;
    expect(put.path, endsWith('/admin/products/2'));
    final body = put.data as Map;
    final d = api.details['2']!;
    final vs = (body['variants'] as List).cast<Map>();
    expect(vs.map((v) => v['id']), [for (final v in d['variants'] as List) v['id']], reason: 'đủ mọi phiên bản');
    expect(vs.first['price'], 28500000);
    expect(vs.first['sku'], (d['variants'] as List).first['sku']);
    expect(body['base_price'], d['base_price'], reason: 'hàng nhiều phiên bản giữ giá gốc');
    expect((body['name'], body['status'], body['vat']), (d['name'], d['status'], d['vat']));
    expect(find.text('Đã lưu giá bán 28.500.000 đ'), findsOneWidget);
    expect(find.text('28.500.000'), findsWidgets);
  });

  testWidgets('quét mã: đúng SKU mở thẳng sản phẩm; mã vạch qua API; mã lạ thì báo và ở lại', (t) async {
    final api = await _open(t);
    Future<void> scan(String code) async {
      await t.enterText(find.byKey(const Key('scan_manual')), code);
      await t.tap(find.byKey(const Key('scan_submit')));
      await _settle(t);
    }

    await t.tap(find.byTooltip('Quét mã vạch'));
    await _settle(t, 5);
    expect(find.text('Đưa mã vạch hoặc SKU vào khung'), findsOneWidget);
    await scan('ZZZ999');
    expect(find.text('Không tìm thấy sản phẩm có mã “ZZZ999”'), findsOneWidget);
    expect(find.text('Quét mã vạch'), findsOneWidget, reason: 'vẫn ở màn quét');

    await scan('hh000003');
    expect(find.text('SKU: HH000003'), findsOneWidget, reason: 'không phân biệt hoa thường');
    await t.tap(find.byTooltip('Quay lại'));
    await _settle(t);

    await t.tap(find.byTooltip('Quét mã vạch'));
    await _settle(t, 5);
    await scan('8930000000017');
    expect(api.hit('/products').last.queryParameters['keyword'], '8930000000017');
    expect(find.text('SKU: HH000003'), findsOneWidget);
  });

  testWidgets('nhớ bộ lọc: mở lại vẫn đúng trạng thái + sắp xếp; đổi thì lưu', (t) async {
    await _open(t, prefs: {'goods.filter': '{"status":"active","cats":[3],"sort":"priceAsc"}'});
    expect(_names(t), ['Ốp lưng silicon']);
    expect(find.text('Giá tăng dần'), findsOneWidget);
    await t.tap(_chip(GoodsStatus.all));
    await _settle(t, 3);
    final saved = (await SharedPreferences.getInstance()).getString('goods.filter');
    expect(saved, '{"status":"all","cats":[3],"sort":"priceAsc"}');
  });

  testWidgets('thêm sản phẩm: thiếu ô bắt buộc thì báo tại ô; giá 0 hợp lệ; đóng khi đã nhập thì hỏi lại', (t) async {
    final api = await _openForm(t);
    await t.tap(find.byKey(const Key('form_save')));
    await t.pump();
    expect(find.text('Nhập tên sản phẩm'), findsOneWidget);
    expect(find.text('Chọn nhóm hàng'), findsWidgets);
    expect(find.text('Nhập giá bán'), findsOneWidget);
    expect(api.calls.where((c) => c.method == 'POST'), isEmpty);

    await t.enterText(find.byKey(const Key('form_name')), 'x');
    await t.enterText(find.byKey(const Key('form_price')), '0');
    await t.pump();
    expect(find.text('Nhập tên sản phẩm'), findsNothing, reason: 'gõ vào thì lỗi ô đó biến mất');
    expect(find.text('Nhập giá bán'), findsNothing, reason: 'giá 0 hợp lệ như PC');

    await t.tap(find.byTooltip('Đóng'));
    await _settle(t, 5);
    expect(find.text('Bỏ sản phẩm đang nhập?'), findsOneWidget);
    await t.tap(find.text('Nhập tiếp'));
    await _settle(t, 5);
    expect(find.text('Thêm sản phẩm'), findsOneWidget);
    await t.tap(find.byTooltip('Đóng'));
    await _settle(t, 5);
    await t.tap(find.byKey(const Key('form_discard')));
    await _settle(t, 10);
    expect(find.text('Thêm sản phẩm'), findsNothing);
  });

  testWidgets(
    'thêm sản phẩm: ảnh, nhóm nhỏ nhất, giá + lãi, chi tiết (mã, quy đổi, VAT, công tắc, thẻ) → payload đúng',
    (t) async {
      final api = await _openForm(t);
      await t.tap(find.byKey(const Key('form_image')));
      await _settle(t, 5);
      await t.tap(find.byKey(const Key('pick_gallery')));
      await _settle(t, 5);
      expect(api.calls.where((c) => c.path.endsWith('/admin/products/anh')), hasLength(2));
      expect(find.text('Đại diện'), findsOneWidget);
      expect(find.text('2/8'), findsOneWidget);

      await t.enterText(find.byKey(const Key('form_name')), 'Cáp sạc USB-C');
      await t.tap(find.byKey(const Key('form_category')));
      await _settle(t, 5);
      // Chỉ nhóm nhỏ nhất: "Hàng bán" / "Thiết bị điện tử" có nhóm con nên không chọn được.
      expect(find.byKey(const Key('cat_2')), findsNothing);
      expect(find.byKey(const Key('cat_4')), findsNothing);
      expect(find.text('Hàng bán › Thiết bị điện tử'), findsOneWidget);
      await t.tap(find.byKey(const Key('cat_5')));
      await _settle(t, 5);
      expect(find.text('Hàng bán › Thiết bị điện tử › điện thoại'), findsOneWidget);

      await t.enterText(find.byKey(const Key('form_price')), '150000');
      await t.enterText(find.byKey(const Key('form_cost')), '90000');
      await t.pump();
      expect(find.text('150.000'), findsOneWidget);
      expect(t.widget<Text>(find.byKey(const Key('form_gain'))).data, '60.000đ · 40%');

      expect(t.widget<Text>(find.byKey(const Key('form_more_sum'))).data, 'SKU tự sinh · VAT 0%');
      await t.ensureVisible(find.byKey(const Key('form_more')));
      await _settle(t, 4);
      await t.tap(find.byKey(const Key('form_more')));
      await _settle(t, 3);

      await t.ensureVisible(find.byTooltip('Quét mã vạch').last);
      await _settle(t, 4);
      await t.tap(find.byTooltip('Quét mã vạch').last);
      await _settle(t, 5);
      await t.enterText(find.byKey(const Key('scan_manual')), '8930000000999');
      await t.tap(find.byKey(const Key('scan_submit')));
      await _settle(t, 12);
      expect(
        find.descendant(of: find.byKey(const Key('form_barcode')), matching: find.text('8930000000999')),
        findsOneWidget,
      );

      await t.ensureVisible(find.byKey(const Key('form_unit')));
      await _settle(t, 4);
      await t.tap(find.byKey(const Key('form_unit')));
      await _settle(t, 5);
      await t.tap(find.byKey(const Key('unit_1')));
      await _settle(t, 5);
      await t.ensureVisible(find.byKey(const Key('form_add_conv')));
      await _settle(t, 4);
      await t.tap(find.byKey(const Key('form_add_conv')));
      await _settle(t, 5);
      await t.tap(find.byKey(const Key('unit_2')));
      await _settle(t, 5);
      await t.enterText(find.byKey(const Key('conv_q_0')), '12');

      await t.ensureVisible(find.byKey(const Key('form_sale')));
      await _settle(t, 4);
      await t.enterText(find.byKey(const Key('form_sale')), '200000');
      await t.enterText(find.byKey(const Key('form_weight')), '50');
      await t.ensureVisible(find.byKey(const Key('vat_10')));
      await _settle(t, 4);
      await t.tap(find.byKey(const Key('vat_10')));
      await t.pump();
      expect(t.widget<Text>(find.byKey(const Key('form_after_tax'))).data, '165.000đ');

      await t.ensureVisible(find.byKey(const Key('sw_active')));
      await _settle(t, 4);
      await t.tap(find.byKey(const Key('sw_active')));
      await t.tap(find.byKey(const Key('sw_serial')));
      await t.pump();
      await t.ensureVisible(find.byKey(const Key('form_branches')));
      await _settle(t, 4);
      await t.tap(find.byKey(const Key('form_branches')));
      await _settle(t, 5);
      await t.tap(find.text('Chi nhánh 2').last);
      await t.pump();
      await t.tap(find.text('Xong'));
      await _settle(t, 5);
      await t.ensureVisible(find.byKey(const Key('form_tag')));
      await _settle(t, 4);
      await t.enterText(find.byKey(const Key('form_tag')), 'hàng mới');
      await t.testTextInput.receiveAction(TextInputAction.done);
      await t.pump();
      await t.enterText(find.byKey(const Key('form_desc')), 'Dây dài 1m');
      await t.pump();
      expect(
        t.widget<Text>(find.byKey(const Key('form_more_sum'))).data,
        'SKU tự sinh · VAT 10% · Cái +1 quy đổi · Lưu nháp · IMEI · 1 chi nhánh · 1 thẻ',
      );

      // Giá KM phải thấp hơn giá bán.
      await t.tap(find.byKey(const Key('form_save')));
      await _settle(t, 5);
      expect(find.text('Giá khuyến mãi phải thấp hơn giá bán'), findsWidgets);
      expect(api.calls.where((c) => c.method == 'POST' && c.path.endsWith('/admin/products')), isEmpty);
      await t.enterText(find.byKey(const Key('form_sale')), '129000');

      await t.tap(find.byKey(const Key('form_save')));
      await _settle(t, 10);
      final b = api.calls.where((c) => c.method == 'POST' && c.path.endsWith('/admin/products')).single.data as Map;
      expect(b['name'], 'Cáp sạc USB-C');
      expect(b['slug'], 'cap-sac-usb-c');
      expect((b['category_id'], b['base_price'], b['cost_price'], b['sale_price']), (5, 150000, 90000, 129000));
      expect(
        (b['vat'], b['unit_id'], b['status'], b['is_serial'], b['is_stock_deducted']),
        (10, 1, 'hidden', true, true),
      );
      expect(b['unit_conversions'], [
        {'unit_id': 2, 'quantity': 12},
      ]);
      expect(b['shop_ids'], [5]);
      expect(b['tags'], ['hàng mới']);
      expect(b['description'], 'Dây dài 1m');
      expect(b['thumbnail'], '/uploads/2/a1.jpg');
      expect((b['images'] as List).map((i) => (i as Map)['url']), ['/uploads/2/a1.jpg', '/uploads/2/a2.jpg']);
      expect(b['is_multi_variant'], false);
      final v = (b['variants'] as List).single as Map;
      expect((v['barcode'], v['price'], v['cost_price'], v['weight_gram']), ('8930000000999', 150000, 90000, 50));
      expect(b['sku'], '', reason: 'để trống cho API tự sinh');

      expect(find.text('Đã thêm sản phẩm'), findsOneWidget);
      expect(t.widget<Text>(find.byKey(const Key('done_sub'))).data, 'Cáp sạc USB-C · HH000099');
    },
  );

  testWidgets('thêm sản phẩm: ngăn "Đã thêm" — nhập tồn ban đầu ghi kho chi nhánh, rồi xem sản phẩm', (t) async {
    final api = await _openForm(t);
    await _fillMinimal(t, 'Cáp sạc USB-C', price: '150000');
    await t.enterText(find.byKey(const Key('form_cost')), '90000');
    await t.tap(find.byKey(const Key('form_save')));
    await _settle(t, 10);
    await t.tap(find.byKey(const Key('done_stock')));
    await _settle(t, 5);
    expect(find.text('Nhập hàng ban đầu'), findsWidgets);
    await t.enterText(find.byKey(const Key('stock_0')), '5');
    await t.tap(find.byKey(const Key('stock_save')));
    await _settle(t, 10);
    final adj = api.calls.where((c) => c.method == 'PUT' && RegExp(r'/admin/inventory/\d+$').hasMatch(c.path)).single;
    expect(adj.data, {
      'mode': 'delta',
      'quantity': 5,
      'type': 'import',
      'unit_cost': 90000,
      'note': 'Tồn ban đầu khi thêm sản phẩm trên app',
    });
    expect(adj.headers['X-Chi-Nhanh'], '2');
    expect(find.text('Đã nhập 5 vào kho'), findsOneWidget);

    await t.tap(find.byKey(const Key('done_view')));
    await _settle(t, 12);
    expect(find.text('SKU: HH000099'), findsOneWidget, reason: 'mở thẳng chi tiết sản phẩm vừa tạo');
    await t.tap(find.byTooltip('Quay lại'));
    await _settle(t);
    expect(_names(t).first, 'Cáp sạc USB-C');
    expect(_pill(t, 55), 'Sắp hết · 5', reason: 'id mới = 50 + 5 mặt hàng có sẵn');
  });

  testWidgets('thêm sản phẩm nhiều phiên bản: tổ hợp, giá cộng thêm, mặc định, bỏ / sửa phiên bản', (t) async {
    final api = await _openForm(t);
    await _fillMinimal(t, 'iPhone 17', price: '10000000');
    await t.ensureVisible(find.byKey(const Key('form_multi')));
    await _settle(t, 4);
    await t.tap(find.byKey(const Key('form_multi')));
    await _settle(t, 5);
    await t.tap(find.byKey(const Key('attr_30')));
    await _settle(t, 5);
    await t.tap(find.byKey(const Key('val_301')));
    await t.tap(find.byKey(const Key('val_302')));
    await t.pump();
    await t.ensureVisible(find.byKey(const Key('form_add_attr')));
    await _settle(t, 4);
    await t.tap(find.byKey(const Key('form_add_attr')));
    await _settle(t, 5);
    await t.tap(find.byKey(const Key('attr_31')));
    await _settle(t, 5);
    await t.ensureVisible(find.byKey(const Key('val_311')));
    await _settle(t, 4);
    await t.tap(find.byKey(const Key('val_311')));
    await t.tap(find.byKey(const Key('val_312')));
    await t.pump();
    await t.ensureVisible(find.byKey(const Key('extra_312')));
    await _settle(t, 4);
    await t.enterText(find.byKey(const Key('extra_312')), '2000000');
    await t.pump();
    expect(find.text('4 PHIÊN BẢN'), findsOneWidget);
    for (final (k, price) in [('301-311', '10.000.000'), ('301-312', '12.000.000'), ('302-312', '12.000.000')]) {
      expect(
        find.descendant(of: find.byKey(Key('cprice_$k')), matching: find.text(price)),
        findsOneWidget,
        reason: 'giá $k = giá gốc + cộng thêm',
      );
    }
    // Bỏ "Trắng / 128GB", sửa tay giá "Trắng / 256GB", gắn mã vạch riêng.
    await t.ensureVisible(find.byKey(const Key('combo_302-311')));
    await _settle(t, 4);
    await t.tap(find.descendant(of: find.byKey(const Key('combo_302-311')), matching: find.byTooltip('Bỏ phiên bản')));
    await t.pump();
    expect(find.text('3 PHIÊN BẢN'), findsOneWidget);
    await t.ensureVisible(find.byKey(const Key('cprice_302-312')));
    await _settle(t, 4);
    await t.enterText(find.byKey(const Key('cprice_302-312')), '12500000');
    await t.enterText(find.byKey(const Key('cbar_302-312')), '8931');
    await t.pump();

    await t.tap(find.byKey(const Key('form_save')));
    await _settle(t, 10);
    final b = api.calls.where((c) => c.method == 'POST' && c.path.endsWith('/admin/products')).single.data as Map;
    expect(b['is_multi_variant'], true);
    final vs = (b['variants'] as List).cast<Map>();
    expect(vs.map((v) => v['name']), ['Đen / 128GB', 'Đen / 256GB', 'Trắng / 256GB']);
    expect(vs.map((v) => v['price']), [10000000, 12000000, 12500000]);
    expect(vs.map((v) => v['is_default']), [true, false, false], reason: 'mặc định = tổ hợp toàn giá trị mặc định');
    expect(vs[2]['barcode'], '8931');
    expect(vs[0]['attributes'], [
      {'attribute_id': 30, 'value_id': 301},
      {'attribute_id': 31, 'value_id': 311},
    ]);
    expect(t.widget<Text>(find.byKey(const Key('done_sub'))).data, 'iPhone 17 · HH000099 · 3 phiên bản');
  });

  testWidgets('Lưu & thêm tiếp: báo đã thêm, form trống lại nhưng giữ nhóm hàng', (t) async {
    final api = await _openForm(t);
    await _fillMinimal(t, 'Món A', price: '1000');
    await t.tap(find.byKey(const Key('form_save_more')));
    await _settle(t, 10);
    expect(api.calls.where((c) => c.method == 'POST' && c.path.endsWith('/admin/products')), hasLength(1));
    expect(find.text('Đã thêm “Món A” (HH000099). Nhập sản phẩm tiếp theo.'), findsOneWidget);
    expect(find.text('Món A'), findsNothing);
    expect(find.text('Hàng bán › Thiết bị điện tử › điện thoại'), findsOneWidget);
    expect(find.text('Thêm sản phẩm'), findsOneWidget);
  });

  testWidgets('thêm sản phẩm: trùng tên thì API báo đúng ô Tên', (t) async {
    await _openForm(t);
    await _fillMinimal(t, 'iphone 15', price: '1000');
    await t.tap(find.byKey(const Key('form_save')));
    await _settle(t);
    expect(find.text('Tên mặt hàng này đã có trong cửa hàng'), findsOneWidget);
    expect(find.text('Thêm sản phẩm'), findsOneWidget, reason: 'vẫn ở form để sửa');
  });

  testWidgets('thêm sản phẩm: cửa hàng tắt tự sinh mã thì bắt nhập SKU ngay trên máy', (t) async {
    final api = await _openForm(t, api: _Api(autoSku: false));
    await _fillMinimal(t, 'Món mới', price: '1000');
    await t.tap(find.byKey(const Key('form_save')));
    await _settle(t, 5);
    expect(find.text('Nhập mã hàng'), findsOneWidget, reason: 'chặn ngay trên máy, mở sẵn "Thêm chi tiết"');
    expect(api.calls.where((c) => c.method == 'POST' && c.path.endsWith('/admin/products')), isEmpty);
  });

  testWidgets('lỗi tải: báo lỗi + Thử lại', (t) async {
    await _open(t, api: _Api(productsCode: 500));
    expect(find.text('Không tải được hàng hoá'), findsOneWidget);
    expect(find.text('Thử lại'), findsOneWidget);
  });

  testWidgets('máy hẹp 360×640: danh sách, ngăn lọc và chi tiết không tràn', (t) async {
    await _open(t, size: const Size(360, 640));
    expect(t.takeException(), isNull);
    await t.tap(find.byKey(const Key('goods_filter_btn')));
    await _settle(t, 5);
    expect(t.takeException(), isNull);
    await t.tap(find.text('Đóng'));
    await _settle(t, 5);
    await t.ensureVisible(find.byKey(const Key('goods_2')));
    await _settle(t, 3);
    await t.tap(find.byKey(const Key('goods_2')));
    await _settle(t);
    await t.scrollUntilVisible(find.text('Ngày tạo'), 200);
    expect(t.takeException(), isNull);
  });
}
