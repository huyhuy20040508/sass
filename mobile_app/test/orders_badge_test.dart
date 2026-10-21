import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:selliotech/features/admin/dashboard/dashboard_cards.dart';
import 'package:selliotech/features/admin/dashboard/dashboard_data.dart';
import 'package:selliotech/features/admin/orders/orders_data.dart';
import 'package:selliotech/features/admin/shell/shell_widgets.dart';

/// MỘT CHỮ "đang xử lý", HAI NGHĨA — lỗi 82.
///
/// Số đỏ trên tab Đơn hàng và thẻ "Trạng thái đơn" gộp chờ xác nhận + đã xác
/// nhận + đang chuẩn bị + đang giao rồi gọi tất cả là "đang xử lý"; trong khi
/// màn Đơn hàng có tab "Đang xử lý" KHÔNG gồm đơn chờ xác nhận. Đơn DH000051
/// (pending) vì thế đếm ở Tổng quan là "đang xử lý 1" mà mở tab Đang xử lý thì
/// trống trơn.
DashboardData _data(Map<String, int> theoTrangThai) => DashboardData(
  period: DashPeriod.thisMonth,
  now: DateTime(2026, 10, 19, 14),
  from: '2026-10-01',
  to: '2026-10-19',
  revenue: const {},
  revenuePrev: const {},
  orders: {
    'by_status': [
      for (final e in theoTrangThai.entries) {'key': e.key, 'orders': e.value},
    ],
    'totals': {'total': theoTrangThai.values.fold<int>(0, (a, b) => a + b), 'dead_rate': 0, 'cancelled': 0, 'returned': 0},
  },
  products: const {},
  purchases: Purchases.of(const []),
  purchasesPrev: Purchases.of(const []),
  openShifts: const [],
  shiftDiffs: const [],
  shiftDiffTotal: 0,
  inventory: const {},
  einvoiceFailed: 0,
  settings: const {},
  monthNet: 0,
  promotions: const {},
  branchNames: const {},
);

void main() {
  group('thẻ Trạng thái đơn', () {
    test('chờ xác nhận là nhóm riêng, không nằm trong "Đang xử lý"', () {
      final nhom = {for (final (keys, ten, _) in StatusCard.groups) ten: keys};

      expect(nhom['Chờ xác nhận'], ['pending']);
      expect(nhom['Đang xử lý'], ['confirmed', 'processing', 'shipping']);
      expect(nhom['Đang xử lý'], isNot(contains('pending')));
    });

    /// Mỗi nhóm của thẻ phải rơi đúng vào MỘT tab của màn Đơn hàng — không thì
    /// đọc số ở đây rồi sang bên kia vẫn lệch.
    test('mỗi nhóm ứng đúng một tab của màn Đơn hàng', () {
      for (final (keys, ten, _) in StatusCard.groups) {
        final tabs = keys.map(OrderTab.ofStatus).toSet();
        expect(tabs, hasLength(1), reason: 'nhóm "$ten" trải trên nhiều tab: $tabs');
      }
    });

    testWidgets('một đơn chờ xác nhận hiện ở dòng Chờ xác nhận', (t) async {
      await t.pumpWidget(
        MaterialApp(home: Scaffold(body: SingleChildScrollView(child: StatusCard(data: _data({'pending': 1}))))),
      );

      expect(find.text('Chờ xác nhận'), findsOneWidget);
      expect(find.text('Đang xử lý'), findsOneWidget);
      // Dòng Chờ xác nhận mang số 1, Đang xử lý mang số 0.
      expect(find.text('1'), findsOneWidget);
    });
  });

  group('số đỏ trên tab Đơn hàng', () {
    testWidgets('đọc là "đơn chưa xong", không phải "đơn đang xử lý"', (t) async {
      await t.pumpWidget(
        MaterialApp(
          home: Scaffold(
            bottomNavigationBar: AdminTabBar(
              current: AdminTab.home,
              orderCount: 3,
              onTab: (_) {},
              onMore: () {},
            ),
          ),
        ),
      );

      final nhan = t
          .widgetList<Semantics>(find.byType(Semantics))
          .map((w) => w.properties.label)
          .whereType<String>()
          .toList();

      expect(nhan, contains('Đơn hàng, 3 đơn chưa xong'));
      expect(nhan.any((x) => x.contains('đơn đang xử lý')), isFalse);
    });

    test('đếm tách hai nhóm và cộng lại đúng', () {
      const u = UnfinishedOrders(pending: 1, doing: 2);
      expect(u.total, 3);
    });
  });

  group('bấm số đỏ mở đúng chỗ đang có đơn', () {
    const macDinh = OrdersFilter();

    test('chỉ có đơn chờ xác nhận → mở tab Chờ xác nhận, kỳ Tháng này', () {
      final f = const UnfinishedOrders(pending: 1).locKhiBam(macDinh)!;
      expect(f.tab, OrderTab.pending);
      expect(f.time, OrderTime.thisMonth);
    });

    test('chỉ có đơn đang xử lý → mở tab Đang xử lý', () {
      expect(const UnfinishedOrders(doing: 2).locKhiBam(macDinh)!.tab, OrderTab.doing);
    });

    test('có cả hai → mở Tất cả, vì không tab nào chứa đủ', () {
      expect(const UnfinishedOrders(pending: 1, doing: 1).locKhiBam(macDinh)!.tab, OrderTab.all);
    });

    test('không còn đơn nào thì không đụng vào bộ lọc', () {
      expect(const UnfinishedOrders().locKhiBam(macDinh), isNull);
    });

    test('người dùng đã tự chọn tab hay kỳ thì giữ nguyên lựa chọn của họ', () {
      const u = UnfinishedOrders(pending: 1);
      expect(u.locKhiBam(macDinh.copyWith(tab: OrderTab.done)), isNull);
      expect(u.locKhiBam(macDinh.copyWith(time: OrderTime.days7)), isNull);
      expect(u.locKhiBam(macDinh.copyWith(query: 'DH000051')), isNull);
      expect(u.locKhiBam(macDinh.copyWith(pays: {'unpaid'})), isNull);
    });
  });
}
