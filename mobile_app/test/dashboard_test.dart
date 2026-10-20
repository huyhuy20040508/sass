import 'dart:convert';
import 'dart:io';
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:selliotech/core/api/api_client.dart';
import 'package:selliotech/core/format/vn_number.dart';
import 'package:selliotech/core/net/online_provider.dart';
import 'package:selliotech/core/storage/session_store.dart';
import 'package:selliotech/features/admin/admin_shell.dart';
import 'package:selliotech/features/admin/dashboard/dashboard_data.dart';
import 'package:selliotech/features/admin/shell/shell_widgets.dart';
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

final Map<String, dynamic> _snap = jsonDecode(File('test/fixtures/tong_quan_snapshot.json').readAsStringSync());
final _taken = DateTime(2026, 10, 17, 16, 30);

/// API giả trả đúng số đã chụp từ API dev (bản thiết kế dùng cùng bộ số này).
class _Api implements HttpClientAdapter {
  final calls = <RequestOptions>[];
  int revenueCode = 200;
  Map<String, dynamic>? settingsPut;
  Map<String, dynamic> settings = Map<String, dynamic>.from(_snap['settings'] as Map);

  static const _purchases = [
    {'status': 'completed', 'total_amount': 2000000, 'document_date': '2026-10-05'},
    {'status': 'cancelled', 'total_amount': 9000000, 'document_date': '2026-10-06'},
    {'status': 'draft', 'total_amount': 7000000, 'document_date': '2026-10-06'},
    {'status': 'approved', 'total_amount': 1500000, 'document_date': '2026-10-10'},
  ];

  Map<String, dynamic>? _range(String? from, String? to) {
    for (final r in (_snap['ranges'] as Map).values) {
      if (r['from'] == from && r['to'] == to) return r as Map<String, dynamic>;
    }
    return null;
  }

  Map<String, dynamic>? _revenue(String? from, String? to) {
    for (final r in (_snap['ranges'] as Map).values) {
      if (r['revenue']['from'] == from && r['revenue']['to'] == to) return r['revenue'] as Map<String, dynamic>;
      if (r['revenue_prev']['from'] == from && r['revenue_prev']['to'] == to) {
        return r['revenue_prev'] as Map<String, dynamic>;
      }
    }
    return null;
  }

  @override
  Future<ResponseBody> fetch(RequestOptions o, Stream<Uint8List>? _, Future<void>? _) async {
    calls.add(o);
    final q = o.queryParameters.map((k, v) => MapEntry(k, '$v'));
    final p = o.path;
    final r = _range(q['from'], q['to']);
    if (p.endsWith('/auth/me')) {
      return _ok({..._user, 'access_areas': 'quan_ly,thu_ngan'});
    }
    if (p.endsWith('/admin/chi-nhanh')) return _ok(_snap['branches']);
    if (p.endsWith('/reports/revenue')) {
      if (revenueCode != 200) return _json(revenueCode, {'success': false, 'message': 'Máy chủ lỗi'});
      if (q['group_by'] == 'month') return _ok(_snap['ranges']['this-month']['revenue']);
      return _ok(_revenue(q['from'], q['to']) ?? _emptyRevenue(q['from']!, q['to']!));
    }
    if (p.endsWith('/reports/orders')) return _ok(r?['orders'] ?? _snap['ranges']['this-month']['orders']);
    if (p.endsWith('/reports/products')) return _ok(r?['products'] ?? {'items': []});
    if (p.endsWith('/reports/promotions')) return _ok(r?['promotions']?[q['tab']] ?? {'rows': []});
    if (p.endsWith('/phieu-mua-hang')) {
      final mine = q['from_date'] == '2026-10-01' ? _purchases : const [];
      return _page(mine, mine.length);
    }
    if (p.endsWith('/ca-lam-viec')) {
      final src = q['status'] == 'dang_mo' ? _snap['open_shifts'] : _snap['shift_diffs_7d'];
      return _page(src['data'] as List, (src['meta']['total'] as num).toInt());
    }
    if (p.endsWith('/inventory/stats')) return _ok(_snap['inventory_stats']);
    if (p.endsWith('/etax/hoa-don')) return _page(const [], (_snap['einvoice_failed']['meta']['total'] as num).toInt());
    if (p.endsWith('/admin/settings')) {
      if (o.method == 'PUT') {
        settingsPut = Map<String, dynamic>.from(o.data as Map);
        settings = {...settings, ...(settingsPut!['items'] as Map)};
        return _ok(null);
      }
      return _ok({'values': settings});
    }
    return _json(404, {'success': false, 'message': 'không có'});
  }

  Map<String, dynamic> _emptyRevenue(String from, String to) => {
    'from': from,
    'to': to,
    'prev_from': from,
    'prev_to': to,
    'buckets': const [],
    'totals': const {},
    'prev': const {},
  };

  ResponseBody _ok(Object? data) => _json(200, {'success': true, 'data': data});

  ResponseBody _page(List data, int total) => _json(200, {
    'success': true,
    'data': data,
    'meta': {'page': 1, 'page_size': 100, 'total': total, 'total_pages': data.isEmpty ? 0 : 1},
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
  'username': 'admin',
  'full_name': 'Quản trị viên',
  'role': {'id': 1, 'name': 'super_admin', 'display_name': 'Super Admin'},
};

Session _session({int? chiNhanhId}) => Session.fromLogin({
  'access_token': 'a',
  'refresh_token': 'r',
  'user': {..._user, 'access_areas': 'quan_ly,thu_ngan'},
  'tenant': {'id': 2, 'code': 'test', 'name': 'Cửa hàng chính'},
  'chi_nhanh_id': chiNhanhId,
});

Future<(_MemoryStore, _Api)> _open(
  WidgetTester t, {
  _Api? api,
  Size size = const Size(390, 844),
  int? chiNhanhId,
  bool thisMonth = false,
}) async {
  t.view.physicalSize = size;
  t.view.devicePixelRatio = 1;
  addTearDown(t.view.reset);
  final a = api ?? _Api();
  final store = _MemoryStore(_session(chiNhanhId: chiNhanhId));
  await t.pumpWidget(
    ProviderScope(
      overrides: [
        sessionStoreProvider.overrideWithValue(store),
        onlineProvider.overrideWith((ref) => Stream.value(true)),
        clockProvider.overrideWithValue(() => _taken),
        apiClientProvider.overrideWith(
          (ref) => ApiClient(ref.read(authProvider.notifier), dio: Dio()..httpClientAdapter = a),
        ),
      ],
      child: const SelliotechApp(),
    ),
  );
  await _settle(t);
  await t.tap(find.byKey(const Key('area_quan_ly')));
  await t.pump(ChooseWorkspaceScreen.goingDelay);
  await _settle(t);
  if (thisMonth) {
    await t.tap(find.text('Tháng'));
    await _settle(t);
  }
  return (store, a);
}

Future<void> _settle(WidgetTester t, [int n = 10]) async {
  for (var i = 0; i < n; i++) {
    await t.pump(const Duration(milliseconds: 100));
  }
}

String _heroText(WidgetTester t) => t.widget<Text>(find.byKey(const Key('hero_num'))).textSpan!.toPlainText();

void main() {
  group('định dạng số', () {
    test('chấm ngăn nghìn, phẩy thập phân', () {
      expect(dot(1724340000), '1.724.340.000');
      expect(dot(-12500), '-12.500');
      expect(pct(31.818181), '31,8%');
      expect(pct(965.2, 0), '965%');
      expect(decimal(-0.4, 1), '-0,4');
      expect(dm('2026-10-07'), '07/10');
    });
  });

  group('công thức như Tổng quan PC', () {
    test('kỳ xem và kỳ trước cùng độ dài', () {
      final now = DateTime(2026, 1, 15);
      expect(DashPeriod.lastMonth.range(now), (DateTime(2025, 12), DateTime(2025, 12, 31)));
      expect(DashPeriod.days7.range(now), (DateTime(2026, 1, 9), DateTime(2026, 1, 15)));
      expect(prevRange(DateTime(2026, 10, 1), DateTime(2026, 10, 17)), (DateTime(2026, 9, 14), DateTime(2026, 9, 30)));
      expect(prevRange(DateTime(2026, 10, 17), DateTime(2026, 10, 17)), (
        DateTime(2026, 10, 16),
        DateTime(2026, 10, 16),
      ));
    });

    test('thuần = tiền hàng + phí giao − giảm; so sánh; phiếu nhập bỏ huỷ/nháp', () {
      final m = Money.of({'subtotal': 1000, 'shipping': 50, 'discount': 30, 'orders': 2});
      expect(m.net, 1020);
      expect(Delta.of(0, 0), isNull);
      expect(Delta.of(5, 0)!.isNew, isTrue);
      expect(Delta.of(150, 100)!.pct, 50);
      final p = Purchases.of(_Api._purchases);
      expect(p.total, 3500000);
      expect(p.count, 2);
      expect(p.byDay['2026-10-05'], 2000000);
    });
  });

  testWidgets('vào màn: mặc định HÔM NAY + chi nhánh chính (không gộp tất cả)', (t) async {
    final (_, api) = await _open(t);

    expect(find.text('17/10/2026'), findsOneWidget);
    expect(find.text('theo giờ'), findsOneWidget);
    // Không có chi nhánh loại Công ty → chi nhánh chính là id nhỏ nhất (2).
    expect(find.text('Cửa hàng chính'), findsWidgets);
    expect(_heroText(t), '0 đ');
    expect(find.text('▼ 100%'), findsWidgets);
    final rev = api.calls.where((c) => c.path.endsWith('/reports/revenue') && c.queryParameters['group_by'] == 'day');
    expect(rev.every((c) => c.queryParameters['shop_id'] == '2'), isTrue);
    expect(rev.any((c) => c.queryParameters['from'] == '2026-10-17'), isTrue);
    expect(rev.any((c) => c.queryParameters['shop_id'] == '0'), isFalse);
  });

  testWidgets('đã chọn chi nhánh ở màn chọn nơi làm việc thì xem đúng chi nhánh đó', (t) async {
    final (_, api) = await _open(t, chiNhanhId: 5);
    expect(find.text('Chi nhánh 2'), findsWidgets);
    expect(
      api.calls.where((c) => c.path.endsWith('/reports/orders')).every((c) => c.queryParameters['shop_id'] == '5'),
      isTrue,
    );
  });

  testWidgets('Tổng quan tháng này: số khớp bản thiết kế, việc cần chú ý, số đơn đang xử lý', (t) async {
    final (_, api) = await _open(t, thisMonth: true);

    // Thuần tháng 10 = 1.725.000.000 + 0 − 660.000; kỳ trước 172.000.000 − 10.120.000.
    // Tiền ghi số đầy đủ, không rút gọn tr/tỷ.
    expect(_heroText(t), '1.724.340.000 đ');
    expect(find.text('▲ 965%'), findsOneWidget);
    expect(find.text('kỳ trước 161.880.000 đ'), findsOneWidget);
    expect(find.text('472.740.000'), findsOneWidget);
    expect(find.text('30'), findsWidgets);
    expect(find.text('3.500.000'), findsOneWidget, reason: 'nhập hàng chỉ cộng phiếu không huỷ/nháp');
    expect(find.text('2 phiếu'), findsOneWidget);
    expect(find.text('Chưa đặt mục tiêu\nTháng này đã đạt 1.724.340.000 đ thuần.', findRichText: true), findsOneWidget);

    expect(find.textContaining('ca lệch két', findRichText: true), findsOneWidget);
    expect(find.textContaining('hoá đơn điện tử', findRichText: true), findsOneWidget);
    expect(find.textContaining('chưa thu tiền', findRichText: true), findsOneWidget);

    final rev = api.calls.where((c) => c.path.endsWith('/reports/revenue')).toList();
    expect(rev.every((c) => c.queryParameters['shop_id'] == '2'), isTrue);
    expect(rev.any((c) => c.queryParameters['from'] == '2026-09-14'), isTrue, reason: 'kỳ trước cho đường xám');
    expect(t.takeException(), isNull);
  });

  testWidgets('đổi kỳ: Tháng rồi Hôm nay gọi lại API ngày 17/10; Khác → Tháng trước', (t) async {
    final (_, api) = await _open(t, thisMonth: true);
    api.calls.clear();

    await t.tap(find.text('Hôm nay'));
    await _settle(t);
    expect(
      api.calls.any((c) => c.path.endsWith('/reports/revenue') && c.queryParameters['from'] == '2026-10-17'),
      isTrue,
    );
    expect(find.text('17/10/2026'), findsOneWidget);
    expect(find.text('theo giờ'), findsOneWidget);

    await t.tap(find.byKey(const Key('period_more')));
    await _settle(t);
    expect(find.text('Chọn kỳ'), findsOneWidget);
    await t.tap(find.byKey(const Key('period_lastMonth')));
    await _settle(t);
    // Thanh chọn kỳ + chip của thanh thu gọn (đang ẩn) cùng ghi "Tháng trước".
    expect(find.text('Tháng trước'), findsWidgets);
    expect(find.text('01/09 – 30/09'), findsOneWidget);
  });

  testWidgets('lọc chi nhánh: chọn chi nhánh 5 thì báo cáo gửi shop_id=5', (t) async {
    final (_, api) = await _open(t);
    await t.tap(find.byKey(const Key('branch_filter')));
    await _settle(t);
    expect(find.text('Xem chi nhánh'), findsOneWidget);
    await t.tap(find.byKey(const Key('dash_branch_5')));
    await _settle(t);
    expect(find.text('Chi nhánh 2'), findsWidgets);
    expect(api.calls.any((c) => c.path.endsWith('/reports/orders') && c.queryParameters['shop_id'] == '5'), isTrue);

    await t.tap(find.byKey(const Key('branch_filter')));
    await _settle(t);
    await t.tap(find.byKey(const Key('dash_branch_0')));
    await _settle(t);
    expect(find.text('Tất cả chi nhánh'), findsWidgets);
    expect(api.calls.any((c) => c.path.endsWith('/reports/orders') && c.queryParameters['shop_id'] == '0'), isTrue);
  });

  testWidgets('Bảng số mở ngăn kéo có đủ 17 ngày', (t) async {
    await _open(t, thisMonth: true);
    await t.tap(find.text('Bảng số'));
    await _settle(t);
    expect(find.text('Doanh thu thuần · Tháng này'), findsOneWidget);
    expect(find.text('01/10'), findsWidgets);
    expect(find.text('17/10'), findsWidgets);
  });

  testWidgets('đặt mục tiêu: PUT /admin/settings rồi hiện thước tiến độ', (t) async {
    final (_, api) = await _open(t);
    await t.scrollUntilVisible(find.text('Đặt mục tiêu'), 200);
    await t.tap(find.text('Đặt mục tiêu'));
    await _settle(t);
    await t.enterText(find.byKey(const Key('goal_input')), '2000000000');
    await t.tap(find.text('Lưu mục tiêu'));
    await _settle(t);
    expect(api.settingsPut, {
      'items': {'monthly_revenue_goal': '2000000000'},
    });
    // 1,72 tỷ / 2 tỷ = 86% ≥ nhịp 17/31 ngày = 55%.
    expect(find.textContaining('Vượt nhịp'), findsOneWidget);
    expect(find.text('86%'), findsOneWidget);
  });

  testWidgets('thanh dưới: số đơn đang xử lý, tab khác hiện khung chờ + thanh thu gọn', (t) async {
    await _open(t);
    final bs = {
      for (final r in _snap['ranges']['this-month']['orders']['by_status'] as List)
        r['key']: (r['orders'] as num).toInt(),
    };
    final cho = ['pending', 'confirmed', 'processing', 'shipping'].fold<int>(0, (a, k) => a + (bs[k] ?? 0));
    if (cho > 0) expect(find.text('$cho'), findsWidgets);

    await t.tap(find.byKey(const Key('tab_pos')));
    await _settle(t, 3);
    expect(find.textContaining('chưa thiết kế', findRichText: true), findsOneWidget);
    expect(t.widget<CollapsedAppBar>(find.byType(CollapsedAppBar)).visible, isTrue);
    expect(t.widget<CollapsedAppBar>(find.byType(CollapsedAppBar)).title, 'Bán hàng');

    await t.tap(find.byKey(const Key('tab_home')));
    await _settle(t, 3);
    expect(t.widget<CollapsedAppBar>(find.byType(CollapsedAppBar)).visible, isFalse);
  });

  testWidgets('cuộn qua phần đầu thì hiện thanh thu gọn có kỳ + chuông', (t) async {
    await _open(t);
    await t.drag(find.byType(SingleChildScrollView).first, const Offset(0, -500));
    await _settle(t, 3);
    final bar = t.widget<CollapsedAppBar>(find.byType(CollapsedAppBar));
    expect(bar.visible, isTrue);
    expect(bar.periodLabel, 'Hôm nay');
    expect(bar.alertCount, greaterThan(0));
  });

  testWidgets('ngăn Thêm: lưới chức năng; chạm chức năng chưa có thì báo', (t) async {
    await _open(t);
    await t.tap(find.text('Thêm'));
    await _settle(t);
    expect(find.text('Tất cả chức năng'), findsOneWidget);
    expect(find.text('Nhà cung cấp'), findsOneWidget);
    await t.tap(find.text('Báo cáo'));
    await _settle(t);
    expect(find.text('Báo cáo — màn chưa thiết kế'), findsOneWidget);
  });

  testWidgets('ngăn tài khoản: sang khu Thu ngân; đăng xuất về màn đăng nhập', (t) async {
    final (store, _) = await _open(t);
    await t.tap(find.byKey(const Key('avatar')));
    await _settle(t);
    expect(find.text('Tài khoản'), findsOneWidget);
    expect(find.text('admin · Super Admin'), findsOneWidget);
    await t.tap(find.text('Chuyển sang khu Thu ngân'));
    await _settle(t);
    expect(find.byType(AdminShell), findsNothing);

    // Về lại Quản trị rồi đăng xuất.
    await t.tap(find.widgetWithText(TextButton, 'Thu ngân'));
    await _settle(t);
    await t.tap(find.byKey(const Key('area_quan_ly')));
    await t.pump(ChooseWorkspaceScreen.goingDelay);
    await _settle(t);
    await t.tap(find.byKey(const Key('avatar')));
    await _settle(t);
    await t.tap(find.byKey(const Key('account_logout')));
    await _settle(t);
    expect(find.text('Đăng xuất?'), findsOneWidget);
    await t.tap(find.byKey(const Key('logout_yes')));
    await _settle(t);
    expect(store.session, isNull);
    expect(find.text('Đăng nhập'), findsOneWidget);
  });

  testWidgets('ngăn Thêm có Đăng xuất: hỏi lại; Ở lại thì giữ phiên, Đăng xuất thì về màn đăng nhập', (t) async {
    final (store, _) = await _open(t);
    await t.tap(find.text('Thêm'));
    await _settle(t);
    await t.scrollUntilVisible(find.byKey(const Key('more_logout')), 200, scrollable: find.byType(Scrollable).last);
    await t.tap(find.byKey(const Key('more_logout')));
    await _settle(t);
    expect(find.text('Đăng xuất?'), findsOneWidget);
    await t.tap(find.text('Ở lại'));
    await _settle(t);
    expect(store.session, isNotNull);

    await t.tap(find.text('Thêm'));
    await _settle(t);
    await t.scrollUntilVisible(find.byKey(const Key('more_logout')), 200, scrollable: find.byType(Scrollable).last);
    await t.tap(find.byKey(const Key('more_logout')));
    await _settle(t);
    await t.tap(find.byKey(const Key('logout_yes')));
    await _settle(t);
    expect(store.session, isNull);
    expect(find.text('Đăng nhập'), findsOneWidget);
  });

  testWidgets('báo cáo doanh thu lỗi: báo lỗi + Thử lại', (t) async {
    final api = _Api()..revenueCode = 500;
    await _open(t, api: api);
    expect(find.text('Máy chủ lỗi'), findsOneWidget);
    api.revenueCode = 200;
    await t.tap(find.text('Thử lại'));
    await _settle(t);
    expect(_heroText(t), '0 đ');
    expect(find.text('▼ 100%'), findsWidgets);
  });

  testWidgets('máy hẹp 360×640: không tràn khung ở mọi thẻ', (t) async {
    await _open(t, size: const Size(360, 640));
    for (var i = 0; i < 8; i++) {
      await t.drag(find.byType(SingleChildScrollView).first, const Offset(0, -500));
      await _settle(t, 2);
      expect(t.takeException(), isNull);
    }
  });
}
