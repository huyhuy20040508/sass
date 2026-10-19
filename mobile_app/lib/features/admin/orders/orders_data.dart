import 'dart:math' as math;

import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/api/api_client.dart';
import '../../../core/api/api_exception.dart';
import '../../auth/auth_controller.dart';
import '../dashboard/dashboard_data.dart' show defaultShopProvider, clockProvider, ymd;

/// Một dòng sổ chứng từ bán hàng (GET /admin/orders/so-don): đơn bán hoặc phiếu trả.
class SoDonRow {
  SoDonRow(this.j);

  final Map<String, dynamic> j;

  bool get isReturn => j['loai'] != 'don';
  int get id => (j['id'] as num).toInt();
  String get code => '${j['ma'] ?? ''}';
  String get channel => '${j['kenh'] ?? ''}';
  String get customer => '${j['khach_hang'] ?? ''}';
  String get phone => '${j['so_dien_thoai'] ?? ''}';
  num get total => (j['tong_tien'] as num?) ?? 0;
  num get debt => (j['con_no'] as num?) ?? 0;
  String get method => '${j['phuong_thuc'] ?? ''}';

  /// Trạng thái của SỔ: paid | unpaid | partial | returned | cancelled.
  String get ledgerStatus => '${j['trang_thai'] ?? ''}';
  String get creator => '${j['nguoi_tao'] ?? ''}';
  bool get canEInvoice => j['xuat_duoc_hoa_don'] == true;
  DateTime get createdAt => DateTime.parse('${j['created_at']}').toLocal();
  DateTime get updatedAt => DateTime.parse('${j['updated_at']}').toLocal();
  bool get cancelled => ledgerStatus == 'cancelled';

  /// Trạng thái xử lý — chỉ có khi API đã bổ sung cột `trang_thai_don` vào dòng sổ.
  String? get procStatus => isReturn ? 'returned' : j['trang_thai_don'] as String?;
  int? get itemCount => (j['so_mon'] as num?)?.toInt();
  int? get qty => (j['so_luong'] as num?)?.toInt();
  String? get firstItem => j['ten_mon_dau'] as String?;
  List<String>? get thumbs => (j['anh_mon'] as List?)?.map((e) => '$e').toList();
}

/// Nhóm trạng thái xử lý = các tab của màn.
enum OrderTab {
  all('Tất cả'),
  pending('Chờ xác nhận'),
  doing('Đang xử lý'),
  done('Hoàn tất'),
  cancelled('Đã huỷ'),
  returned('Trả hàng');

  const OrderTab(this.label);

  final String label;

  /// Ba tab theo trạng thái xử lý cần cột `trang_thai_don` của API.
  bool get needsProcStatus => this == pending || this == doing || this == done;

  static OrderTab ofStatus(String? s) => switch (s) {
    'pending' => pending,
    'confirmed' || 'processing' || 'shipping' => doing,
    'completed' || 'delivered' => done,
    'cancelled' => cancelled,
    'returned' => returned,
    _ => all,
  };
}

enum OrderTime {
  today('Hôm nay'),
  yesterday('Hôm qua'),
  days7('7 ngày'),
  thisMonth('Tháng này'),
  lastMonth('Tháng trước'),
  all('Tất cả');

  const OrderTime(this.label);

  final String label;

  (DateTime, DateTime)? range(DateTime now) {
    final d = DateTime(now.year, now.month, now.day);
    return switch (this) {
      OrderTime.today => (d, d),
      OrderTime.yesterday => (d.subtract(const Duration(days: 1)), d.subtract(const Duration(days: 1))),
      OrderTime.days7 => (d.subtract(const Duration(days: 6)), d),
      OrderTime.thisMonth => (DateTime(d.year, d.month), d),
      OrderTime.lastMonth => (DateTime(d.year, d.month - 1), DateTime(d.year, d.month, 0)),
      OrderTime.all => null,
    };
  }
}

enum OrderSort {
  updated('Mới cập nhật'),
  newest('Mới nhất'),
  oldest('Cũ nhất'),
  totalDesc('Giá trị cao nhất'),
  totalAsc('Giá trị thấp nhất');

  const OrderSort(this.label);

  final String label;

  String get api => switch (this) {
    OrderSort.updated => 'updated',
    OrderSort.newest => 'newest',
    OrderSort.oldest => 'oldest',
    OrderSort.totalDesc => 'total_desc',
    OrderSort.totalAsc => 'total_asc',
  };

  bool get byTime => this == updated || this == newest || this == oldest;
}

const payLabels = {'paid': 'Đã thanh toán', 'unpaid': 'Chưa thanh toán', 'partial': 'Thanh toán một phần'};
const methodLabels = {'cash': 'Tiền mặt', 'bank_transfer': 'Chuyển khoản', 'card': 'Quẹt thẻ', 'auto_qr': 'QR tự động'};
const channelLabels = {'pos': 'Bán tại quầy', 'web': 'Đơn giao hàng'};

/// Nhãn hình thức thu của một mã phương thức (gộp các cổng QR).
String methodLabel(String m) => switch (m) {
  'cash' => 'Tiền mặt',
  'bank_transfer' => 'Chuyển khoản',
  'card' => 'Quẹt thẻ',
  'sepay' || 'payos' || 'mpos_qr' => 'QR tự động',
  _ => 'Khác',
};

/// Bộ lọc của màn Đơn hàng. [shopId] null = chi nhánh mặc định (xem [defaultShopProvider]).
class OrdersFilter {
  const OrdersFilter({
    this.tab = OrderTab.all,
    this.time = OrderTime.today,
    this.query = '',
    this.pays = const {},
    this.methods = const {},
    this.channels = const {},
    this.creators = const {},
    this.sort = OrderSort.updated,
    this.shopId,
  });

  final OrderTab tab;
  final OrderTime time;
  final String query;
  final Set<String> pays;
  final Set<String> methods;
  final Set<String> channels;

  /// id người lập.
  final Set<int> creators;
  final OrderSort sort;
  final int? shopId;

  int get filterCount => [pays, methods, channels, creators].where((s) => s.isNotEmpty).length;

  OrdersFilter copyWith({
    OrderTab? tab,
    OrderTime? time,
    String? query,
    Set<String>? pays,
    Set<String>? methods,
    Set<String>? channels,
    Set<int>? creators,
    OrderSort? sort,
    int? shopId,
  }) => OrdersFilter(
    tab: tab ?? this.tab,
    time: time ?? this.time,
    query: query ?? this.query,
    pays: pays ?? this.pays,
    methods: methods ?? this.methods,
    channels: channels ?? this.channels,
    creators: creators ?? this.creators,
    sort: sort ?? this.sort,
    shopId: shopId ?? this.shopId,
  );

  OrdersFilter clearFilters() => OrdersFilter(time: time, sort: sort, shopId: shopId);

  bool _setEq<T>(Set<T> a, Set<T> b) => a.length == b.length && a.containsAll(b);

  @override
  bool operator ==(Object other) =>
      other is OrdersFilter &&
      other.tab == tab &&
      other.time == time &&
      other.query == query &&
      _setEq(other.pays, pays) &&
      _setEq(other.methods, methods) &&
      _setEq(other.channels, channels) &&
      _setEq(other.creators, creators) &&
      other.sort == sort &&
      other.shopId == shopId;

  @override
  int get hashCode => Object.hash(
    tab,
    time,
    query,
    Object.hashAllUnordered(pays),
    Object.hashAllUnordered(methods),
    Object.hashAllUnordered(channels),
    Object.hashAllUnordered(creators),
    sort,
    shopId,
  );

  /// Tham số chung của sổ (không gồm trạng thái/trang). [statuses]: tập trạng thái sổ
  /// muốn lấy, giao với bộ lọc thanh toán; null = không ràng buộc.
  Map<String, String> query$(DateTime now, {Set<String>? statuses, bool withProc = true}) {
    final q = <String, String>{'sort': sort.api};
    final r = time.range(now);
    if (r != null) {
      q['from_date'] = ymd(r.$1);
      q['to_date'] = ymd(r.$2);
    }
    if (shopId != null) q['shop_id'] = '$shopId';
    final text = query.trim();
    if (text.isNotEmpty) {
      // Một ô tìm cho cả mã đơn lẫn tên / SĐT khách: chuỗi dạng "DH000073", "TKH3" là mã.
      if (RegExp(r'^[A-Za-z]{1,4}\d+$').hasMatch(text)) {
        q['keyword'] = text;
      } else {
        q['customer'] = text;
      }
    }
    if (methods.isNotEmpty) {
      q['payment_method'] = methods.expand((m) => m == 'auto_qr' ? ['sepay', 'payos', 'mpos_qr'] : [m]).join(',');
    }
    if (channels.isNotEmpty) q['channel'] = channels.join(',');
    if (creators.isNotEmpty) q['created_by'] = creators.join(',');

    Set<String>? st = statuses;
    if (pays.isNotEmpty) st = st == null ? {...pays} : st.intersection(pays);
    if (withProc) {
      if (tab == OrderTab.cancelled) st = st == null ? {'cancelled'} : st.intersection({'cancelled'});
      if (tab == OrderTab.returned) st = st == null ? {'returned'} : st.intersection({'returned'});
      if (tab.needsProcStatus) q['trang_thai_don'] = tab.name;
    }
    // Giao ra tập rỗng: gửi một giá trị không có thật → API trả 0 dòng.
    if (st != null) q['status'] = st.isEmpty ? 'none' : st.join(',');
    return q;
  }
}

final ordersFilterProvider = NotifierProvider<OrdersFilterNotifier, OrdersFilter>(OrdersFilterNotifier.new);

class OrdersFilterNotifier extends Notifier<OrdersFilter> {
  @override
  OrdersFilter build() => const OrdersFilter();

  void set(OrdersFilter f) => state = f;
}

/// Bộ lọc đã giải chi nhánh mặc định. null = đang tải danh sách chi nhánh.
final ordersQueryProvider = Provider.autoDispose<OrdersFilter?>((ref) {
  final f = ref.watch(ordersFilterProvider);
  if (f.shopId != null) return f;
  final d = ref.watch(defaultShopProvider);
  if (!d.hasValue) return null;
  return d.value == null ? f : f.copyWith(shopId: d.value);
});

/// API đã có cột trạng thái xử lý trong dòng sổ chưa — quyết định có hiện ba tab
/// Chờ xác nhận / Đang xử lý / Hoàn tất hay không. Học từ lần tải sổ đầu tiên.
final procStatusSupportedProvider = NotifierProvider<_Flag, bool>(_Flag.new);

class _Flag extends Notifier<bool> {
  @override
  bool build() => false;

  void set(bool v) {
    if (v != state) state = v;
  }
}

class OrdersPage {
  const OrdersPage({
    required this.rows,
    required this.total,
    required this.page,
    required this.totalPages,
    this.loadingMore = false,
    this.dem,
  });

  final List<SoDonRow> rows;
  final int total;
  final int page;
  final int totalPages;
  final bool loadingMore;

  /// Số đơn theo tab (meta.dem) — có khi API đã bổ sung.
  final Map<String, int>? dem;

  bool get hasMore => page < totalPages;

  OrdersPage copyWith({List<SoDonRow>? rows, int? page, bool? loadingMore}) => OrdersPage(
    rows: rows ?? this.rows,
    total: total,
    page: page ?? this.page,
    totalPages: totalPages,
    loadingMore: loadingMore ?? this.loadingMore,
    dem: dem,
  );
}

const ordersPageSize = 30;

Duration? _noRetry(int _, Object _) => null;

/// Sổ đơn theo trang — cuộn tới cuối thì [OrdersList.loadMore].
final ordersListProvider = AsyncNotifierProvider.autoDispose.family<OrdersList, OrdersPage, OrdersFilter>(
  OrdersList.new,
  retry: _noRetry,
);

class OrdersList extends AsyncNotifier<OrdersPage> {
  OrdersList(this.filter);

  final OrdersFilter filter;

  Future<ApiPage> _fetch(int page) {
    final now = ref.read(clockProvider)();
    return ref
        .read(apiClientProvider)
        .getPage(
          '/admin/orders/so-don',
          query: {...filter.query$(now), 'page': '$page', 'page_size': '$ordersPageSize'},
        );
  }

  @override
  Future<OrdersPage> build() async {
    final p = await _fetchRaw(1);
    return p;
  }

  Future<OrdersPage> _fetchRaw(int page) async {
    // Đọc mọi thứ cần từ ref TRƯỚC khi chờ API: đổi bộ lọc giữa chừng thì provider
    // này bị huỷ, đọc ref sau await là ném lỗi.
    final now = ref.read(clockProvider)();
    final flag = ref.read(procStatusSupportedProvider.notifier);
    final res = await ref
        .read(apiClientProvider)
        .getRaw(
          '/admin/orders/so-don',
          query: {...filter.query$(now), 'page': '$page', 'page_size': '$ordersPageSize'},
        );
    final rows = ((res['data'] as List?) ?? const [])
        .whereType<Map>()
        .map((m) => SoDonRow(Map<String, dynamic>.from(m)))
        .toList();
    if (rows.isNotEmpty && ref.mounted) flag.set(rows.first.j.containsKey('trang_thai_don'));
    final meta = (res['meta'] as Map?) ?? const {};
    // Số theo tab: API đặt trong meta.tong.dem (bản vá sổ đơn cho mobile).
    final demRaw = (meta['tong'] as Map?)?['dem'] ?? meta['dem'];
    final dem = demRaw is Map ? {for (final e in demRaw.entries) '${e.key}': ((e.value as num?) ?? 0).toInt()} : null;
    return OrdersPage(
      rows: rows,
      total: ((meta['total'] as num?) ?? rows.length).toInt(),
      page: page,
      totalPages: ((meta['total_pages'] as num?) ?? 1).toInt(),
      dem: dem,
    );
  }

  Future<void> loadMore() async {
    final cur = state.value;
    if (cur == null || !cur.hasMore || cur.loadingMore) return;
    state = AsyncData(cur.copyWith(loadingMore: true));
    try {
      final next = await _fetch(cur.page + 1);
      if (!ref.mounted) return;
      final more = next.data.whereType<Map>().map((m) => SoDonRow(Map<String, dynamic>.from(m)));
      state = AsyncData(cur.copyWith(rows: [...cur.rows, ...more], page: cur.page + 1, loadingMore: false));
    } on ApiException {
      if (ref.mounted) state = AsyncData(cur.copyWith(loadingMore: false));
    }
  }
}

/// Thẻ tổng của kỳ (không phụ thuộc tab): doanh thu = đơn bán chưa huỷ − phiếu trả,
/// chưa thu, số đơn huỷ, số phiếu trả, và doanh thu theo ngày cho biểu đồ cột nhỏ.
class OrdersSummary {
  const OrdersSummary({
    required this.revenue,
    required this.sales,
    required this.debt,
    required this.debtOrders,
    required this.cancelled,
    required this.returned,
    required this.returnedRows,
    required this.days,
    required this.perDay,
  });

  final num revenue;
  final int sales;
  final num debt;
  final int debtOrders;
  final int cancelled;

  /// Số PHIẾU trả trong kỳ (chỉ số "Trả hàng" của thẻ tổng).
  final int returned;

  /// Số dòng ở tab Trả hàng = phiếu trả + đơn đã bị trả (cùng trạng thái sổ `returned`).
  final int returnedRows;
  final List<DateTime> days;
  final List<num> perDay;
}

final ordersSummaryProvider = FutureProvider.autoDispose.family<OrdersSummary, OrdersFilter>((ref, f) async {
  final api = ref.read(apiClientProvider);
  final now = ref.read(clockProvider)();
  // Thẻ tổng tính trên cả kỳ đang xem — không theo tab.
  final base = f.copyWith(tab: OrderTab.all);
  Future<ApiPage> page(Set<String> st, {int size = 1, int p = 1, Map<String, String> extra = const {}}) => api.getPage(
    '/admin/orders/so-don',
    query: {
      ...base.query$(now, statuses: st),
      ...extra,
      'page': '$p',
      'page_size': '$size',
    },
  );
  Future<Map<String, dynamic>> tong(Set<String> st) async {
    final r = await api.getRaw(
      '/admin/orders/so-don',
      query: {
        ...base.query$(now, statuses: st),
        'page_size': '1',
      },
    );
    return Map<String, dynamic>.from(((r['meta'] as Map?)?['tong'] as Map?) ?? const {});
  }

  const ban = {'paid', 'unpaid', 'partial'};
  final r =
      f.time.range(now) ??
      (
        DateTime(now.year, now.month, now.day).subtract(const Duration(days: 13)),
        DateTime(now.year, now.month, now.day),
      );
  final nDays = math.min(14, r.$2.difference(r.$1).inDays + 1);
  final days = [for (var i = nDays - 1; i >= 0; i--) r.$2.subtract(Duration(days: i))];

  final res = await Future.wait<Object>([
    tong(ban),
    // Trạng thái sổ `returned` gồm cả phiếu trả LẪN đơn đã bị trả: đơn vẫn là đơn
    // bán (cộng), chỉ phiếu trả mới trừ — phải tách theo cột `loai`.
    page({'returned'}, size: 100),
    page({'cancelled'}),
    // Chưa thu: cộng con_no từ danh sách (meta chưa có tổng nợ).
    page({'unpaid', 'partial'}, size: 100),
    // Biểu đồ cột: đơn bán trong ≤ 14 ngày cuối của kỳ.
    Future.wait([
      for (var p = 1; p <= 3; p++)
        page(ban, size: 100, p: p, extra: {'from_date': ymd(days.first), 'to_date': ymd(days.last)}),
    ]),
    tong({'unpaid', 'partial'}),
  ]);
  final sales = res[0] as Map<String, dynamic>;
  final retRows = (res[1] as ApiPage).data.whereType<Map>().map((m) => SoDonRow(Map<String, dynamic>.from(m))).toList();
  final phieu = retRows.where((r) => r.isReturn), donTra = retRows.where((r) => !r.isReturn);
  final unpaid = res[3] as ApiPage;
  final unpaidTong = (res[5] as Map<String, dynamic>)['con_no'] as num? ?? 0;
  final perDay = {for (final d in days) ymd(d): 0 as num};
  final seen = <int>{};
  for (final pg in res[4] as List<ApiPage>) {
    for (final m in pg.data.whereType<Map>()) {
      final row = SoDonRow(Map<String, dynamic>.from(m));
      if (row.isReturn || !seen.add(row.id)) continue;
      final k = ymd(row.createdAt);
      if (perDay.containsKey(k)) perDay[k] = perDay[k]! + row.total;
    }
  }
  return OrdersSummary(
    revenue:
        ((sales['tong_tien'] as num?) ?? 0) +
        donTra.fold<num>(0, (a, r) => a + r.total) -
        phieu.fold<num>(0, (a, r) => a + r.total),
    sales: ((sales['so_dong'] as num?) ?? 0).toInt() + donTra.length,
    // API đã có tổng nợ (meta.tong.con_no) thì dùng; chưa có thì cộng từ ≤100 dòng nợ.
    debt: (sales['con_no'] as num?) != null
        ? unpaidTong
        : unpaid.data.whereType<Map>().fold<num>(0, (a, m) => a + ((m['con_no'] as num?) ?? 0)),
    debtOrders: unpaid.total,
    cancelled: (res[2] as ApiPage).total,
    returned: phieu.length,
    returnedRows: (res[1] as ApiPage).total,
    days: days,
    perDay: [for (final d in days) perDay[ymd(d)]!],
  );
}, retry: _noRetry);

/// Chi tiết một đơn (GET /admin/orders/{id}). Giữ trong bộ nhớ khi còn dùng —
/// thẻ đơn đọc ảnh món + trạng thái từ đây khi dòng sổ chưa có.
final orderDetailProvider = FutureProvider.autoDispose.family<Map<String, dynamic>, int>((ref, id) async {
  final link = ref.keepAlive();
  try {
    return await ref.read(apiClientProvider).get<Map<String, dynamic>>('/admin/orders/$id');
  } catch (_) {
    link.close();
    rethrow;
  }
}, retry: _noRetry);

/// Nhân viên để lọc "Nhân viên tạo". Không có quyền xem tài khoản thì rỗng (ẩn nhóm lọc).
final orderCreatorsProvider = FutureProvider.autoDispose<List<(int, String)>>((ref) async {
  try {
    final p = await ref
        .read(apiClientProvider)
        .getPage('/admin/users', query: {'page_size': '100', 'status': 'active'});
    return [
      for (final u in p.data.whereType<Map>())
        ((u['id'] as num).toInt(), '${u['full_name'] ?? ''}'.isEmpty ? '${u['username']}' : '${u['full_name']}'),
    ];
  } on ApiException {
    return const [];
  }
}, retry: _noRetry);
