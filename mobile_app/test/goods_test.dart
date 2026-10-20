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
import 'package:selliotech/features/admin/goods/goods_screen.dart';
import 'package:selliotech/features/admin/goods/goods_widgets.dart';
import 'package:selliotech/features/admin/goods/label_print.dart';
import 'package:pdf/pdf.dart';
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
  _Api({
    this.productsCode = 200,
    this.autoSku = true,
    this.perms,
    this.lowStock,
    this.inventoryCode = 200,
    this.extraProducts = 0,
    this.laterPageDelay = Duration.zero,
  }) {
    // Hàng giả thêm để thử tải dần nhiều trang.
    for (var i = 0; i < extraProducts; i++) {
      final id = 1000 + i;
      (s['products'] as List).add({
        'id': id,
        'name': 'Hàng thêm $i',
        'sku': 'X$id',
        'status': 'active',
        'is_active': true,
        'base_price': 1000,
        'created_at': '2026-01-01T00:00:00+07:00',
        'category_id': 3,
      });
      (s['inventory'] as List).add({
        'variant_id': id * 10,
        'product_id': id,
        'sku': 'X$id',
        'stock_quantity': 50,
        'price': 1000,
        'is_active': true,
      });
    }
    // Một mã vạch chỉ có ở phiên bản — tìm phải qua API mới thấy.
    (details['4']!['variants'] as List).first['barcode'] = '8930000000017';
    // Iphone 16: phiên bản mang tổ hợp thuộc tính như API thật (ảnh chụp thiết kế đã lược bỏ).
    final v2 = (details['2']!['variants'] as List).first as Map;
    v2['is_default'] = true;
    v2['attributes'] = [
      {
        'attribute_id': 14,
        'value_id': 91,
        'attribute': {'name': 'Dung lượng lưu trữ'},
        'value': {'name': '512GB'},
      },
      {
        'attribute_id': 15,
        'value_id': 96,
        'attribute': {'name': 'RAM'},
        'value': {'name': '8GB'},
      },
    ];
    details['2']!['category_id'] = 4;
    details['2']!['slug'] = 'iphone-16-pro-max';
  }

  final int productsCode;

  /// Quyền trả về ở /admin/quyen-cua-toi (null = không đọc được → coi như đủ quyền).
  final List<String>? perms;

  /// low_stock_threshold trong cài đặt (null = không đọc được).
  final int? lowStock;
  final int inventoryCode;
  final int extraProducts;

  /// Trang 2 trở đi trả chậm — để thấy danh sách hiện trang đầu trước.
  final Duration laterPageDelay;

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
    if ('${q['page'] ?? '1'}' != '1' && laterPageDelay > Duration.zero) await Future<void>.delayed(laterPageDelay);
    if (p.endsWith('/admin/orders/pos/mau-in/tem')) {
      return _ok({
        'ma': 'tem',
        'settings': {'kho_tem': '40x30', 'cot': 3, 'co': 9},
        'options': {'ten': true, 'gia': true, 'donvitien': true, 'dvt': false, 'bienthe': true, 'nguoc': false},
      });
    }
    if (p.endsWith('/admin/quyen-cua-toi')) {
      if (perms == null) return _json(403, {'success': false, 'message': 'Không có quyền'});
      return _ok({'toan_quyen': false, 'quyen': perms});
    }
    if (p.endsWith('/admin/settings')) {
      if (lowStock == null) return _json(403, {'success': false, 'message': 'Không có quyền'});
      return _ok({
        'values': {'low_stock_threshold': '$lowStock'},
      });
    }
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
    if (p.endsWith('/admin/inventory')) {
      if (inventoryCode != 200) return _json(inventoryCode, {'success': false, 'message': 'Không có quyền'});
      return _page(s['inventory'] as List, q);
    }
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
      for (final it in ((o.data as Map)['items'] as List).cast<Map>()) {
        for (final d in details.values) {
          for (final v in (d['variants'] as List).cast<Map>()) {
            if (v['id'] == it['variant_id']) v['cost_price'] = it['cost_price'];
          }
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
        // Ghi lại như API: trường của mặt hàng + phiên bản theo id (giữ tồn, thuộc tính).
        final old = {for (final v in (d['variants'] as List).cast<Map>()) v['id']: v};
        for (final k in ['name', 'base_price', 'sale_price', 'cost_price', 'vat', 'status', 'description', 'sku']) {
          if (body.containsKey(k)) d[k] = body[k];
        }
        d['variants'] = [
          for (final v in (body['variants'] as List).cast<Map>())
            {...?old[v['id']], ...v, 'attributes': old[v['id']]?['attributes'] ?? v['attributes']},
        ];
        final p = products.firstWhere((x) => '${x['id']}' == m.group(1));
        p['name'] = d['name'];
        p['status'] = d['status'];
        return _ok(d);
      }
      if (m.group(2) == null && o.method == 'DELETE') {
        (s['products'] as List).removeWhere((x) => '${(x as Map)['id']}' == m.group(1));
        (s['inventory'] as List).removeWhere((x) => '${(x as Map)['product_id']}' == m.group(1));
        return _ok(null);
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
        labelPrinterProvider.overrideWithValue(_printer),
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

/// Máy in giả: ghi lại lệnh in / chia sẻ; xem trước thay bằng khung trống (test không có pdf.js / raster).
class _FakePrinter extends LabelPrinter {
  final printed = <(Uint8List, PdfPageFormat)>[];
  final shared = <Uint8List>[];

  @override
  Future<void> print(Uint8List pdf, String name, PdfPageFormat format) async => printed.add((pdf, format));

  @override
  Future<void> share(Uint8List pdf, String name) async => shared.add(pdf);

  @override
  Widget preview(Future<Uint8List> Function() build, String key) => SizedBox(key: Key('label_preview_$key'));
}

var _printer = _FakePrinter();

/// Danh sách cuộn của form (dưới nó còn danh sách chi tiết, dải ảnh ngang).
Finder get _formScroll => find.descendant(of: find.byType(ProductFormScreen), matching: find.byType(Scrollable)).first;

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
    expect(stockPill(low)!.$3, 'Sắp hết · 3');
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

  testWidgets('chi tiết (v9): giá gốc + lãi phiên bản mặc định, thuộc tính, thông tin như PC, công tắc bán hàng', (
    t,
  ) async {
    final api = await _open(t);
    await t.tap(find.byKey(const Key('goods_2')));
    await _settle(t);
    expect(find.text('Chi tiết sản phẩm'), findsOneWidget);
    expect(find.text('SKU: HH000001'), findsOneWidget);
    // Giá bán = giá gốc base_price; lãi tính trên phiên bản mặc định 28.000.000 − 23.700.000.
    expect(find.descendant(of: find.byKey(const Key('edit_price')), matching: find.text('25.000.000')), findsOneWidget);
    expect(find.text('23.700.000'), findsWidgets);
    expect(find.text('4.300.000'), findsOneWidget);
    expect(find.text('15,4%'), findsOneWidget);
    expect(find.text('IMEI'), findsOneWidget);
    expect(find.text('1 phiên bản'), findsOneWidget);
    for (final q in ['Nhập hàng', 'In mã vạch', 'Sao chép', 'Ngừng bán']) {
      expect(find.text(q), findsOneWidget, reason: 'nút nhanh $q');
    }
    expect(api.hit('/admin/inventory/chi-nhanh').single.queryParameters['keyword'], 'HH000001');
    expect(find.text('Cửa hàng chính'), findsOneWidget);
    expect(find.text('Tổng'), findsOneWidget);

    await t.scrollUntilVisible(find.byKey(const Key('variant_1')), 200);
    expect(find.text('Dung lượng lưu trữ'), findsOneWidget);
    expect(find.text('RAM'), findsOneWidget);
    expect(
      find.textContaining('512GB / 8GB', findRichText: true),
      findsOneWidget,
      reason: 'tên phiên bản ghép giá trị thuộc tính',
    );
    expect(find.text('Mặc định'), findsOneWidget);
    expect(find.text('Vốn 23.700.000 · Tồn 30'), findsOneWidget);

    String kv(String k) => t
        .widgetList<Text>(find.descendant(of: find.byKey(Key('kv_$k')), matching: find.byType(Text)))
        .map((w) => w.data)
        .join('|');
    await t.scrollUntilVisible(find.byKey(const Key('product_meta')), 200);
    expect(kv('Mã hàng'), 'Mã hàng|HH000001');
    expect(kv('Mã vạch'), 'Mã vạch|Theo từng phiên bản');
    expect(kv('Nhóm hàng'), 'Nhóm hàng|Hàng bán › Thiết bị điện tử');
    expect(kv('Đơn vị tính'), 'Đơn vị tính|Chưa chọn');
    expect(kv('Loại thuế'), 'Loại thuế|Thuế mặc định');
    expect(kv('% VAT'), '% VAT|10%');
    expect(kv('Giá sau thuế'), 'Giá sau thuế|27.500.000');
    expect(kv('Vị trí'), 'Vị trí|Chưa xếp kệ');
    expect(kv('Chi nhánh bán'), 'Chi nhánh bán|Tất cả chi nhánh');
    expect(kv('Thẻ hàng hoá'), 'Thẻ hàng hoá|Chưa gắn');
    expect(kv('Mô tả'), 'Mô tả|Chưa có');
    for (final f in ['Đang bán_on', 'In tem_on', 'Trừ kho_on', 'Serial / IMEI_on']) {
      expect(find.byKey(Key('flag_$f')), findsOneWidget, reason: f);
    }
    expect(t.widget<Text>(find.byKey(const Key('product_meta'))).data, startsWith('Đã bán 30 · Tạo 20/08/2026 · Sửa '));

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
    expect(find.text('Dung lượng lưu trữ'), findsNothing, reason: 'hàng một phiên bản không có khối thuộc tính');
    await t.scrollUntilVisible(find.byKey(const Key('product_meta')), 200);
    expect(kv('Mã vạch'), 'Mã vạch|8930000000017');
    expect(find.byKey(const Key('flag_Serial / IMEI_off')), findsOneWidget);
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
    expect(find.text('Tạm ẩn'), findsOneWidget, reason: 'nhãn trạng thái theo API: hidden = Tạm ẩn');

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

  testWidgets('sửa giá bán = giá gốc: ô trống bị chặn; hàng nhiều phiên bản giữ giá riêng từng phiên bản', (t) async {
    final api = await _open(t);
    await t.tap(find.byKey(const Key('goods_2')));
    await _settle(t);
    await t.tap(find.byKey(const Key('edit_price')));
    await _settle(t, 5);
    expect(find.text('Sửa giá bán'), findsOneWidget);
    expect(find.textContaining('Đây là giá gốc', findRichText: true), findsOneWidget);
    await t.enterText(find.byKey(const Key('money_input')), '');
    await t.pump();
    await t.tap(find.byKey(const Key('money_save')));
    await t.pump();
    expect(find.text('Nhập giá bán.'), findsOneWidget);
    expect(api.calls.where((c) => c.method == 'PUT'), isEmpty);

    await t.enterText(find.byKey(const Key('money_input')), '26000000');
    await t.pump();
    await t.tap(find.byKey(const Key('money_save')));
    await _settle(t);
    final put = api.calls.where((c) => c.method == 'PUT').single;
    expect(put.path, endsWith('/admin/products/2'));
    final body = put.data as Map;
    final v = (body['variants'] as List).cast<Map>().single;
    expect(body['base_price'], 26000000);
    expect(
      (v['id'], v['price'], v['name'], v['is_default']),
      (1, 28000000, '24 tháng · Mới 100% (nguyên seal) · iOS · 8GB · 512GB', true),
      reason: 'giữ id, giá riêng, tên, cờ mặc định của phiên bản',
    );
    expect(v['attributes'], [
      {'attribute_id': 14, 'value_id': 91},
      {'attribute_id': 15, 'value_id': 96},
    ]);
    expect(
      (body['name'], body['status'], body['vat'], body['is_multi_variant']),
      ('Iphone 16 pro max', 'active', 10, true),
    );
    expect(body.containsKey('images'), isFalse, reason: 'không đụng ảnh thì không gửi — API giữ nguyên');
    expect(find.text('Đã lưu giá bán 26.000.000 đ'), findsOneWidget);
    expect(find.descendant(of: find.byKey(const Key('edit_price')), matching: find.text('26.000.000')), findsOneWidget);
  });

  testWidgets('sửa sản phẩm: form đổ sẵn dữ liệu, lưu PUT giữ id + mã phiên bản; chi tiết cập nhật', (t) async {
    final api = await _open(t);
    await t.tap(find.byKey(const Key('goods_4')));
    await _settle(t);
    await t.tap(find.byKey(const Key('product_edit')));
    await _settle(t, 12);
    expect(find.text('Sửa sản phẩm'), findsWidgets);
    expect(find.descendant(of: find.byKey(const Key('form_name')), matching: find.text('iphone 15')), findsOneWidget);
    expect(find.text('Hàng bán › Thiết bị điện tử › điện thoại'), findsOneWidget);
    expect(find.descendant(of: find.byKey(const Key('form_price')), matching: find.text('25.000.000')), findsOneWidget);
    expect(find.text('Lưu thay đổi'), findsOneWidget);
    expect(find.text('Lưu & thêm tiếp'), findsNothing);

    await t.enterText(find.byKey(const Key('form_name')), 'iPhone 15 128GB');
    await t.enterText(find.byKey(const Key('form_cost')), '21000000');
    await t.pump();
    await t.tap(find.byKey(const Key('form_save')));
    await _settle(t, 12);
    final put = api.calls.where((c) => c.method == 'PUT' && c.path.endsWith('/admin/products/4')).single;
    final b = put.data as Map;
    expect(
      (b['name'], b['base_price'], b['cost_price'], b['category_id'], b['sku']),
      ('iPhone 15 128GB', 25000000, 21000000, 5, 'HH000003'),
    );
    final v = (b['variants'] as List).cast<Map>().single;
    expect(
      (v['id'], v['sku'], v['barcode'], v['price'], v['cost_price']),
      (3, 'HH000003', '8930000000017', 25000000, 21000000),
    );
    expect(b['is_multi_variant'], false);
    expect(find.text('Đã lưu thay đổi'), findsOneWidget);
    expect(find.text('iPhone 15 128GB'), findsOneWidget, reason: 'về chi tiết, tên mới');
  });

  testWidgets('sửa hàng nhiều phiên bản không đổi gì: lưu lại giữ nguyên phiên bản (id, tên, mặc định, giá)', (
    t,
  ) async {
    final api = await _open(t);
    await t.tap(find.byKey(const Key('goods_2')));
    await _settle(t);
    await t.tap(find.byKey(const Key('product_edit')));
    await _settle(t, 12);
    expect(find.text('1 PHIÊN BẢN'), findsOneWidget);
    expect(find.textContaining('512GB / 8GB', findRichText: true), findsOneWidget);
    await t.tap(find.byKey(const Key('form_save')));
    await _settle(t, 12);
    final b = api.calls.where((c) => c.method == 'PUT').single.data as Map;
    final v = (b['variants'] as List).cast<Map>().single;
    expect(
      (v['id'], v['price'], v['is_default'], v['name'], v['sku']),
      (
        1,
        28000000,
        true,
        '24 tháng · Mới 100% (nguyên seal) · iOS · 8GB · 512GB',
        'HH000001-24-THANG-MOI-100%-(NGUYEN-SEAL)-IOS-8GB-512GB',
      ),
    );
    expect(v['cost_price'], 23700000);
    expect(b.containsKey('images'), isFalse);
  });

  testWidgets('xoá sản phẩm: hỏi lại (nhắc tồn), DELETE rồi về danh sách', (t) async {
    final api = await _open(t);
    await t.tap(find.byKey(const Key('goods_4')));
    await _settle(t);
    await t.tap(find.byKey(const Key('product_edit')));
    await _settle(t, 12);
    await t.tap(find.byKey(const Key('form_delete')));
    await _settle(t, 5);
    expect(find.text('Xoá sản phẩm?'), findsOneWidget);
    expect(find.textContaining('còn tồn 6'), findsOneWidget);
    await t.tap(find.text('Thôi'));
    await _settle(t, 5);
    expect(api.calls.where((c) => c.method == 'DELETE'), isEmpty);
    await t.tap(find.byKey(const Key('form_delete')));
    await _settle(t, 5);
    await t.tap(find.byKey(const Key('form_delete_yes')));
    await _settle(t, 15);
    expect(api.calls.where((c) => c.method == 'DELETE').single.path, endsWith('/admin/products/4'));
    expect(find.text('Đã xoá iphone 15'), findsOneWidget);
    expect(find.text('Chi tiết sản phẩm'), findsNothing, reason: 'đóng cả chi tiết');
    expect(_names(t), isNot(contains('iphone 15')));
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

  testWidgets('vuốt dòng sang trái: lộ Sửa / Xoá; chạm dòng đang mở thì đóng; chỉ mở một dòng', (t) async {
    await _open(t);
    final edit4 = find.byKey(const Key('swipe_edit_4'));
    expect(edit4.hitTestable(), findsNothing, reason: 'chưa vuốt thì nút bị dòng che');
    await t.drag(find.byKey(const Key('goods_4')), const Offset(-220, 0));
    await _settle(t, 4);
    expect(edit4.hitTestable(), findsOneWidget);
    expect(find.byKey(const Key('swipe_delete_4')).hitTestable(), findsOneWidget);

    // Chạm vào dòng đang mở: đóng lại, không mở chi tiết.
    await t.tap(find.byKey(const Key('goods_4')), warnIfMissed: false);
    await _settle(t, 4);
    expect(find.text('Chi tiết sản phẩm'), findsNothing);
    expect(edit4.hitTestable(), findsNothing);

    // Mở dòng 4 rồi vuốt dòng 3: dòng 4 tự đóng.
    await t.drag(find.byKey(const Key('goods_4')), const Offset(-220, 0));
    await _settle(t, 4);
    await t.drag(find.byKey(const Key('goods_3')), const Offset(-220, 0));
    await _settle(t, 4);
    expect(edit4.hitTestable(), findsNothing);
    expect(find.byKey(const Key('swipe_edit_3')).hitTestable(), findsOneWidget);

    // Vuốt ngắn rồi thả: bật về.
    await t.drag(find.byKey(const Key('goods_7')), const Offset(-30, 0));
    await _settle(t, 4);
    expect(find.byKey(const Key('swipe_edit_7')).hitTestable(), findsNothing);
  });

  testWidgets('vuốt → Sửa mở form sửa đổ sẵn; vuốt → Xoá hỏi lại rồi xoá khỏi danh sách', (t) async {
    final api = await _open(t);
    await t.drag(find.byKey(const Key('goods_4')), const Offset(-220, 0));
    await _settle(t, 4);
    await t.tap(find.byKey(const Key('swipe_edit_4')));
    await _settle(t, 12);
    expect(find.text('Sửa sản phẩm'), findsWidgets);
    expect(find.descendant(of: find.byKey(const Key('form_name')), matching: find.text('iphone 15')), findsOneWidget);
    await t.tap(find.byTooltip('Đóng'));
    await _settle(t, 10);

    await t.drag(find.byKey(const Key('goods_4')), const Offset(-220, 0));
    await _settle(t, 4);
    await t.tap(find.byKey(const Key('swipe_delete_4')));
    await _settle(t, 5);
    expect(find.text('Xoá sản phẩm?'), findsOneWidget);
    expect(find.textContaining('còn tồn 6'), findsOneWidget);
    await t.tap(find.byKey(const Key('form_delete_yes')));
    await _settle(t, 10);
    expect(api.calls.where((c) => c.method == 'DELETE').single.path, endsWith('/admin/products/4'));
    expect(find.text('Đã xoá iphone 15'), findsOneWidget);
    expect(_names(t), isNot(contains('iphone 15')));
    expect(_chipText(t, GoodsStatus.all), 'Tất cả 4');
  });

  testWidgets(
    'quyền chỉ xem: ẩn nút Thêm, không vuốt Sửa/Xoá, chi tiết không có Sửa / sửa nhanh / Sao chép / Ngừng bán',
    (t) async {
      final api = await _open(t, api: _Api(perms: ['san-pham.xem']));
      expect(find.byTooltip('Thêm hàng hoá'), findsNothing);
      await t.drag(find.byKey(const Key('goods_4')), const Offset(-220, 0));
      await _settle(t, 4);
      expect(find.byKey(const Key('swipe_edit_4')), findsNothing);
      await t.tap(find.byKey(const Key('goods_4')));
      await _settle(t);
      expect(find.text('Chi tiết sản phẩm'), findsOneWidget);
      expect(find.byKey(const Key('product_edit')), findsNothing);
      expect(find.byKey(const Key('product_duplicate')), findsNothing);
      expect(find.byKey(const Key('product_toggle')), findsNothing);
      await t.tap(find.byKey(const Key('edit_price')));
      await _settle(t, 4);
      expect(find.text('Sửa giá bán'), findsNothing, reason: 'không có quyền sửa thì ô giá không mở');
      expect(api.calls.where((c) => c.method != 'GET'), isEmpty);
    },
  );

  testWidgets('quyền sửa mà không có quyền xoá: vuốt chỉ có Sửa; form sửa không có nút Xoá', (t) async {
    await _open(t, api: _Api(perms: ['san-pham.xem', 'san-pham.sua']));
    await t.drag(find.byKey(const Key('goods_4')), const Offset(-220, 0));
    await _settle(t, 4);
    expect(find.byKey(const Key('swipe_edit_4')).hitTestable(), findsOneWidget);
    expect(find.byKey(const Key('swipe_delete_4')), findsNothing);
    await t.tap(find.byKey(const Key('swipe_edit_4')));
    await _settle(t, 12);
    expect(find.text('Lưu thay đổi'), findsOneWidget);
    expect(find.byKey(const Key('form_delete')), findsNothing);
    expect(find.byKey(const Key('form_image')), findsNothing, reason: 'tải ảnh cần quyền thêm sản phẩm');
  });

  testWidgets('mốc "sắp hết" theo cài đặt cửa hàng', (t) async {
    await _open(t, api: _Api(lowStock: 10));
    expect(_pill(t, 4), 'Sắp hết · 6', reason: 'tồn 6 ≤ mốc 10');
  });

  testWidgets('đổi chi nhánh: cả cửa hàng (không gửi X-Chi-Nhanh, shop_id=0) / đúng chi nhánh 5', (t) async {
    final api = await _open(t);
    Finder label(String t) => find.descendant(of: find.byKey(const Key('goods_branch')), matching: find.text(t));
    expect(label('Cửa hàng chính'), findsOneWidget, reason: 'mặc định chi nhánh trụ sở');
    await t.tap(find.byKey(const Key('goods_branch')));
    await _settle(t, 5);
    api.calls.clear();
    await t.tap(find.byKey(const Key('goods_branch_0')));
    await _settle(t);
    expect(label('Tất cả chi nhánh'), findsOneWidget);
    final inv = api.hit('/admin/inventory').first;
    expect(inv.headers.containsKey('X-Chi-Nhanh'), isFalse, reason: 'không gửi header = tồn cả cửa hàng');
    expect(api.hit('/products').first.queryParameters['shop_id'], '0');

    await t.tap(find.byKey(const Key('goods_branch')));
    await _settle(t, 5);
    api.calls.clear();
    await t.tap(find.byKey(const Key('goods_branch_5')));
    await _settle(t);
    expect(label('Chi nhánh 2'), findsOneWidget);
    expect(api.hit('/admin/inventory').first.headers['X-Chi-Nhanh'], '5');
    expect(api.hit('/products').first.queryParameters['shop_id'], '5');
  });

  testWidgets('tải dần: trang đầu hiện ngay kèm "Đang tải thêm", các trang sau nối vào', (t) async {
    final api = await _open(t, api: _Api(extraProducts: 230, laterPageDelay: const Duration(seconds: 3)));
    expect(find.byKey(const Key('goods_loading_more')), findsNothing, reason: 'chưa cuộn tới cuối');
    expect(_chipText(t, GoodsStatus.all), 'Tất cả 100', reason: 'mới có trang đầu');
    final list = find.descendant(of: find.byType(GoodsScreen), matching: find.byType(Scrollable)).last;
    await t.scrollUntilVisible(find.byKey(const Key('goods_loading_more')), 2000, scrollable: list);
    expect(find.text('Đang tải thêm… 100/235 sản phẩm'), findsOneWidget);
    await t.pump(const Duration(seconds: 4));
    await _settle(t, 5);
    expect(_chipText(t, GoodsStatus.all), 'Tất cả 235');
    expect(api.hit('/products').map((c) => c.queryParameters['page']).toSet(), {'1', '2', '3'});
    await t.scrollUntilVisible(find.text('Đã hiện hết 235 sản phẩm'), 2000, scrollable: list);
  });

  testWidgets('thiếu quyền xem tồn: vẫn có danh sách, không chip "Hết hàng", không viên tồn sai', (t) async {
    await _open(t, api: _Api(inventoryCode: 403));
    expect(_names(t), hasLength(5));
    expect(_chip(GoodsStatus.out), findsNothing);
    expect(
      find.descendant(of: find.byKey(const Key('goods_7')), matching: find.byKey(const Key('stock_pill'))),
      findsNothing,
    );
    expect(_pill(t, 8), 'Ngừng bán');
  });

  test('PDF tem: mỗi trang một hàng tem, đủ số tem', () async {
    TestWidgetsFlutterBinding.ensureInitialized();
    final items = [
      const LabelItem(name: 'Iphone 16', variant: '512GB', code: '8930000000017', price: 28000000, qty: 4),
      const LabelItem(name: 'Cáp sạc', code: 'HH000003', price: 150000),
    ];
    final pdf = await buildLabelsPdf(items, const LabelSettings(cols: 3), shop: 'Cửa hàng chính');
    expect(utf8.decode(pdf.sublist(0, 5), allowMalformed: true), '%PDF-');
    expect(
      RegExp(r'/Type\s*/Page\b').allMatches(latin1.decode(pdf)).length,
      2,
      reason: '5 tem, 3 tem một hàng → 2 hàng',
    );
    expect(labelCodeOk('HH000001-24-THANG'), isTrue);
    expect(labelCodeOk('Đen-128'), isFalse, reason: 'Code128 không nhận chữ có dấu');
  });

  testWidgets('in tem mã vạch: theo mẫu tem cửa hàng, chọn số tem, In gửi PDF đúng khổ; Chia sẻ PDF', (t) async {
    _printer = _FakePrinter();
    await _open(t);
    await t.tap(find.byKey(const Key('goods_2')));
    await _settle(t);
    await t.tap(find.byKey(const Key('product_labels')));
    await _settle(t, 10);
    expect(find.text('In tem mã vạch'), findsOneWidget);
    expect(find.text('512GB / 8GB'), findsOneWidget);
    expect(find.text('HH000001-24-THANG-MOI-100%-(NGUYEN-SEAL)-IOS-8GB-512GB · 28.000.000đ'), findsOneWidget);
    expect(
      t.widget<GoodsChip>(find.byKey(const Key('label_size_40x30'))).on,
      isTrue,
      reason: 'khổ tem theo mẫu tem của cửa hàng',
    );
    expect(t.widget<GoodsChip>(find.byKey(const Key('label_cols_3'))).on, isTrue);
    expect(find.text('In 1 tem'), findsOneWidget);
    expect(find.byKey(const Key('label_long_warn')), findsOneWidget, reason: 'chưa có mã vạch, SKU 54 ký tự');
    await t.tap(find.descendant(of: find.byKey(const Key('label_qty_0')), matching: find.byTooltip('Thêm')));
    await t.pump();
    expect(find.text('In 2 tem'), findsOneWidget);
    await t.tap(find.byKey(const Key('label_size_50x30')));
    await t.tap(find.byKey(const Key('label_cols_1')));
    await t.pump();
    await t.tap(find.byKey(const Key('label_print')));
    await t.runAsync(() => Future<void>.delayed(const Duration(milliseconds: 500)));
    await _settle(t, 5);
    expect(_printer.printed, hasLength(1));
    final (pdf, format) = _printer.printed.single;
    expect(format.width, closeTo(50 * PdfPageFormat.mm, .01));
    expect(format.height, closeTo(30 * PdfPageFormat.mm, .01));
    expect(RegExp(r'/Type\s*/Page\b').allMatches(latin1.decode(pdf)).length, 2, reason: '2 tem, 1 tem một hàng');

    await t.tap(find.byKey(const Key('label_share')));
    await t.runAsync(() => Future<void>.delayed(const Duration(milliseconds: 500)));
    await _settle(t, 5);
    expect(_printer.shared, hasLength(1));
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
    await t.scrollUntilVisible(find.byKey(const Key('product_meta')), 200);
    expect(t.takeException(), isNull);
    // Form sửa trên máy hẹp.
    await t.tap(find.byKey(const Key('product_edit')));
    await _settle(t, 12);
    await t.scrollUntilVisible(find.byKey(const Key('form_more')), 200, scrollable: _formScroll);
    await _settle(t, 4);
    await t.tap(find.byKey(const Key('form_more')));
    await _settle(t, 4);
    await t.scrollUntilVisible(find.byKey(const Key('form_desc')), 200, scrollable: _formScroll);
    expect(t.takeException(), isNull);
  });
}
