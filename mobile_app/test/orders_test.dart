import 'dart:convert';
import 'dart:io';
import 'dart:math' as math;
import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:selliotech/core/api/api_client.dart';
import 'package:selliotech/core/format/vn_number.dart';
import 'package:selliotech/core/net/online_provider.dart';
import 'package:selliotech/core/storage/session_store.dart';
import 'package:selliotech/features/admin/dashboard/dashboard_data.dart' show clockProvider;
import 'package:selliotech/features/admin/orders/order_detail_screen.dart';
import 'package:selliotech/features/admin/orders/order_widgets.dart';
import 'package:selliotech/features/admin/orders/orders_data.dart';
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

final Map<String, dynamic> _snap = jsonDecode(File('test/fixtures/don_hang_snapshot.json').readAsStringSync());
final _now = DateTime(2026, 10, 18, 9);
List<Map<String, dynamic>> get _rows => [for (final r in _snap['rows'] as List) Map<String, dynamic>.from(r as Map)];

String _group(String? s) => switch (s) {
  'pending' => 'pending',
  'confirmed' || 'processing' || 'shipping' => 'doing',
  'completed' || 'delivered' => 'done',
  _ => s ?? '',
};

/// API giả cho sổ đơn: lọc / sắp xếp / phân trang như /admin/orders/so-don.
class _Api implements HttpClientAdapter {
  _Api({this.procSupported = false});

  /// Giả lập API đã bổ sung cột trạng thái xử lý + meta.dem.
  final bool procSupported;
  final calls = <RequestOptions>[];
  final details = <String, Map<String, dynamic>>{
    for (final e in (_snap['details'] as Map).entries) '${e.key}': Map<String, dynamic>.from(e.value as Map),
  };

  List<Map> _items(Map r) => ((details['${r['id']}']?['items'] as List?) ?? const []).whereType<Map>().toList();

  String _proc(Map r) => r['loai'] != 'don' ? 'returned' : '${details['${r['id']}']?['status'] ?? 'pending'}';

  List<Map<String, dynamic>> _filter(Map<String, dynamic> q, {bool withProc = true}) {
    final st = q['status']?.toString().split(',');
    final ch = q['channel']?.toString().split(',');
    final pm = q['payment_method']?.toString().split(',');
    final kw = q['keyword']?.toString().toLowerCase();
    final cu = q['customer']?.toString().toLowerCase();
    final proc = withProc ? q['trang_thai_don']?.toString() : null;
    return [
      for (final r in _rows)
        if ((st == null || st.contains(r['trang_thai'])) &&
            (ch == null || ch.contains(r['kenh'])) &&
            (pm == null || pm.contains(r['phuong_thuc'])) &&
            (kw == null || '${r['ma']}'.toLowerCase().contains(kw)) &&
            (cu == null || '${r['khach_hang']} ${r['so_dien_thoai']}'.toLowerCase().contains(cu)) &&
            (q['from_date'] == null || '${r['created_at']}'.substring(0, 10).compareTo('${q['from_date']}') >= 0) &&
            (q['to_date'] == null || '${r['created_at']}'.substring(0, 10).compareTo('${q['to_date']}') <= 0) &&
            (!procSupported || proc == null || _group(_proc(r)) == proc))
          r,
    ];
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
    if (p.endsWith('/admin/orders/so-don')) {
      var rows = _filter(q);
      final sort = '${q['sort'] ?? 'updated'}';
      int by(Map a, Map b) => switch (sort) {
        'newest' => '${b['created_at']}'.compareTo('${a['created_at']}'),
        'oldest' => '${a['created_at']}'.compareTo('${b['created_at']}'),
        'total_desc' => (b['tong_tien'] as num).compareTo(a['tong_tien'] as num),
        'total_asc' => (a['tong_tien'] as num).compareTo(b['tong_tien'] as num),
        _ => '${b['updated_at']}'.compareTo('${a['updated_at']}'),
      };
      rows.sort(by);
      if (procSupported) {
        rows = [
          for (final r in rows)
            {
              ...r,
              'trang_thai_don': _proc(r),
              'so_mon': _items(r).length,
              'so_luong': _items(r).fold<num>(0, (a, x) => a + (x['quantity'] as num)),
              'ten_mon_dau': _items(r).isEmpty ? '' : '${_items(r).first['product_name']}',
              'anh_mon': const <String>[],
            },
        ];
      }
      final size = int.parse('${q['page_size'] ?? 20}'), page = int.parse('${q['page'] ?? 1}');
      final slice = rows.skip((page - 1) * size).take(size).toList();
      return _json(200, {
        'success': true,
        'data': slice,
        'meta': {
          'page': page,
          'page_size': size,
          'total': rows.length,
          'total_pages': math.max(1, (rows.length / size).ceil()),
          'tong': {
            'so_dong': rows.length,
            'tong_tien': rows.fold<num>(0, (a, r) => a + (r['tong_tien'] as num)),
            // Bản vá sổ đơn: tổng nợ + số theo tab nằm trong meta.tong.
            if (procSupported) 'con_no': rows.fold<num>(0, (a, r) => a + (r['con_no'] as num)),
            if (procSupported)
              'dem': {
                for (final g in ['pending', 'doing', 'done'])
                  g: _filter(
                    {...q}..remove('trang_thai_don'),
                    withProc: false,
                  ).where((r) => _group(_proc(r)) == g).length,
              },
          },
        },
      });
    }
    final m = RegExp(r'/admin/orders/(\d+)(/.*)?$').firstMatch(p);
    if (m != null) {
      final d = details[m.group(1)];
      if (d == null) return _json(404, {'success': false, 'message': 'Không thấy đơn'});
      if (m.group(2) == '/status') {
        d['status'] = (o.data as Map)['status'];
        if ((o.data as Map)['note'] != null) d['cancel_reason'] = (o.data as Map)['note'];
        return _ok(d);
      }
      if (m.group(2) == '/payments') {
        final amt = (o.data as Map)['amount'] as num;
        d['con_no'] = (d['con_no'] as num) - amt;
        d['da_thu'] = ((d['da_thu'] as num?) ?? 0) + amt;
        return _ok(d);
      }
      return _ok(d);
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

  List<RequestOptions> soDon() => calls.where((c) => c.path.endsWith('/so-don')).toList();

  /// Lượt tải DANH SÁCH (page_size của màn) — tách khỏi các lượt của thẻ tổng chạy song song.
  List<RequestOptions> list() => soDon().where((c) => c.queryParameters['page_size'] == '$ordersPageSize').toList();
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

Future<_Api> _open(WidgetTester t, {_Api? api, Size size = const Size(390, 844), OrderTime? time}) async {
  t.view.physicalSize = size;
  t.view.devicePixelRatio = 1;
  addTearDown(t.view.reset);
  final a = api ?? _Api();
  await t.pumpWidget(
    ProviderScope(
      overrides: [
        sessionStoreProvider.overrideWithValue(_MemoryStore(_session())),
        onlineProvider.overrideWith((ref) => Stream.value(true)),
        clockProvider.overrideWithValue(() => _now),
        apiClientProvider.overrideWith(
          (ref) => ApiClient(ref.read(authProvider.notifier), dio: Dio()..httpClientAdapter = a),
        ),
      ],
      child: const SelliotechApp(),
    ),
  );
  await _settle(t);
  // Một khu + một chi nhánh nhìn thấy → vào thẳng Quản trị.
  if (find.byKey(const Key('area_quan_ly')).evaluate().isNotEmpty) {
    await t.tap(find.byKey(const Key('area_quan_ly')));
    await t.pump(ChooseWorkspaceScreen.goingDelay);
    await _settle(t);
  }
  await t.tap(find.byKey(const Key('tab_orders')));
  await _settle(t);
  if (time != null) {
    await t.ensureVisible(find.byKey(Key('time_${time.name}')));
    await t.pump();
    await t.tap(find.byKey(Key('time_${time.name}')));
    await _settle(t);
  }
  return a;
}

Future<void> _settle(WidgetTester t, [int n = 10]) async {
  for (var i = 0; i < n; i++) {
    await t.pump(const Duration(milliseconds: 100));
  }
}

Finder _card(String kind, int id) => find.byKey(Key('order_${kind}_$id'));

void main() {
  test('bộ lọc dựng đúng tham số sổ: mã → keyword, tên → customer, giao trạng thái với thanh toán', () {
    final f = const OrdersFilter(time: OrderTime.thisMonth, shopId: 2, query: 'DH000073');
    final q = f.query$(_now);
    expect(q['keyword'], 'DH000073');
    expect(q['from_date'], '2026-10-01');
    expect(q['to_date'], '2026-10-18');
    expect(q['shop_id'], '2');
    expect(const OrdersFilter(query: 'Nguyễn').query$(_now)['customer'], 'Nguyễn');
    expect(const OrdersFilter(methods: {'auto_qr'}).query$(_now)['payment_method'], 'sepay,payos,mpos_qr');
    expect(const OrdersFilter(tab: OrderTab.cancelled, pays: {'paid'}).query$(_now)['status'], 'none');
    expect(const OrdersFilter(tab: OrderTab.pending).query$(_now)['trang_thai_don'], 'pending');
    expect(OrderTime.all.range(_now), isNull);
  });

  testWidgets(
    'mặc định: hôm nay + chi nhánh chính; hôm nay chưa có đơn → màn trống; API chưa có trạng thái xử lý → 3 tab',
    (t) async {
      final api = await _open(t);
      expect(find.text('Hôm nay chưa bán được đơn nào.'), findsOneWidget);
      expect(api.soDon().every((c) => c.queryParameters['shop_id'] == '2'), isTrue);
      expect(api.soDon().any((c) => c.queryParameters['from_date'] == '2026-10-18'), isTrue);
      expect(find.byKey(const Key('tab_pending')), findsNothing);
      expect(find.byKey(const Key('tab_cancelled')), findsOneWidget);
      expect(find.byKey(const Key('tab_returned')), findsOneWidget);
      expect(t.takeException(), isNull);
    },
  );

  testWidgets('tháng này: thẻ tổng đúng công thức, nhóm theo ngày, thẻ đơn đọc món từ chi tiết', (t) async {
    await _open(t, time: OrderTime.thisMonth);
    final m = _rows.where((r) => '${r['created_at']}'.compareTo('2026-10-01') >= 0).toList();
    final sales = m.where((r) => r['loai'] == 'don' && r['trang_thai'] != 'cancelled');
    final ret = m.where((r) => r['loai'] != 'don');
    final revenue =
        sales.fold<num>(0, (a, r) => a + (r['tong_tien'] as num)) -
        ret.fold<num>(0, (a, r) => a + (r['tong_tien'] as num));
    final text = t.widget<Text>(find.byKey(const Key('orders_revenue'))).textSpan!.toPlainText();
    expect(text, '${dot(revenue)} · ${sales.length} đơn');

    expect(find.text(dayLabel(DateTime(2026, 10, 16), _now)), findsOneWidget);
    expect(find.text('DH000073'), findsOneWidget);
    // Món + trạng thái đọc từ GET /admin/orders/{id}.
    final d = _snap['details']['900067'] as Map;
    expect(find.text('${(d['items'] as List).first['product_name']}'), findsWidgets);
    expect(find.descendant(of: _card('don', 900067), matching: find.text('Hoàn tất')), findsOneWidget);
    await t.scrollUntilVisible(_card('tra', 5), 300, scrollable: find.byType(Scrollable).last);
    expect(find.descendant(of: _card('tra', 5), matching: find.text('Trả hàng')), findsOneWidget);
    expect(find.text('−22.000.000đ'), findsWidgets);
    await _settle(t, 3);
  });

  testWidgets('tab Đã huỷ gọi sổ với status=cancelled; chạm "Chưa thu" lọc đơn nợ, bỏ chip thì hết lọc', (t) async {
    final api = await _open(t, time: OrderTime.all);
    await t.tap(find.byKey(const Key('tab_cancelled')));
    await _settle(t);
    expect(api.list().last.queryParameters['status'], 'cancelled');
    final huy = _rows.where((r) => r['trang_thai'] == 'cancelled').length;
    expect(find.text('$huy đơn'), findsWidgets);

    await t.tap(find.byKey(const Key('tab_all')));
    await _settle(t);
    await t.tap(find.byKey(const Key('kpi_unpaid')));
    await _settle(t);
    expect(find.text('DH000051'), findsOneWidget);
    expect(find.text('Còn nợ 24.200.000 đ'), findsOneWidget);
    expect(find.text('Chưa thanh toán'), findsWidgets);
    await t.tap(find.text('Thanh toán một phần'));
    await t.tap(find.text('Chưa thanh toán').first);
    await _settle(t);
    expect(find.byKey(const Key('filter_btn')), findsOneWidget);
  });

  testWidgets('tìm: gõ mã đơn gửi keyword, gõ tên gửi customer', (t) async {
    final api = await _open(t, time: OrderTime.all);
    await t.enterText(find.byKey(const Key('orders_search')), 'DH000073');
    await _settle(t, 5);
    expect(api.list().last.queryParameters['keyword'], 'DH000073');
    expect(find.byType(OrderCard), findsOneWidget);
    expect(find.descendant(of: find.byType(OrderCard), matching: find.text('DH000073')), findsOneWidget);
    await t.enterText(find.byKey(const Key('orders_search')), 'Người');
    await _settle(t, 5);
    expect(api.list().last.queryParameters['customer'], 'Người');
  });

  testWidgets('ngăn Lọc: đếm trước "Xem N đơn", áp dụng gửi channel=pos', (t) async {
    final api = await _open(t, time: OrderTime.all);
    await t.tap(find.byKey(const Key('filter_btn')));
    await _settle(t);
    expect(find.text('Lọc đơn hàng'), findsOneWidget);
    await t.tap(find.byKey(const Key('f_ch_pos')));
    await _settle(t, 5);
    expect(find.text('Xem ${_rows.length} đơn'), findsOneWidget);
    await t.tap(find.byKey(const Key('apply_filter')));
    await _settle(t);
    expect(api.list().last.queryParameters['channel'], 'pos');
    expect(find.text('Bán tại quầy'), findsWidgets);
  });

  testWidgets('sắp xếp theo giá trị: gửi total_desc, bỏ nhóm theo ngày', (t) async {
    final api = await _open(t, time: OrderTime.all);
    await t.tap(find.byKey(const Key('sort_btn')));
    await _settle(t);
    await t.tap(find.byKey(const Key('sort_totalDesc')));
    await _settle(t);
    expect(api.list().last.queryParameters['sort'], 'total_desc');
    expect(find.byType(DayHeader), findsNothing);
  });

  testWidgets('cuộn tới cuối tải trang 2; hết thì ghi "Đã hiện hết"', (t) async {
    final api = await _open(t, time: OrderTime.all);
    for (var i = 0; i < 30 && find.text('Đã hiện hết ${_rows.length} đơn').evaluate().isEmpty; i++) {
      await t.drag(find.byType(ListView).last, const Offset(0, -1500));
      await _settle(t, 3);
    }
    expect(api.soDon().any((c) => c.queryParameters['page'] == '2'), isTrue);
    expect(find.text('Đã hiện hết ${_rows.length} đơn'), findsOneWidget);
  });

  testWidgets('chi tiết đơn nợ: thu tiền gửi POST payments; không thu quá số nợ', (t) async {
    final api = await _open(t, time: OrderTime.all);
    await t.scrollUntilVisible(_card('don', 900045), 400, scrollable: find.byType(Scrollable).last);
    await t.tap(_card('don', 900045));
    await _settle(t);
    expect(find.byType(OrderDetailScreen), findsOneWidget);
    expect(find.text('Còn phải thu'), findsOneWidget);
    expect(find.text('Chờ xác nhận'), findsWidgets);

    await t.tap(find.byKey(const Key('act_pay')));
    await _settle(t);
    await t.enterText(find.byKey(const Key('pay_amount')), '99999999');
    await t.tap(find.byKey(const Key('pay_ok')));
    await t.pump();
    expect(find.text('Không thu quá 24.200.000 đ còn nợ'), findsOneWidget);
    await t.enterText(find.byKey(const Key('pay_amount')), '200000');
    await t.tap(find.text('Chuyển khoản'));
    await t.tap(find.byKey(const Key('pay_ok')));
    await _settle(t);
    final post = api.calls.lastWhere((c) => c.path.endsWith('/payments'));
    expect(post.data, {'amount': 200000, 'payment_method': 'bank_transfer'});
    expect(find.text('24.000.000'), findsOneWidget, reason: 'còn phải thu sau khi ghi');
  });

  testWidgets('chi tiết: chuyển trạng thái kế tiếp và huỷ đơn có lý do', (t) async {
    final api = _Api();
    api.details['900045']!
      ..['next_statuses'] = ['confirmed', 'cancelled']
      ..['can_cancel'] = true;
    await _open(t, api: api, time: OrderTime.all);
    await t.scrollUntilVisible(_card('don', 900045), 400, scrollable: find.byType(Scrollable).last);
    await t.tap(_card('don', 900045));
    await _settle(t);

    await t.tap(find.byKey(const Key('act_st')));
    await _settle(t);
    expect(api.calls.lastWhere((c) => c.path.endsWith('/status')).data, {'status': 'confirmed'});

    await t.tap(find.byKey(const Key('act_more')));
    await _settle(t);
    await t.tap(find.byKey(const Key('act_cancel')));
    await _settle(t);
    await t.enterText(find.byKey(const Key('cancel_reason')), 'Khách đổi ý');
    await t.tap(find.byKey(const Key('cancel_ok')));
    await _settle(t);
    expect(api.calls.lastWhere((c) => c.path.endsWith('/status')).data, {'status': 'cancelled', 'note': 'Khách đổi ý'});
    expect(find.text('Đã huỷ'), findsWidgets);
    expect(find.text('Khách đổi ý'), findsOneWidget);
  });

  testWidgets('phiếu trả: chi tiết gọn, không gọi API đơn', (t) async {
    final api = await _open(t, time: OrderTime.all);
    await t.scrollUntilVisible(_card('tra', 5), 300, scrollable: find.byType(Scrollable).last);
    await t.tap(_card('tra', 5));
    await _settle(t);
    expect(find.text('Phiếu trả hàng'), findsOneWidget);
    expect(find.text('22.000.000 đ'), findsOneWidget);
    expect(api.calls.any((c) => c.path.endsWith('/admin/orders/5')), isFalse);
  });

  testWidgets('API đã có trạng thái xử lý: hiện đủ 6 tab có số đếm; tab Chờ xác nhận gửi trang_thai_don', (t) async {
    final api = await _open(t, api: _Api(procSupported: true), time: OrderTime.all);
    expect(find.byKey(const Key('tab_pending')), findsOneWidget);
    expect(find.byKey(const Key('tab_done')), findsOneWidget);
    await t.tap(find.byKey(const Key('tab_pending')));
    await _settle(t);
    expect(api.list().last.queryParameters['trang_thai_don'], 'pending');
    // Dòng sổ đã có món + trạng thái → danh sách không gọi chi tiết từng đơn.
    expect(api.calls.where((c) => RegExp(r'/admin/orders/\d+$').hasMatch(c.path)), isEmpty);
    expect(find.text('Chờ xác nhận'), findsWidgets);
    expect(find.descendant(of: find.byType(OrderCard), matching: find.text('DH000051')), findsOneWidget);
    expect(find.byType(OrderCard), findsOneWidget);
  });

  testWidgets('máy hẹp 360×640: danh sách và chi tiết không tràn', (t) async {
    await _open(t, time: OrderTime.all, size: const Size(360, 640));
    for (var i = 0; i < 4; i++) {
      await t.drag(find.byType(ListView).last, const Offset(0, -600));
      await _settle(t, 3);
      expect(t.takeException(), isNull);
    }
    await t.tap(find.byType(OrderCard).first);
    await _settle(t);
    await t.drag(find.byType(ListView).last, const Offset(0, -800));
    await _settle(t, 3);
    expect(t.takeException(), isNull);
  });
}
