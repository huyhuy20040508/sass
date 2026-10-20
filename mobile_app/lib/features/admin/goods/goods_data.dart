import 'dart:convert';
import 'dart:math' as math;

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../../../core/api/api_client.dart';
import '../../../core/api/api_exception.dart';
import '../../../core/format/vn_text.dart';
import '../../auth/auth_controller.dart';

/// Mốc "sắp hết" mặc định khi chưa đọc được cài đặt — cùng NormalizeLowStock của API.
const defaultLowStock = 5;

/// Một phiên bản trong tồn kho chi nhánh đang làm việc (GET /admin/inventory).
class StockRow {
  StockRow(this.j);

  final Map<String, dynamic> j;

  int get productId => (j['product_id'] as num).toInt();
  int get variantId => (j['variant_id'] as num).toInt();
  String get name => '${j['variant_name'] ?? ''}';
  String get sku => '${j['sku'] ?? ''}';
  num get qty => (j['stock_quantity'] as num?) ?? 0;
  num get price => (j['price'] as num?) ?? 0;
  num? get cost => j['cost_price'] as num?;
  bool get active => j['is_active'] != false;
}

class GoodsCategory {
  const GoodsCategory({required this.id, required this.name, this.parentId, this.isActive = true, this.vat = 0});

  final int id;
  final String name;
  final int? parentId;
  final bool isActive;

  /// Mức VAT mặc định của nhóm — mặt hàng mới lấy theo, trừ khi khai riêng.
  final int vat;
}

/// Một mặt hàng của danh sách: dòng GET /products (slim) + các dòng tồn của nó.
class GoodsItem {
  GoodsItem(this.j, this.stockRows, {this.stockKnown = true});

  final Map<String, dynamic> j;
  final List<StockRow> stockRows;

  /// Đã có số tồn của mặt hàng này (thiếu quyền xem tồn, hoặc trang tồn của nó chưa nạp tới → false).
  final bool stockKnown;

  int get id => (j['id'] as num).toInt();
  String get name => '${j['name'] ?? ''}';
  String get sku => '${j['sku'] ?? ''}';
  String get thumb => '${j['thumbnail'] ?? ''}';
  bool get active => (j['status'] as String?) == null ? j['is_active'] == true : j['status'] == 'active';
  Map<String, dynamic>? get category => (j['category'] as Map?)?.cast<String, dynamic>();
  int? get categoryId => ((category?['id'] ?? j['category_id']) as num?)?.toInt();
  String get categoryName => '${category?['name'] ?? ''}';
  int get sold => ((j['sold_count'] as num?) ?? 0).toInt();
  bool get isSerial => j['is_serial'] == true;
  DateTime get createdAt => DateTime.tryParse('${j['created_at']}')?.toLocal() ?? DateTime(2000);

  List<StockRow> get variants => [
    for (final r in stockRows)
      if (r.active) r,
  ];

  /// Tồn chi nhánh đang làm việc, cộng mọi phiên bản.
  num get stock => stockRows.fold<num>(0, (a, r) => a + r.qty);

  List<num> get _prices => variants.isNotEmpty
      ? [for (final v in variants) v.price]
      : [((j['sale_price'] as num?) ?? (j['base_price'] as num?) ?? 0)];
  num get minPrice => _prices.reduce(math.min);
  num get maxPrice => _prices.reduce(math.max);

  num? get cost => variants.isNotEmpty ? variants.first.cost : j['cost_price'] as num?;
}

class GoodsCatalog {
  const GoodsCatalog({
    required this.items,
    required this.categories,
    this.complete = true,
    this.total = 0,
    this.stockKnown = true,
    this.lowStock = defaultLowStock,
    this.loadError,
  });

  /// Lỗi khi nạp các trang sau (phần đã có vẫn dùng được).
  final String? loadError;

  final List<GoodsItem> items;
  final List<GoodsCategory> categories;

  /// Đã tải hết mọi trang (sản phẩm + tồn). Chưa hết thì danh sách đang lớn dần.
  final bool complete;

  /// Tổng số sản phẩm theo API (để ghi "đang tải 300/1.250").
  final int total;

  /// Đọc được tồn kho (thiếu quyền xem tồn thì không có — ẩn số tồn, không báo "Hết hàng" sai).
  final bool stockKnown;

  /// Mốc "sắp hết" của cửa hàng (Cài đặt → low_stock_threshold).
  final int lowStock;

  GoodsItem? byId(int id) => items.where((x) => x.id == id).firstOrNull;
}

Duration? _noRetry(int _, Object _) => null;

/// Chi nhánh đang xem ở màn Hàng hoá: null = chi nhánh làm việc của phiên,
/// 0 = cả cửa hàng (tồn cộng mọi chi nhánh), số khác = đúng chi nhánh đó.
final goodsBranchProvider = NotifierProvider<GoodsBranchNotifier, int?>(GoodsBranchNotifier.new);

class GoodsBranchNotifier extends Notifier<int?> {
  @override
  int? build() => null;

  void set(int? v) => state = v;
}

/// Tham số chi nhánh cho /products: lọc theo chi nhánh QUẢN LÝ mặt hàng (0 = không lọc).
Map<String, String> _shopQuery(int? branch) => branch == null ? const {} : {'shop_id': '$branch'};

const _pageSize = 100;

/// Danh mục hàng + tồn + nhóm hàng, tải DẦN: trang đầu hiện ngay, các trang sau nạp
/// nền và nối vào (shop vài nghìn mặt hàng không phải chờ tải hết mới thấy gì).
/// Lọc / đếm / xếp làm trên máy: chip "Hết hàng" và nút "Xem N sản phẩm" cần số của
/// mọi tổ hợp lọc, mà tồn lại nằm ở API khác (/admin/inventory) với sản phẩm.
final goodsCatalogProvider = AsyncNotifierProvider.autoDispose<GoodsCatalogNotifier, GoodsCatalog>(
  GoodsCatalogNotifier.new,
  retry: _noRetry,
);

class GoodsCatalogNotifier extends AsyncNotifier<GoodsCatalog> {
  /// Lượt tải hiện hành — đổi chi nhánh / làm mới thì lượt nạp nền cũ tự dừng.
  int _gen = 0;

  final _products = <Map<String, dynamic>>[];
  final _stock = <int, List<StockRow>>{};
  List<GoodsCategory> _cats = const [];
  bool _stockKnown = true;
  bool _stockDone = true;
  int _low = defaultLowStock;

  @override
  Future<GoodsCatalog> build() async {
    final gen = ++_gen;
    final branch = ref.watch(goodsBranchProvider);
    final api = ref.read(apiClientProvider);
    _products.clear();
    _stock.clear();

    Future<ApiPage> products(int p) => api.getPage(
      '/products',
      query: {
        'all': 'true',
        'slim': 'true',
        'sort': 'newest',
        ..._shopQuery(branch),
        'page': '$p',
        'page_size': '$_pageSize',
      },
    );
    Future<ApiPage?> stock(int p) async {
      try {
        return await api.getPage(
          '/admin/inventory',
          query: {'sort': 'newest', 'page': '$p', 'page_size': '$_pageSize'},
          branch: branch,
        );
      } on ApiException catch (e) {
        // Thiếu quyền xem tồn kho: danh sách vẫn dùng được, chỉ không có số tồn.
        if (e.statusCode == 403) return null;
        rethrow;
      }
    }

    Future<int> low() async {
      try {
        final s = await api.get<Map<String, dynamic>>('/admin/settings');
        return int.tryParse('${(s['values'] as Map?)?['low_stock_threshold'] ?? ''}') ?? defaultLowStock;
      } on ApiException {
        return defaultLowStock;
      }
    }

    final (p1, s1, cats, lowStock) = await (
      products(1),
      stock(1),
      api.getRaw('/categories', query: {'all': 'true'}),
      low(),
    ).wait;
    _stockKnown = s1 != null;
    _low = lowStock;
    _cats = [
      for (final c in ((cats['data'] as List?) ?? const []).whereType<Map>())
        GoodsCategory(
          id: (c['id'] as num).toInt(),
          name: '${c['name'] ?? ''}',
          parentId: (c['parent_id'] as num?)?.toInt(),
          isActive: c['is_active'] != false,
          vat: ((c['vat'] as num?) ?? 0).toInt(),
        ),
    ];
    _addProducts(p1);
    if (s1 != null) _addStock(s1);
    final pPages = p1.totalPages, sPages = s1?.totalPages ?? 1;
    _stockDone = sPages <= 1;
    final done = pPages <= 1 && sPages <= 1;
    if (!done) Future(() => _loadRest(gen, products, stock, pPages, sPages, p1.total));
    return _snapshot(complete: done, total: p1.total);
  }

  void _addProducts(ApiPage p) {
    for (final m in p.data.whereType<Map>()) {
      _products.add(Map<String, dynamic>.from(m));
    }
  }

  void _addStock(ApiPage p) {
    for (final m in p.data.whereType<Map>()) {
      final r = StockRow(Map<String, dynamic>.from(m));
      (_stock[r.productId] ??= []).add(r);
    }
  }

  GoodsCatalog _snapshot({required bool complete, required int total, String? error}) => GoodsCatalog(
    items: [
      for (final p in _products)
        if (_stock[(p['id'] as num).toInt()] case final rows?)
          GoodsItem(p, rows, stockKnown: _stockKnown)
        else
          // Chưa nạp tới trang tồn của nó thì chưa biết — đừng vội báo "Hết hàng".
          GoodsItem(p, const [], stockKnown: _stockKnown && _stockDone),
    ],
    categories: _cats,
    complete: complete,
    total: total,
    stockKnown: _stockKnown,
    lowStock: _low,
    loadError: error,
  );

  /// Nạp các trang còn lại, mỗi đợt 4 trang song song, đẩy danh sách mới lên sau mỗi đợt.
  Future<void> _loadRest(
    int gen,
    Future<ApiPage> Function(int) products,
    Future<ApiPage?> Function(int) stock,
    int pPages,
    int sPages,
    int total,
  ) async {
    bool alive() => gen == _gen && ref.mounted;
    try {
      final last = math.max(pPages, _stockKnown ? sPages : 1);
      for (var from = 2; from <= last; from += 4) {
        final to = math.min(from + 3, last);
        final batch = await (
          Future.wait([for (var p = from; p <= math.min(to, pPages); p++) products(p)]),
          Future.wait([
            if (_stockKnown)
              for (var p = from; p <= math.min(to, sPages); p++) stock(p),
          ]),
        ).wait;
        if (!alive()) return;
        batch.$1.forEach(_addProducts);
        for (final s in batch.$2) {
          if (s != null) _addStock(s);
        }
        _stockDone = to >= sPages;
        state = AsyncData(_snapshot(complete: to >= last, total: total));
      }
    } on ApiException catch (e) {
      // Lỗi giữa chừng: giữ phần đã có, ghi lỗi để người dùng kéo xuống tải lại.
      if (alive()) state = AsyncData(_snapshot(complete: false, total: total, error: e.message));
    }
  }
}

/// Mọi trang của một danh sách có phân trang (≤ [maxPages] × 100 dòng).
Future<List<Map<String, dynamic>>> _allPages(
  ApiClient api,
  String path,
  Map<String, String> query, {
  int maxPages = 30,
}) async {
  Future<ApiPage> page(int p) => api.getPage(path, query: {...query, 'page': '$p', 'page_size': '$_pageSize'});
  final first = await page(1);
  final rest = await Future.wait([for (var p = 2; p <= math.min(first.totalPages, maxPages); p++) page(p)]);
  return [
    for (final pg in [first, ...rest])
      for (final m in pg.data.whereType<Map>()) Map<String, dynamic>.from(m),
  ];
}

/// id mặt hàng khớp từ khoá theo API — bắt cả mã vạch / tên phiên bản mà danh
/// sách rút gọn không có.
final goodsSearchProvider = FutureProvider.autoDispose.family<Set<int>, String>((ref, q) async {
  final rows = await _allPages(ref.read(apiClientProvider), '/products', {
    ..._shopQuery(ref.read(goodsBranchProvider)),
    'all': 'true',
    'slim': 'true',
    'keyword': q,
  }, maxPages: 5);
  return {for (final p in rows) (p['id'] as num).toInt()};
}, retry: _noRetry);

enum GoodsStatus {
  all('Tất cả'),
  active('Đang bán'),
  out('Hết hàng'),
  hidden('Ngừng bán');

  const GoodsStatus(this.label);

  final String label;

  bool has(GoodsItem it) => switch (this) {
    all => true,
    active => it.active,
    out => it.active && it.stockKnown && it.stock <= 0,
    hidden => !it.active,
  };
}

enum GoodsSort {
  newest('Mới nhất'),
  nameAsc('Tên A→Z'),
  nameDesc('Tên Z→A'),
  priceAsc('Giá tăng dần'),
  priceDesc('Giá giảm dần'),
  bestSelling('Bán chạy');

  const GoodsSort(this.label);

  final String label;

  int compare(GoodsItem a, GoodsItem b) => switch (this) {
    newest => b.createdAt.compareTo(a.createdAt),
    nameAsc => compareVn(a.name, b.name),
    nameDesc => compareVn(b.name, a.name),
    priceAsc => a.minPrice.compareTo(b.minPrice),
    priceDesc => b.maxPrice.compareTo(a.maxPrice),
    bestSelling => b.sold.compareTo(a.sold),
  };
}

class GoodsFilter {
  const GoodsFilter({
    this.status = GoodsStatus.all,
    this.query = '',
    this.cats = const {},
    this.sort = GoodsSort.newest,
  });

  final GoodsStatus status;
  final String query;
  final Set<int> cats;
  final GoodsSort sort;

  GoodsFilter copyWith({GoodsStatus? status, String? query, Set<int>? cats, GoodsSort? sort}) => GoodsFilter(
    status: status ?? this.status,
    query: query ?? this.query,
    cats: cats ?? this.cats,
    sort: sort ?? this.sort,
  );

  @override
  bool operator ==(Object other) =>
      other is GoodsFilter &&
      other.status == status &&
      other.query == query &&
      other.cats.length == cats.length &&
      other.cats.containsAll(cats) &&
      other.sort == sort;

  @override
  int get hashCode => Object.hash(status, query, Object.hashAllUnordered(cats), sort);
}

final goodsFilterProvider = NotifierProvider<GoodsFilterNotifier, GoodsFilter>(GoodsFilterNotifier.new);

/// Bộ lọc nhớ qua các lần mở app: trạng thái, nhóm hàng, sắp xếp (không nhớ từ khoá tìm).
class GoodsFilterNotifier extends Notifier<GoodsFilter> {
  static const _key = 'goods.filter';
  bool _touched = false;

  @override
  GoodsFilter build() {
    _restore();
    return const GoodsFilter();
  }

  Future<void> _restore() async {
    try {
      final raw = (await SharedPreferences.getInstance()).getString(_key);
      // Người dùng đã chọn gì trong lúc chờ đọc thì giữ lựa chọn đó.
      if (raw == null || _touched || !ref.mounted) return;
      final m = jsonDecode(raw) as Map<String, dynamic>;
      state = GoodsFilter(
        status: GoodsStatus.values.asNameMap()[m['status']] ?? GoodsStatus.all,
        cats: {for (final c in (m['cats'] as List? ?? const [])) (c as num).toInt()},
        sort: GoodsSort.values.asNameMap()[m['sort']] ?? GoodsSort.newest,
      );
    } catch (_) {
      // Không đọc được (máy chặn lưu, dữ liệu hỏng) thì dùng mặc định.
    }
  }

  void set(GoodsFilter f) {
    _touched = true;
    state = f;
    SharedPreferences.getInstance()
        .then(
          (p) => p.setString(_key, jsonEncode({'status': f.status.name, 'cats': f.cats.toList(), 'sort': f.sort.name})),
        )
        .catchError((_) => false);
  }
}

/// Nhóm đã chọn kèm mọi nhóm con / cháu — chọn "Hàng bán" là thấy cả "Điện thoại" bên trong.
Set<int> expandCategories(Set<int> picked, List<GoodsCategory> all) {
  final out = {...picked};
  var grew = true;
  while (grew) {
    grew = false;
    for (final c in all) {
      if (c.parentId != null && out.contains(c.parentId) && out.add(c.id)) grew = true;
    }
  }
  return out;
}

/// Mặt hàng khớp từ khoá + nhóm (chưa xét trạng thái). [serverIds]: id API tìm được
/// cho từ khoá — gộp với phần khớp tại chỗ (tên / SKU, không phân biệt dấu).
List<GoodsItem> matchGoods(GoodsCatalog c, {String query = '', Set<int> cats = const {}, Set<int>? serverIds}) {
  final q = foldVn(query.trim());
  final inCats = cats.isEmpty ? null : expandCategories(cats, c.categories);
  bool hit(GoodsItem it) =>
      q.isEmpty ||
      (serverIds?.contains(it.id) ?? false) ||
      foldVn('${it.name} ${it.sku} ${it.stockRows.map((r) => r.sku).join(' ')}').contains(q);
  return [
    for (final it in c.items)
      if (hit(it) && (inCats == null || inCats.contains(it.categoryId))) it,
  ];
}

/// Chi tiết sản phẩm (GET /admin/products/{id}).
final productDetailProvider = FutureProvider.autoDispose.family<Map<String, dynamic>, int>(
  (ref, id) => ref.read(apiClientProvider).get<Map<String, dynamic>>('/admin/products/$id'),
  retry: _noRetry,
);

/// Tồn của một mặt hàng ở từng chi nhánh: (tên chi nhánh, số lượng), theo thứ tự chi nhánh của API.
final productBranchStockProvider = FutureProvider.autoDispose.family<List<(String, num)>, (int, String)>((
  ref,
  key,
) async {
  final (id, sku) = key;
  final res = await ref
      .read(apiClientProvider)
      .getRaw('/admin/inventory/chi-nhanh', query: {'keyword': sku, 'page_size': '200'});
  final data = (res['data'] as Map?) ?? const {};
  final by = <String, num>{};
  for (final r in ((data['dong'] as List?) ?? const []).whereType<Map>()) {
    if ((r['product_id'] as num?)?.toInt() != id) continue;
    final name = '${r['shop_name'] ?? ''}';
    by[name] = (by[name] ?? 0) + ((r['quantity'] as num?) ?? 0);
  }
  return [for (final e in by.entries) (e.key, e.value)];
}, retry: _noRetry);

/// Ngừng bán / mở bán lại. Gửi cờ `is_active` thay vì mức trạng thái: API tự giữ
/// mức "ngừng kinh doanh" nếu mặt hàng đang ở mức đó.
Future<void> setProductActive(WidgetRef ref, int id, bool active) async {
  await ref.read(apiClientProvider).put<Object?>('/admin/products/$id/status', body: {'is_active': active});
  ref.invalidate(goodsCatalogProvider);
  ref.invalidate(productDetailProvider(id));
}

/// Sao chép thành mặt hàng mới — API đặt tên "… (Bản sao)", SKU mới, để ở trạng thái ẩn.
Future<void> duplicateProduct(WidgetRef ref, int id) async {
  await ref.read(apiClientProvider).post<Object?>('/admin/products/$id/duplicate');
  ref.invalidate(goodsCatalogProvider);
}

/// Tra một mã vừa quét: khớp đúng SKU sản phẩm / phiên bản trong danh mục đã tải,
/// không có thì hỏi API (bắt cả mã vạch). Nhiều kết quả hoặc không có thì null.
Future<GoodsItem?> lookupCode(WidgetRef ref, String raw) async {
  final code = raw.trim().toUpperCase();
  if (code.isEmpty) return null;
  final c = await ref.read(goodsCatalogProvider.future);
  for (final it in c.items) {
    if (it.sku.toUpperCase() == code || it.stockRows.any((r) => r.sku.toUpperCase() == code)) return it;
  }
  // Gọi thẳng API thay vì đọc goodsSearchProvider: provider tự huỷ khi không ai theo dõi.
  final p = await ref
      .read(apiClientProvider)
      .getPage('/products', query: {'all': 'true', 'slim': 'true', 'keyword': raw.trim(), 'page_size': '2'});
  return p.total == 1 ? c.byId(((p.data.first as Map)['id'] as num).toInt()) : null;
}

/// Khai giá vốn cho một phiên bản (PUT /admin/inventory/cost) — không ghi sổ kho.
Future<void> setVariantCost(WidgetRef ref, int productId, int variantId, num cost) async {
  await ref
      .read(apiClientProvider)
      .put<Object?>(
        '/admin/inventory/cost',
        body: {
          'items': [
            {'variant_id': variantId, 'cost_price': cost},
          ],
        },
      );
  ref.invalidate(goodsCatalogProvider);
  ref.invalidate(productDetailProvider(productId));
}
