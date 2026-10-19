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
  _Api({this.productsCode = 200}) {
    // Một mã vạch chỉ có ở phiên bản — tìm phải qua API mới thấy.
    (details['4']!['variants'] as List).first['barcode'] = '8930000000017';
  }

  final int productsCode;
  final calls = <RequestOptions>[];
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

Future<_Api> _open(WidgetTester t, {_Api? api, Size size = const Size(390, 844), bool openTab = true}) async {
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

/// Tên mặt hàng theo thứ tự đang hiện.
List<String> _names(WidgetTester t) => [for (final r in t.widgetList<GoodsRow>(find.byType(GoodsRow))) r.item.name];

Finder _chip(GoodsStatus s) => find.byKey(Key('goods_st_${s.name}'));

String _chipText(WidgetTester t, GoodsStatus s) =>
    t.widget<RichText>(find.descendant(of: _chip(s), matching: find.byType(RichText))).text.toPlainText();

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
    final row = find.byKey(const Key('goods_7'));
    final stockText = t.widget<RichText>(
      find.descendant(
        of: row,
        matching: find.byWidgetPredicate((w) => w is RichText && w.text.toPlainText() == 'Tồn: 0'),
      ),
    );
    Color? numColor;
    stockText.text.visitChildren((span) {
      if (span is TextSpan && span.text == '0') numColor = span.style?.color;
      return true;
    });
    expect(numColor, stockColor(0));
    expect(find.descendant(of: find.byKey(const Key('goods_8')), matching: find.text('Ngừng bán')), findsOneWidget);
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
    expect(find.text('1'), findsOneWidget);
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
    expect(api.hit('/admin/inventory/chi-nhanh').single.queryParameters['keyword'], 'HH000001');
    expect(find.text('Cửa hàng chính'), findsOneWidget);
    expect(find.text('Chi nhánh 2'), findsOneWidget);
    expect(find.text('Tổng'), findsOneWidget);
    expect(find.text('Phiên bản (1)'), findsOneWidget, reason: 'hàng nhiều phiên bản thì liệt kê phiên bản');
    await t.scrollUntilVisible(find.text('Ngày tạo'), 200);
    expect(find.text('10%'), findsOneWidget);
    expect(find.text('Có'), findsOneWidget);
    expect(find.text('20/08/2026'), findsOneWidget);
    expect(find.text('In mã vạch'), findsOneWidget);
    expect(find.text('Sửa sản phẩm'), findsOneWidget);

    await t.tap(find.byTooltip('Quay lại'));
    await _settle(t);
    await t.tap(find.byKey(const Key('goods_4')));
    await _settle(t);
    expect(find.text('Chưa có'), findsOneWidget, reason: 'chưa khai giá vốn');
    expect(find.textContaining('Phiên bản ('), findsNothing, reason: 'hàng một phiên bản không lặp khối phiên bản');
    expect(find.text('Tổng'), findsOneWidget);
  });

  testWidgets('chi tiết: Ngừng bán có xác nhận, gửi is_active=false; danh sách cập nhật; sao chép gửi POST', (t) async {
    final api = await _open(t);
    await t.tap(find.byKey(const Key('goods_2')));
    await _settle(t);
    await t.tap(find.byKey(const Key('product_more')));
    await _settle(t, 5);
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

    await t.tap(find.byKey(const Key('product_more')));
    await _settle(t, 5);
    expect(find.text('Mở bán lại'), findsOneWidget);
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
