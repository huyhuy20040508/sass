import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:selliotech/features/admin/dashboard/dashboard_cards.dart';
import 'package:selliotech/features/admin/dashboard/dashboard_data.dart';

/// Thẻ "Cần chú ý" — dòng ca đang mở.
///
/// GET /admin/ca-lam-viec?status=dang_mo trả `opened_by_name` cho mọi ca, còn
/// `employee_name` (người trực két) chỉ có khi lúc mở ca chọn người khác người
/// mở — `employee_id` null thì API bỏ hẳn trường ấy. Đọc một mình
/// `employee_name` nên dòng cảnh báo mất tên: " đang mở ca".
///
/// Bộ số chụp sẵn (tong_quan_snapshot.json) có `open_shifts` RỖNG nên bài test
/// màn hình không bao giờ dựng tới dòng này — vì thế bài này tự dựng dữ liệu.
DashboardData _data(List<Map> caDangMo, {num nguong = 24}) => DashboardData(
  period: DashPeriod.today,
  now: DateTime(2026, 10, 19, 9, 32),
  from: '2026-10-19',
  to: '2026-10-19',
  revenue: const {},
  revenuePrev: const {},
  orders: const {},
  products: const {},
  purchases: Purchases.of(const []),
  purchasesPrev: Purchases.of(const []),
  openShifts: caDangMo,
  shiftDiffs: const [],
  shiftDiffTotal: 0,
  inventory: const {},
  einvoiceFailed: 0,
  settings: {'shift_open_alert_hours': '$nguong'},
  monthNet: 0,
  promotions: const {},
  branchNames: const {2: 'quochuy'},
);

void main() {
  test('ca không có người trực riêng vẫn hiện tên người mở ca', () {
    // Đúng dạng API trả cho ca #31: employee_id null, không có employee_name.
    final d = _data([
      {
        'id': 31,
        'shop_id': 2,
        'opened_at': '2026-10-19T08:32:00+07:00',
        'employee_id': null,
        'opened_by_name': 'Quốc Huy',
      },
    ]);

    expect(d.alerts, hasLength(1));
    expect(d.alerts.first.parts.join(), 'Quốc Huy đang mở ca');
    expect(d.alerts.first.sub, 'quochuy · 1 giờ');
    // Không được để chỗ trống: đó chính là lỗi đang chữa.
    expect(d.alerts.first.parts[1], isNotEmpty);
  });

  test('có người trực két thì lấy tên người trực, không lấy người mở', () {
    final d = _data([
      {
        'id': 32,
        'shop_id': 2,
        'opened_at': '2026-10-19T08:32:00+07:00',
        'employee_id': 7,
        'employee_name': 'Trần Thu Hà',
        'opened_by_name': 'Quốc Huy',
      },
    ]);

    expect(d.alerts.first.parts[1], 'Trần Thu Hà');
  });

  test('ca mở quá ngưỡng thì lên mức gấp và vẫn có tên', () {
    final d = _data([
      {'id': 30, 'shop_id': 2, 'opened_at': '2026-10-17T08:32:00+07:00', 'opened_by_name': 'Quốc Huy'},
    ]);

    final a = d.alerts.first;
    expect(a.level, AlertLevel.critical);
    expect(a.parts.join(), 'Ca của Quốc Huy mở 2 ngày chưa chốt');
    expect(a.sub, 'quochuy · ngưỡng 24 giờ');
  });

  /// Cả hai trường đều thiếu thì đành để trống, nhưng KHÔNG được vỡ.
  test('thiếu cả hai tên thì vẫn ra một dòng cảnh báo', () {
    final d = _data([
      {'id': 33, 'shop_id': 2, 'opened_at': '2026-10-19T08:32:00+07:00'},
    ]);

    expect(d.alerts, hasLength(1));
    expect(d.alerts.first.parts[1], isEmpty);
  });

  /// Dựng thẳng thẻ "Cần chú ý" — đo CHỮ NGƯỜI DÙNG ĐỌC, không chỉ dữ liệu.
  /// Máy hẹp 360px + tên dài: dòng phải gói lại, không tràn khung (flutter_test
  /// tự báo đỏ nếu RenderFlex tràn).
  testWidgets('thẻ Cần chú ý hiện tên người mở ca trên màn hình', (t) async {
    t.view.physicalSize = const Size(360, 640);
    t.view.devicePixelRatio = 1;
    addTearDown(t.view.reset);

    final d = _data([
      {
        'id': 31,
        'shop_id': 2,
        'opened_at': '2026-10-19T08:32:00+07:00',
        'employee_id': null,
        'opened_by_name': 'Nguyễn Quốc Huy Hoàng Long',
      },
    ]);

    await t.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: AlertsCard(alerts: d.alerts, onTap: (_) {}),
        ),
      ),
    );

    expect(find.textContaining('Nguyễn Quốc Huy Hoàng Long đang mở ca', findRichText: true), findsOneWidget);
    expect(find.textContaining('quochuy · 1 giờ'), findsOneWidget);
  });
}
