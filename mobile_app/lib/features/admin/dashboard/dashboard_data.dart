import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/api/api_client.dart';
import '../../../core/api/api_exception.dart';
import '../../../core/format/vn_number.dart';
import '../../auth/auth_controller.dart';
import '../../workspace/workspace.dart';
import '../orders/orders_data.dart';

/// Kỳ xem — cùng mã với Period của web_Shop.
enum DashPeriod {
  today('Hôm nay'),
  yesterday('Hôm qua'),
  days7('7 ngày'),
  thisMonth('Tháng này'),
  lastMonth('Tháng trước');

  const DashPeriod(this.label);

  final String label;

  (DateTime, DateTime) range(DateTime now) {
    final d = DateTime(now.year, now.month, now.day);
    return switch (this) {
      DashPeriod.today => (d, d),
      DashPeriod.yesterday => (d.subtract(const Duration(days: 1)), d.subtract(const Duration(days: 1))),
      DashPeriod.days7 => (d.subtract(const Duration(days: 6)), d),
      DashPeriod.thisMonth => (DateTime(d.year, d.month), d),
      DashPeriod.lastMonth => (DateTime(d.year, d.month - 1), DateTime(d.year, d.month, 0)),
    };
  }
}

String ymd(DateTime d) =>
    '${d.year.toString().padLeft(4, '0')}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';

/// Kỳ để so — cùng luật với API (ReportPeriod.Prev) và PC (khoangTruoc): kỳ mùng 1
/// gọn trong một tháng, dài hơn 7 ngày thì so cùng kỳ tháng trước (kẹp cuối tháng,
/// trọn tháng so trọn tháng); còn lại so cùng độ dài, ngay sát trước.
(DateTime, DateTime) prevRange(DateTime from, DateTime to) {
  final n = to.difference(from).inDays + 1;
  if (from.day == 1 && n > 7 && from.year == to.year && from.month == to.month) {
    final tu = DateTime(from.year, from.month - 1);
    final cuoiThangTruoc = DateTime(from.year, from.month, 0);
    final tronThang = DateTime(to.year, to.month, to.day + 1).day == 1;
    final den = DateTime(tu.year, tu.month, n);
    return (tu, tronThang || den.isAfter(cuoiThangTruoc) ? cuoiThangTruoc : den);
  }
  return (from.subtract(Duration(days: n)), from.subtract(const Duration(days: 1)));
}

/// Tiền của một kỳ/mốc theo công thức Tổng quan PC: thuần = tiền hàng + phí giao − giảm giá.
class Money {
  const Money({this.gross = 0, this.net = 0, this.profit = 0, this.orders = 0, this.discount = 0});

  factory Money.of(Map? t) {
    num v(String k) => (t?[k] as num?) ?? 0;
    final gross = v('subtotal') + v('shipping');
    return Money(
      gross: gross,
      net: gross - v('discount'),
      profit: v('profit'),
      orders: v('orders'),
      discount: v('discount'),
    );
  }

  final num gross;
  final num net;
  final num profit;
  final num orders;
  final num discount;
}

/// So với kỳ trước: null = cả hai bằng 0; isNew = kỳ trước bằng 0.
class Delta {
  const Delta.newer() : isNew = true, pct = 0;
  const Delta.change(this.pct) : isNew = false;

  final bool isNew;
  final double pct;

  static Delta? of(num now, num before) {
    if (before == 0) return now == 0 ? null : const Delta.newer();
    return Delta.change((now - before) / before.abs() * 100);
  }
}

/// Tổng phiếu mua hàng trong kỳ, bỏ phiếu huỷ / nháp — DashboardController::muaHang.
class Purchases {
  const Purchases({this.total = 0, this.count = 0, this.byDay = const {}});

  factory Purchases.of(List<dynamic> rows) {
    num total = 0;
    var count = 0;
    final byDay = <String, num>{};
    for (final p in rows.whereType<Map>()) {
      if (p['status'] == 'cancelled' || p['status'] == 'draft') continue;
      final t = (p['total_amount'] as num?) ?? 0;
      final day = '${p['document_date'] ?? p['created_at'] ?? ''}'.padRight(10).substring(0, 10);
      total += t;
      count++;
      byDay[day] = (byDay[day] ?? 0) + t;
    }
    return Purchases(total: total, count: count, byDay: byDay);
  }

  final num total;
  final int count;
  final Map<String, num> byDay;
}

enum AlertLevel { critical, warning, info }

enum AlertKind { shift, stock, einvoice, unpaid, cashDiff }

class DashAlert {
  const DashAlert(this.level, this.kind, this.parts, [this.sub = '']);

  final AlertLevel level;
  final AlertKind kind;

  /// Các đoạn chữ; đoạn lẻ (1, 3…) in đậm — giữ được nhấn mạnh như bản HTML mà không cần HTML.
  final List<String> parts;
  final String sub;
}

/// Mọi số của một lần xem Tổng quan.
class DashboardData {
  DashboardData({
    required this.period,
    required this.now,
    required this.from,
    required this.to,
    required this.revenue,
    required this.revenuePrev,
    required this.orders,
    required this.products,
    required this.purchases,
    required this.purchasesPrev,
    required this.openShifts,
    required this.shiftDiffs,
    required this.shiftDiffTotal,
    required this.inventory,
    required this.einvoiceFailed,
    required this.settings,
    required this.monthNet,
    required this.promotions,
    required this.branchNames,
  });

  final DashPeriod period;
  final DateTime now;
  final String from;
  final String to;
  final Map<String, dynamic> revenue;
  final Map<String, dynamic> revenuePrev;
  final Map<String, dynamic> orders;
  final Map<String, dynamic> products;
  final Purchases purchases;
  final Purchases purchasesPrev;
  final List<Map> openShifts;
  final List<Map> shiftDiffs;
  final int shiftDiffTotal;
  final Map<String, dynamic> inventory;
  final int einvoiceFailed;
  final Map<String, dynamic> settings;
  final num monthNet;
  final Map<String, List<Map>> promotions;
  final Map<int, String> branchNames;

  bool get oneDay => from == to;
  Money get now$ => Money.of(revenue['totals'] as Map?);
  Money get prev$ => Money.of(revenue['prev'] as Map?);
  List<Map> get buckets => ((revenue['buckets'] as List?) ?? const []).whereType<Map>().toList();
  List<Map> get prevBuckets => ((revenuePrev['buckets'] as List?) ?? const []).whereType<Map>().toList();
  String get prevFrom => '${revenue['prev_from'] ?? ''}';
  String get prevTo => '${revenue['prev_to'] ?? ''}';
  Map get orderTotals => (orders['totals'] as Map?) ?? const {};

  num setting(String k, num fallback) => num.tryParse('${settings[k] ?? ''}') ?? fallback;

  /// Một mảng theo giờ 7h–22h từ orders.by_hour.
  List<num> hourly(String field) => _hourly((r) => (r[field] as num?) ?? 0);

  /// Doanh thu thuần theo giờ — cùng công thức Money với kỳ nhiều ngày; field
  /// 'revenue' của by_hour GỒM VAT nên không dùng cho thẻ "Doanh thu thuần".
  List<num> hourlyNet() => _hourly((r) => Money.of(r).net);

  List<num> _hourly(num Function(Map) value) {
    final m = <int, num>{};
    for (final r in ((orders['by_hour'] as List?) ?? const []).whereType<Map>()) {
      m[int.tryParse('${r['key']}') ?? -1] = value(r);
    }
    return [for (var h = 7; h <= 22; h++) m[h] ?? 0];
  }

  Map<String, int> get byStatus => {
    for (final r in ((orders['by_status'] as List?) ?? const []).whereType<Map>())
      '${r['key']}': ((r['orders'] as num?) ?? 0).toInt(),
  };

  /// Việc cần chú ý — đúng thứ tự và ngưỡng của thẻ "Cần chú ý" bên PC.
  List<DashAlert> get alerts {
    final out = <DashAlert>[];
    final gioNguong = setting('shift_open_alert_hours', 24);
    for (final c in openShifts) {
      final mo = DateTime.tryParse('${c['opened_at'] ?? ''}');
      if (mo == null) continue;
      final gio = now.difference(mo).inMinutes / 60;
      // employee_name là NGƯỜI TRỰC KÉT, chỉ có khi mở ca chọn người khác
      // người mở (employee_id null thì API không trả trường này). Thiếu nhánh
      // dự phòng là dòng cảnh báo mất hẳn tên: " đang mở ca". Cùng cách lấy
      // như bên PC — DashboardController::caDangMo().
      final ten = '${c['employee_name'] ?? ''}'.trim().isNotEmpty
          ? '${c['employee_name']}'
          : '${c['opened_by_name'] ?? ''}';
      final cn = branchNames[(c['shop_id'] as num?)?.toInt()] ?? '';
      if (gioNguong > 0 && gio >= gioNguong) {
        final bao = gio >= 48 ? '${gio ~/ 24} ngày' : '${gio.floor()} giờ';
        out.add(
          DashAlert(AlertLevel.critical, AlertKind.shift, [
            'Ca của ',
            ten,
            ' mở $bao chưa chốt',
          ], '$cn · ngưỡng ${gioNguong.toInt()} giờ'),
        );
      } else {
        out.add(DashAlert(AlertLevel.info, AlertKind.shift, ['', ten, ' đang mở ca'], '$cn · ${gio.floor()} giờ'));
      }
    }
    final het = ((inventory['out_of_stock'] as num?) ?? 0).toInt();
    final sapHet = ((inventory['low_stock'] as num?) ?? 0).toInt();
    if (het > 0) out.add(DashAlert(AlertLevel.critical, AlertKind.stock, ['', '$het mặt hàng', ' đã hết']));
    if (sapHet > 0) {
      out.add(
        DashAlert(AlertLevel.warning, AlertKind.stock, [
          '',
          '$sapHet mặt hàng',
          ' sắp hết',
        ], 'Dưới ${setting('low_stock_threshold', 5).toInt()}'),
      );
    }
    if (einvoiceFailed > 0) {
      out.add(
        DashAlert(AlertLevel.critical, AlertKind.einvoice, [
          '',
          '$einvoiceFailed hoá đơn điện tử',
          ' phát hành lỗi',
        ], 'Chờ phát hành lại'),
      );
    }
    final chuaThu = ((orderTotals['unpaid_orders'] as num?) ?? 0).toInt();
    if (chuaThu > 0) {
      out.add(
        DashAlert(AlertLevel.warning, AlertKind.unpaid, [
          '',
          '$chuaThu đơn',
          ' chưa thu tiền',
        ], dot((orderTotals['unpaid_amount'] as num?) ?? 0)),
      );
    }
    if (shiftDiffTotal > 0) {
      num thieu = 0, du = 0;
      var vuot = 0;
      final nguong = setting('shift_diff_alert_threshold', 50000);
      for (final c in shiftDiffs) {
        final d = (c['difference'] as num?) ?? 0;
        if (d < 0) {
          thieu += d;
        } else {
          du += d;
        }
        if (d.abs() > nguong) vuot++;
      }
      out.add(
        DashAlert(vuot > 0 ? AlertLevel.warning : AlertLevel.info, AlertKind.cashDiff, [
          '',
          '$shiftDiffTotal ca lệch két',
          ' trong 7 ngày',
        ], 'Thiếu ${dot(-thieu)} · dư ${dot(du)}${vuot > 0 ? ' · $vuot ca vượt ${dot(nguong)}' : ''}'),
      );
    }
    return out;
  }
}

/// Bộ lọc của màn Tổng quan.
///
/// [shopId]: 0 = tất cả chi nhánh; null ở lựa chọn của người dùng = "mặc định"
/// (xem [dashQueryProvider]); null ở bộ lọc đã giải = không gửi shop_id, API lấy
/// chi nhánh đang làm.
class DashFilter {
  const DashFilter({this.period = DashPeriod.today, this.shopId});

  final DashPeriod period;
  final int? shopId;

  DashFilter copyWith({DashPeriod? period, int? shopId}) =>
      DashFilter(period: period ?? this.period, shopId: shopId ?? this.shopId);

  @override
  bool operator ==(Object other) => other is DashFilter && other.period == period && other.shopId == shopId;

  @override
  int get hashCode => Object.hash(period, shopId);
}

/// Lựa chọn của người dùng trên màn Tổng quan. Vào màn là xem HÔM NAY.
final dashFilterProvider = NotifierProvider<DashFilterNotifier, DashFilter>(DashFilterNotifier.new);

class DashFilterNotifier extends Notifier<DashFilter> {
  @override
  DashFilter build() => const DashFilter();

  void setPeriod(DashPeriod p) => state = state.copyWith(period: p);

  void setShop(int id) => state = state.copyWith(shopId: id);
}

/// Chi nhánh mặc định của các màn khu Quản trị: chi nhánh ĐANG LÀM (chọn ở màn
/// chọn nơi làm việc), chưa có thì chi nhánh chính (trụ sở) — không mặc định gộp
/// mọi chi nhánh. Giá trị null = không xem được danh sách chi nhánh, để API tự lấy
/// chi nhánh đang làm. AsyncLoading = còn đang tải danh sách chi nhánh.
final defaultShopProvider = Provider.autoDispose<AsyncValue<int?>>((ref) {
  final dangLam = ref.watch(authProvider.select((s) => s.value?.chiNhanhId));
  if (dangLam != null) return AsyncData(dangLam);
  final br = ref.watch(dashBranchesProvider);
  if (!br.hasValue) return const AsyncLoading();
  return AsyncData(br.value!.where((b) => b.isHq).firstOrNull?.id);
});

/// Bộ lọc thật gửi lên API của Tổng quan. null = đang tải danh sách chi nhánh.
final dashQueryProvider = Provider.autoDispose<DashFilter?>((ref) {
  final c = ref.watch(dashFilterProvider);
  if (c.shopId != null) return c;
  final d = ref.watch(defaultShopProvider);
  if (!d.hasValue) return null;
  final id = d.value;
  return id == null ? c : c.copyWith(shopId: id);
});

/// Đồng hồ của màn Tổng quan — test thay bằng giờ cố định.
final clockProvider = Provider<DateTime Function()>((_) => DateTime.now);

/// Tên chi nhánh đang xem cho đầu màn và thanh thu gọn.
String branchLabel(int? shopId, List<Branch> branches) => switch (shopId) {
  null => 'Chi nhánh đang làm',
  0 => 'Tất cả chi nhánh',
  final id => branches.where((b) => b.id == id).firstOrNull?.name ?? 'Chi nhánh #$id',
};

/// Chi nhánh để lọc Tổng quan (trụ sở đứng đầu). Không có quyền xem danh sách thì rỗng.
final dashBranchesProvider = FutureProvider.autoDispose<List<Branch>>((ref) async {
  try {
    final raw = await ref.read(apiClientProvider).get<List<dynamic>>('/admin/chi-nhanh', query: {'active': 'true'});
    return parseBranches(raw);
  } on ApiException {
    return const [];
  }
}, retry: _noRetry);

Duration? _noRetry(int _, Object _) => null;

const _promoTabs = ['hoa-don', 'hang-hoa', 'nhom-hang', 'dong-gia', 'voucher'];
const _maxPurchasePages = 5;

final dashboardProvider = FutureProvider.autoDispose.family<DashboardData, DashFilter>(
  (ref, f) => loadDashboard(ref.read(apiClientProvider), f, ref.read(clockProvider)(), ref),
  retry: _noRetry,
);

/// Gọi đủ các nguồn như DashboardController::index (song song). Chỉ báo cáo
/// doanh thu là bắt buộc; các nguồn phụ hỏng (thiếu quyền, lỗi lẻ) thì thẻ của
/// nó hiện rỗng, như bản PC.
Future<DashboardData> loadDashboard(ApiClient api, DashFilter f, DateTime now, Ref ref) async {
  final (a, b) = f.period.range(now);
  final (pa, pb) = prevRange(a, b);
  final from = ymd(a), to = ymd(b), pFrom = ymd(pa), pTo = ymd(pb);
  final shop = {if (f.shopId != null) 'shop_id': '${f.shopId}'};
  final ky = {'from': from, 'to': to, ...shop};

  Future<T> soft<T>(Future<T> Function() call, T fallback) async {
    try {
      return await call();
    } on ApiException {
      return fallback;
    }
  }

  Future<Map<String, dynamic>> map(String path, Map<String, String> q) =>
      soft(() => api.get<Map<String, dynamic>>(path, query: q), <String, dynamic>{});

  Future<Purchases> purchases(String f1, String t1) => soft(() async {
    final rows = <dynamic>[];
    final q = {'from_date': f1, 'to_date': t1, 'page_size': '100', ...shop};
    final first = await api.getPage('/admin/phieu-mua-hang', query: {...q, 'page': '1'});
    rows.addAll(first.data);
    final pages = first.totalPages.clamp(1, _maxPurchasePages);
    final rest = await Future.wait([
      for (var p = 2; p <= pages; p++) api.getPage('/admin/phieu-mua-hang', query: {...q, 'page': '$p'}),
    ]);
    for (final r in rest) {
      rows.addAll(r.data);
    }
    return Purchases.of(rows);
  }, const Purchases());

  Future<(List<Map>, int)> list(String path, Map<String, String> q) => soft(() async {
    final p = await api.getPage(path, query: q);
    return (p.data.whereType<Map>().toList(), p.total);
  }, (const <Map>[], 0));

  final settingsF = map('/admin/settings', const {});
  final lowStock = settingsF.then((s) => '${(s['values'] as Map?)?['low_stock_threshold'] ?? 5}');
  final caLechFrom = ymd(DateTime(now.year, now.month, now.day).subtract(const Duration(days: 6)));
  final tronThang = f.period == DashPeriod.thisMonth;

  final revenueF = api.get<Map<String, dynamic>>('/admin/reports/revenue', query: {...ky, 'group_by': 'day'});
  final results = await Future.wait<Object?>([
    revenueF,
    map('/admin/reports/revenue', {'from': pFrom, 'to': pTo, 'group_by': 'day', ...shop}),
    map('/admin/reports/orders', ky),
    map('/admin/reports/products', {...ky, 'sort': 'units'}),
    purchases(from, to),
    purchases(pFrom, pTo),
    list('/admin/ca-lam-viec', {'status': 'dang_mo', 'page_size': '100', ...shop}),
    list('/admin/ca-lam-viec', {
      'status': 'da_dong',
      'lech': '1',
      'page_size': '100',
      'from_date': caLechFrom,
      'to_date': ymd(now),
      ...shop,
    }),
    lowStock.then((n) => map('/admin/inventory/stats', {'low_stock': n})),
    list('/admin/etax/hoa-don', {'status': 'failed', 'page_size': '1'}),
    settingsF,
    tronThang
        ? Future.value(null)
        : map('/admin/reports/revenue', {
            'from': ymd(DateTime(now.year, now.month)),
            'to': ymd(now),
            'group_by': 'month',
            ...shop,
          }),
    Future.wait([
      for (final t in _promoTabs) map('/admin/reports/promotions', {...ky, 'tab': t}),
    ]),
    ref.read(dashBranchesProvider.future),
  ]);

  final revenue = results[0] as Map<String, dynamic>;
  final shifts = results[6] as (List<Map>, int);
  final diffs = results[7] as (List<Map>, int);
  final settings = results[10] as Map<String, dynamic>;
  final month = results[11] as Map<String, dynamic>?;
  final promos = results[12] as List<Map<String, dynamic>>;

  return DashboardData(
    period: f.period,
    now: now,
    from: from,
    to: to,
    revenue: revenue,
    revenuePrev: results[1] as Map<String, dynamic>,
    orders: results[2] as Map<String, dynamic>,
    products: results[3] as Map<String, dynamic>,
    purchases: results[4] as Purchases,
    purchasesPrev: results[5] as Purchases,
    openShifts: shifts.$1,
    shiftDiffs: diffs.$1,
    shiftDiffTotal: diffs.$2,
    inventory: results[8] as Map<String, dynamic>,
    einvoiceFailed: (results[9] as (List<Map>, int)).$2,
    settings: Map<String, dynamic>.from((settings['values'] as Map?) ?? const {}),
    monthNet: Money.of((month ?? revenue)['totals'] as Map?).net,
    promotions: {
      for (var i = 0; i < _promoTabs.length; i++)
        _promoTabs[i]: ((promos[i]['rows'] as List?) ?? const []).whereType<Map>().toList(),
    },
    branchNames: {for (final b in results[13] as List<Branch>) b.id: b.name},
  );
}

/// Đơn CHƯA XONG trong tháng — con số đỏ trên tab Đơn hàng.
///
/// Tách sẵn hai nhóm thay vì một tổng: số đỏ cần tổng, còn lượt bấm vào nó cần
/// biết mở tab nào của màn Đơn hàng — chờ xác nhận và đang xử lý là hai tab
/// khác nhau ở đó.
class UnfinishedOrders {
  const UnfinishedOrders({this.pending = 0, this.doing = 0});

  /// Chờ xác nhận.
  final int pending;

  /// Đã xác nhận + đang chuẩn bị + đang giao.
  final int doing;

  int get total => pending + doing;

  /// Tab của màn Đơn hàng chứa đúng tập này; cả hai nhóm cùng có đơn thì không
  /// tab nào chứa đủ, mở "Tất cả".
  OrderTab get tab => switch ((pending > 0, doing > 0)) {
    (true, false) => OrderTab.pending,
    (false, true) => OrderTab.doing,
    _ => OrderTab.all,
  };

  /// Bộ lọc nên mở khi bấm số đỏ — null = cứ để nguyên màn Đơn hàng.
  ///
  /// Số đỏ đếm CẢ THÁNG còn màn Đơn hàng mở sẵn ở "Hôm nay", nên bấm vào mà
  /// không đổi kỳ thì hay ra danh sách trống. Đổi kỳ sang Tháng này + nhảy đúng
  /// tab. Người dùng đã tự chọn tab/kỳ/bộ lọc nào rồi thì KHÔNG đè lên.
  OrdersFilter? locKhiBam(OrdersFilter dangDung) {
    if (total == 0) return null;
    final sach =
        dangDung.tab == OrderTab.all &&
        dangDung.time == OrderTime.today &&
        dangDung.query.isEmpty &&
        dangDung.filterCount == 0;
    return sach ? dangDung.copyWith(tab: tab, time: OrderTime.thisMonth) : null;
  }
}

final unfinishedOrdersProvider = FutureProvider.autoDispose<UnfinishedOrders>((ref) async {
  final f = ref.watch(dashQueryProvider);
  if (f == null) return const UnfinishedOrders();
  final now = ref.read(clockProvider)();
  try {
    final r = await ref
        .read(apiClientProvider)
        .get<Map<String, dynamic>>(
          '/admin/reports/orders',
          query: {
            'from': ymd(DateTime(now.year, now.month)),
            'to': ymd(now),
            if (f.shopId != null) 'shop_id': '${f.shopId}',
          },
        );
    var cho = 0;
    var dang = 0;
    for (final s in ((r['by_status'] as List?) ?? const []).whereType<Map>()) {
      final n = ((s['orders'] as num?) ?? 0).toInt();
      if (s['key'] == 'pending') cho += n;
      if (const {'confirmed', 'processing', 'shipping'}.contains(s['key'])) dang += n;
    }
    return UnfinishedOrders(pending: cho, doing: dang);
  } on ApiException {
    return const UnfinishedOrders();
  }
}, retry: _noRetry);
