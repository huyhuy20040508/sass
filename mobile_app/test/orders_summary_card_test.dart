import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:selliotech/features/admin/orders/order_widgets.dart';
import 'package:selliotech/features/admin/orders/orders_data.dart';

/// Thẻ tổng của màn Đơn hàng — lỗi 84.
///
/// Con số ở đây là TỔNG TIỀN TRÊN ĐƠN: gồm VAT, gồm đơn chưa thu, cộng đơn đã
/// bị trả rồi trừ phiếu trả. Tổng quan thì gọi "Doanh thu thuần" cho một cách
/// tính khác hẳn (tiền hàng − giảm, không VAT, chỉ đơn hoàn thành). Cùng một
/// chữ "doanh thu" cho hai con số khác nhau thì người đọc tưởng phần mềm sai.
OrdersSummary _s({num revenue = 2016079000, int sales = 35, int returned = 3}) => OrdersSummary(
  revenue: revenue,
  sales: sales,
  debt: 24200000,
  debtOrders: 1,
  cancelled: 10,
  returned: returned,
  returnedRows: 7,
  days: [for (var i = 13; i >= 0; i--) DateTime(2026, 10, 20).subtract(Duration(days: i))],
  perDay: [for (var i = 0; i < 14; i++) i * 1000],
);

Future<void> _bay(WidgetTester t, OrdersSummary s) async {
  t.view.physicalSize = const Size(390, 844);
  t.view.devicePixelRatio = 1;
  addTearDown(t.view.reset);
  await t.pumpWidget(
    MaterialApp(
      home: Scaffold(
        body: SingleChildScrollView(
          child: OrdersSummaryCard(s: s, timeLabel: 'Tháng này', onKpi: (_) {}),
        ),
      ),
    ),
  );
}

void main() {
  testWidgets('nhãn là "Tổng tiền đơn", không phải "Doanh thu"', (t) async {
    await _bay(t, _s());

    expect(find.text('Tổng tiền đơn · tháng này'), findsOneWidget);
    expect(find.textContaining('Doanh thu'), findsNothing);
    // Nói thẳng chỗ khác với Tổng quan, ngay dưới con số.
    expect(find.text('gồm VAT và đơn chưa thu'), findsOneWidget);
  });

  testWidgets('đếm riêng đơn bán và phiếu trả', (t) async {
    await _bay(t, _s());

    final chu = t.widget<Text>(find.byKey(const Key('orders_revenue'))).textSpan!.toPlainText();
    expect(chu, '2.016.079.000 · 35 đơn bán · 3 phiếu trả');
  });

  /// Kỳ không có phiếu trả nào thì đừng bày "0 phiếu trả" cho dài dòng.
  testWidgets('không có phiếu trả thì chỉ ghi số đơn bán', (t) async {
    await _bay(t, _s(returned: 0));

    final chu = t.widget<Text>(find.byKey(const Key('orders_revenue'))).textSpan!.toPlainText();
    expect(chu, '2.016.079.000 · 35 đơn bán');
  });

  /// Khổ hẹp 390px: ba đoạn chữ dài hơn trước, không được tràn khung.
  testWidgets('số dài ở khổ hẹp vẫn không tràn', (t) async {
    await _bay(t, _s(revenue: 123456789012, sales: 1234, returned: 567));
    expect(t.takeException(), isNull);
  });
}
