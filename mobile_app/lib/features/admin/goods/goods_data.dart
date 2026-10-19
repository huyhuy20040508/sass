import 'dart:convert';
import 'dart:math' as math;

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../../../core/api/api_client.dart';
import '../../../core/format/vn_text.dart';
import '../../auth/auth_controller.dart';

/// Tồn ≤ mốc này thì tô "sắp hết" — cùng mốc mặc định của trang Tồn kho (NormalizeLowStock).
const lowStock = 5;

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
  const GoodsCategory({required this.id, required this.name, this.parentId});

  final int id;
  final String name;
  final int? parentId;
}

/// Một mặt hàng của danh sách: dòng GET /products (slim) + các dòng tồn của nó.
class GoodsItem {
  GoodsItem(this.j, this.stockRows);

  final Map<String, dynamic> j;
  final List<StockRow> stockRows;

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
  const GoodsCatalog({required this.items, required this.categories});

  final List<GoodsItem> items;
  final List<GoodsCategory> categories;

  GoodsItem? byId(int id) => items.where((x) => x.id == id).firstOrNull;
}

Duration? _noRetry(int _, Object _) => null;

/// Mọi trang của một danh sách có phân trang (≤ [maxPages] × 100 dòng).
Future<List<Map<String, dynamic>>> _allPages(
  ApiClient api,
  String path,
  Map<String, String> query, {
  int maxPages = 30,
}) async {
  Future<ApiPage> page(int p) => api.getPage(path, query: {...query, 'page': '$p', 'page_size': '100'});
  final first = await page(1);
  final rest = await Future.wait([for (var p = 2; p <= math.min(first.totalPages, maxPages); p++) page(p)]);
  return [
    for (final pg in [first, ...rest])
      for (final m in pg.data.whereType<Map>()) Map<String, dynamic>.from(m),
  ];
}

/// Cả danh mục hàng của chi nhánh đang làm việc + tồn + nhóm hàng. Tải một lần rồi
/// lọc / đếm / xếp ngay trên máy: các chip trạng thái và nút "Xem N sản phẩm" cần
/// số của mọi tổ hợp lọc, mà "Hết hàng" lại ghép từ hai API (sản phẩm + tồn).
final goodsCatalogProvider = FutureProvider.autoDispose<GoodsCatalog>((ref) async {
  final api = ref.read(apiClientProvider);
  final res = await Future.wait<Object>([
    _allPages(api, '/products', {'all': 'true', 'slim': 'true', 'sort': 'newest'}),
    _allPages(api, '/admin/inventory', {}, maxPages: 50),
    api.getRaw('/categories', query: {'all': 'true'}),
  ]);
  final stock = <int, List<StockRow>>{};
  for (final m in res[1] as List<Map<String, dynamic>>) {
    final r = StockRow(m);
    (stock[r.productId] ??= []).add(r);
  }
  final cats = ((res[2] as Map)['data'] as List?) ?? const [];
  return GoodsCatalog(
    items: [
      for (final p in res[0] as List<Map<String, dynamic>>) GoodsItem(p, stock[(p['id'] as num).toInt()] ?? const []),
    ],
    categories: [
      for (final c in cats.whereType<Map>())
        GoodsCategory(
          id: (c['id'] as num).toInt(),
          name: '${c['name'] ?? ''}',
          parentId: (c['parent_id'] as num?)?.toInt(),
        ),
    ],
  );
}, retry: _noRetry);

/// id mặt hàng khớp từ khoá theo API — bắt cả mã vạch / tên phiên bản mà danh
/// sách rút gọn không có.
final goodsSearchProvider = FutureProvider.autoDispose.family<Set<int>, String>((ref, q) async {
  final rows = await _allPages(ref.read(apiClientProvider), '/products', {
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
    out => it.active && it.stock <= 0,
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

/// Đổi giá bán một phiên bản. API không có đường sửa riêng giá: PUT /admin/products/{id}
/// nhận lại cả mặt hàng, và danh sách `variants` gửi lên là ĐỒNG BỘ (vắng dòng nào là
/// xoá dòng đó) — nên dựng lại đủ mọi trường từ chi tiết [d], chỉ thay giá của [variantId].
Future<void> setVariantPrice(WidgetRef ref, Map<String, dynamic> d, int variantId, num price) async {
  final id = (d['id'] as num).toInt();
  final variants = [
    for (final v in ((d['variants'] as List?) ?? const []).whereType<Map>())
      {
        'id': v['id'],
        'sku': v['sku'] ?? '',
        'barcode': v['barcode'] ?? '',
        'name': v['name'] ?? '',
        'attributes': [
          for (final a in ((v['attributes'] as List?) ?? const []).whereType<Map>())
            {'attribute_id': a['attribute_id'], 'value_id': a['value_id']},
        ],
        'is_default': v['is_default'],
        'pos': v['pos'],
        'price': (v['id'] as num).toInt() == variantId ? price : v['price'],
        'cost_price': v['cost_price'],
        'weight_gram': v['weight_gram'] ?? 0,
        'image': v['image'] ?? '',
        'is_active': v['is_active'],
      },
  ];
  await ref
      .read(apiClientProvider)
      .put<Object?>(
        '/admin/products/$id',
        body: {
          'category_id': d['category_id'] ?? (d['category'] as Map?)?['id'],
          'name': d['name'],
          'slug': d['slug'],
          'sku': d['sku'],
          'description': d['description'] ?? '',
          'vat': d['vat'],
          // Hàng một phiên bản: giá gốc của sản phẩm đi cùng giá phiên bản.
          'base_price': d['is_multi_variant'] == true ? d['base_price'] : price,
          'sale_price': d['sale_price'],
          'cost_price': d['cost_price'],
          'thumbnail': d['thumbnail'] ?? '',
          'status': d['status'],
          'variants': variants,
        },
      );
  ref.invalidate(goodsCatalogProvider);
  ref.invalidate(productDetailProvider(id));
}
