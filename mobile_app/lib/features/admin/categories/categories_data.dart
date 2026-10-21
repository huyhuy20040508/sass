import 'dart:math' as math;

import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/api/api_client.dart';
import '../../../core/api/api_exception.dart';
import '../../../core/format/vn_text.dart';
import '../../auth/auth_controller.dart';
import '../goods/goods_data.dart';
import '../goods/product_form_data.dart' show productFormSourcesProvider;

/// Hai nhóm gốc cố định của mọi cửa hàng (PC: CategoryController::PROTECTED_SLUGS) —
/// không sửa / xoá / tắt, chỉ để chứa nhóm con.
const rootSlugs = ['hang-ban', 'hang-hoa-khac'];

class Category {
  const Category({
    required this.id,
    required this.name,
    required this.code,
    this.parentId,
    this.vat = 0,
    this.active = true,
    this.sortOrder = 0,
    this.description = '',
    this.image = '',
  });

  factory Category.fromJson(Map j) => Category(
    id: (j['id'] as num).toInt(),
    parentId: (j['parent_id'] as num?)?.toInt(),
    name: '${j['name'] ?? ''}',
    code: '${j['slug'] ?? ''}',
    vat: ((j['vat'] as num?) ?? 0).toInt(),
    active: j['is_active'] != false,
    sortOrder: ((j['sort_order'] as num?) ?? 0).toInt(),
    description: '${j['description'] ?? ''}',
    image: '${j['image'] ?? ''}',
  );

  final int id;
  final int? parentId;
  final String name;

  /// Mã nhóm (cột slug) — API đặt theo quy tắc mã khi tạo.
  final String code;
  final int vat;
  final bool active;
  final int sortOrder;
  final String description;
  final String image;

  bool get locked => rootSlugs.contains(code);

  /// Thân PUT: API ghi đè MỌI trường (mã, mô tả, ảnh, thứ tự, nhóm cha) — thiếu là ghi rỗng.
  Map<String, dynamic> body({String? name, int? vat, bool? active}) => {
    'name': name ?? this.name,
    'slug': code,
    'parent_id': parentId,
    'description': description,
    'vat': vat ?? this.vat,
    'image': image,
    'sort_order': sortOrder,
    'is_active': active ?? this.active,
  };
}

/// Cây nhóm + số sản phẩm đếm theo nhóm (cả cửa hàng, mọi trạng thái).
class CategoryTree {
  CategoryTree(this.all, this.ownCount) {
    for (final c in all) {
      (_kids[c.parentId] ??= []).add(c);
    }
    for (final l in _kids.values) {
      l.sort((a, b) => a.sortOrder != b.sortOrder ? a.sortOrder - b.sortOrder : a.id - b.id);
    }
  }

  final List<Category> all;

  /// Sản phẩm nằm thẳng trong nhóm (chưa cộng nhóm con).
  final Map<int, int> ownCount;
  final _kids = <int?, List<Category>>{};

  Category? byId(int? id) => id == null ? null : all.where((c) => c.id == id).firstOrNull;
  List<Category> kids(int? id) => _kids[id] ?? const [];
  List<Category> get roots => kids(null);

  /// Mọi nhóm bên dưới; `seen` chặn vòng lặp khi dữ liệu hỏng kiểu A → B → A.
  List<int> descendants(int id, [Set<int>? seen]) {
    final s = seen ?? <int>{};
    return [
      for (final k in kids(id))
        if (s.add(k.id)) ...[k.id, ...descendants(k.id, s)],
    ];
  }

  int products(int id) => [id, ...descendants(id)].fold(0, (s, x) => s + (ownCount[x] ?? 0));

  /// Các nhóm cha từ gốc xuống (không gồm chính nó).
  List<Category> pathOf(Category c) {
    final out = <Category>[];
    var x = byId(c.parentId);
    while (x != null && out.length < 20) {
      out.insert(0, x);
      x = byId(x.parentId);
    }
    return out;
  }

  /// Lý do KHÔNG được xoá; null = xoá được. API xoá mềm nên không tự chặn nhóm còn con /
  /// còn hàng — xoá bừa là để lại nhóm con mồ côi và hàng trỏ vào nhóm đã xoá.
  String? whyNotDelete(Category c) {
    if (c.locked) return 'nhóm gốc cố định';
    final k = kids(c.id).length;
    if (k > 0) return 'đang chứa $k nhóm con';
    final p = products(c.id);
    if (p > 0) return 'đang có $p sản phẩm';
    return null;
  }

  /// Tên đã có (không phân biệt hoa thường, gộp khoảng trắng) — trừ [except].
  bool taken(String name, Set<int> except) {
    final n = normName(name);
    return all.any((c) => !except.contains(c.id) && normName(c.name) == n);
  }

  List<Category> search(String q) {
    final f = foldVn(q.trim());
    return [
      for (final c in all)
        if (foldVn('${c.name} ${c.code}').contains(f)) c,
    ];
  }
}

String normName(String s) => s.trim().replaceAll(RegExp(r'\s+'), ' ').toLowerCase();

Duration? _noRetry(int _, Object _) => null;

final categoryTreeProvider = FutureProvider.autoDispose<CategoryTree>((ref) async {
  final api = ref.read(apiClientProvider);
  Future<Map<int, int>> counts() async {
    // Đếm cả cửa hàng (shop_id=0) và mọi trạng thái: chặn xoá phải tính cả hàng đang ẩn.
    Future<ApiPage> page(int p) => api.getPage(
      '/products',
      query: {'all': 'true', 'slim': 'true', 'shop_id': '0', 'page': '$p', 'page_size': '100'},
    );
    final first = await page(1);
    final rest = await Future.wait([for (var p = 2; p <= math.min(first.totalPages, 50); p++) page(p)]);
    final out = <int, int>{};
    for (final pg in [first, ...rest]) {
      for (final m in pg.data.whereType<Map>()) {
        final id = ((m['category'] as Map?)?['id'] ?? m['category_id']) as num?;
        if (id != null) out[id.toInt()] = (out[id.toInt()] ?? 0) + 1;
      }
    }
    return out;
  }

  final (cats, n) = await (api.getRaw('/categories', query: {'all': 'true'}), counts()).wait;
  return CategoryTree([
    for (final c in ((cats['data'] as List?) ?? const []).whereType<Map>()) Category.fromJson(c),
  ], n);
}, retry: _noRetry);

/// Mức VAT chọn được cho nhóm: gộp mọi mức của các loại thuế đang bật (/admin/thue);
/// số dương tăng dần trước, KCT / KKKNT sau.
final categoryVatsProvider = FutureProvider.autoDispose<List<int>>((ref) async {
  final src = await ref.watch(productFormSourcesProvider.future);
  final out = <int>{for (final t in src.taxes) ...t.levels}.toList();
  if (out.isEmpty) out.addAll([0, 5, 8, 10]);
  out.sort((a, b) => (a < 0) != (b < 0) ? (a < 0 ? 1 : -1) : (a < 0 ? b - a : a - b));
  return out;
}, retry: _noRetry);

void _refresh(Ref ref) {
  ref.invalidate(categoryTreeProvider);
  // Danh sách hàng dùng chung bảng nhóm (lọc, tên nhóm trên dòng).
  ref.invalidate(goodsCatalogProvider);
}

/// Thao tác ghi; mọi hàm tải lại cây khi xong.
class CategoryRepo {
  CategoryRepo(this._ref);

  final Ref _ref;
  ApiClient get _api => _ref.read(apiClientProvider);

  Future<Category> create({required String name, required int parentId, required int vat, bool active = true}) async {
    final d = await _api.post<Map<String, dynamic>>(
      '/admin/categories',
      body: {'name': name, 'parent_id': parentId, 'vat': vat, 'is_active': active, 'description': '', 'image': ''},
    );
    return Category.fromJson(d);
  }

  Future<void> update(Category c, {String? name, int? vat, bool? active}) => _api.put<Object?>(
    '/admin/categories/${c.id}',
    body: c.body(name: name, vat: vat, active: active),
  );

  Future<void> delete(int id) => _api.delete<Object?>('/admin/categories/$id');

  void refresh() => _refresh(_ref);
}

final categoryRepoProvider = Provider<CategoryRepo>(CategoryRepo.new);

/// Một dòng nhóm con trong hộp Thêm/Sửa: [id] null = dòng mới gõ.
class KidRow {
  KidRow({this.id, this.code = '', this.name = ''});

  final int? id;
  final String code;
  String name;
}

/// Kết quả lưu hộp Thêm/Sửa.
class SaveResult {
  const SaveResult(this.cat, this.newKids, this.failedKids);

  final Category cat;
  final int newKids;
  final int failedKids;
}

/// Lưu nhóm + các dòng nhóm con (dòng cũ đổi tên, dòng mới tạo dưới nhóm này, nhận VAT
/// của nhóm) — đúng CategoryController::store/update + saveChildren của PC. Lỗi ở nhóm
/// chính thì ném ra (giữ hộp mở); lỗi ở nhóm con thì đếm để báo.
Future<SaveResult> saveCategory(
  CategoryRepo repo, {
  Category? editing,
  required int parentId,
  required String name,
  required int vat,
  required bool active,
  required List<KidRow> kids,
  required CategoryTree tree,
}) async {
  final Category cat;
  if (editing != null) {
    await repo.update(editing, name: name, vat: vat, active: active);
    cat = Category(
      id: editing.id,
      parentId: editing.parentId,
      name: name,
      code: editing.code,
      vat: vat,
      active: active,
      sortOrder: editing.sortOrder,
      description: editing.description,
      image: editing.image,
    );
  } else {
    cat = await repo.create(name: name, parentId: parentId, vat: vat, active: active);
  }
  var added = 0, failed = 0;
  for (final k in kids) {
    final n = k.name.trim();
    if (n.isEmpty) continue;
    try {
      if (k.id != null) {
        final old = tree.byId(k.id);
        if (old != null && old.name != n) await repo.update(old, name: n);
      } else {
        await repo.create(name: n, parentId: cat.id, vat: vat);
        added++;
      }
    } on ApiException {
      failed++;
    }
  }
  repo.refresh();
  return SaveResult(cat, added, failed);
}
