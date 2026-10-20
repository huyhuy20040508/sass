import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/api/api_exception.dart';
import '../../../core/format/vn_text.dart';
import '../../auth/auth_controller.dart';
import '../dashboard/dashboard_data.dart' show defaultShopProvider;
import 'goods_data.dart';

class AttrDef {
  const AttrDef({required this.id, required this.name, required this.values});

  final int id;
  final String name;

  /// (id giá trị, tên).
  final List<(int, String)> values;

  String valueName(int id) => values.firstWhere((v) => v.$1 == id, orElse: () => (id, '#$id')).$2;
}

/// Một loại thuế của cửa hàng (/admin/thue) và các mức của nó. Mức âm là mã:
/// -1 = KCT (không chịu thuế), -2 = KKKNT (không kê khai, không nộp).
class TaxType {
  const TaxType({required this.key, required this.name, required this.levels});

  final String key;
  final String name;
  final List<int> levels;
}

String vatName(int v) => switch (v) {
  -1 => 'KCT',
  -2 => 'KKKNT',
  _ => '$v%',
};

/// Danh mục chọn của form thêm hàng. Mục nào không có quyền đọc thì rỗng — ô đó ẩn
/// hoặc dùng mặc định, form vẫn dùng được.
class FormSources {
  const FormSources({
    this.units = const [],
    this.attrs = const [],
    this.taxes = defaultTaxes,
    this.autoSku = true,
    this.skuPrefix = '',
    this.locations = const [],
  });

  final List<(int, String)> units;
  final List<AttrDef> attrs;
  final List<TaxType> taxes;

  /// Quy tắc mã hàng hoá đang bật: để trống SKU thì API tự sinh.
  final bool autoSku;
  final String skuPrefix;
  final List<(int, String)> locations;

  static const defaultTaxes = [
    TaxType(key: 'mac-dinh', name: 'Thuế mặc định', levels: [0, 8, 10]),
  ];
}

Duration? _noRetry(int _, Object _) => null;

final productFormSourcesProvider = FutureProvider.autoDispose<FormSources>((ref) async {
  final api = ref.read(apiClientProvider);
  Future<List<Map>> list(String path) async {
    try {
      return (await api.get<List<dynamic>>(path, query: {'page_size': '200'})).whereType<Map>().toList();
    } on ApiException {
      return const [];
    }
  }

  Future<Map> rules() async {
    try {
      return await api.get<Map<String, dynamic>>('/admin/quy-tac-ma');
    } on ApiException {
      return const {};
    }
  }

  final (units, attrs, taxes, locs, rule) = await (
    list('/admin/don-vi-tinh'),
    list('/admin/thuoc-tinh'),
    list('/admin/thue'),
    list('/admin/vi-tri'),
    rules(),
  ).wait;
  final hh = ((rule['quy_tac'] as List?) ?? const []).whereType<Map>().where((r) => r['doc_type'] == 'hang-hoa');
  final t = [
    for (final x in taxes)
      if (x['is_active'] != false && ((x['muc'] as List?) ?? const []).isNotEmpty)
        TaxType(
          key: '${x['loai']}',
          name: '${x['ten']}',
          levels: [for (final m in x['muc'] as List) (m as num).toInt()],
        ),
  ];
  return FormSources(
    units: [
      for (final u in units)
        if (u['is_active'] != false) ((u['id'] as num).toInt(), '${u['name'] ?? ''}'),
    ],
    attrs: [
      for (final a in attrs)
        if (a['is_active'] != false && ((a['values'] as List?) ?? const []).isNotEmpty)
          AttrDef(
            id: (a['id'] as num).toInt(),
            name: '${a['name']}',
            values: [
              for (final v in (a['values'] as List).whereType<Map>()) ((v['id'] as num).toInt(), '${v['name']}'),
            ],
          ),
    ],
    taxes: t.isEmpty ? FormSources.defaultTaxes : t,
    // Không đọc được quy tắc (thiếu quyền) thì coi như đang bật: để API tự quyết và báo lỗi tại ô SKU nếu cần.
    autoSku: rule.isEmpty || hh.any((r) => r['is_active'] == true),
    skuPrefix: '${hh.firstOrNull?['prefix'] ?? ''}',
    locations: [for (final l in locs) ((l['id'] as num).toInt(), '${l['name'] ?? l['ten'] ?? ''}')],
  );
}, retry: _noRetry);

/// Nhóm chọn được: chỉ nhóm NHỎ NHẤT (không có nhóm con) — đúng luật form PC.
List<GoodsCategory> leafCategories(List<GoodsCategory> all) {
  final active = [
    for (final c in all)
      if (c.isActive) c,
  ];
  return [
    for (final c in active)
      if (!active.any((x) => x.parentId == c.id)) c,
  ];
}

/// Đường dẫn nhóm cha của [c]: ["Hàng bán", "Thiết bị điện tử"].
List<String> categoryParents(GoodsCategory c, List<GoodsCategory> all) {
  final out = <String>[];
  var cur = c;
  for (var i = 0; i < 5 && cur.parentId != null; i++) {
    final p = all.where((x) => x.id == cur.parentId).firstOrNull;
    if (p == null) break;
    out.insert(0, p.name);
    cur = p;
  }
  return out;
}

/// Đường dẫn sản phẩm từ tên — cùng kiểu slugify của web_Shop: bỏ dấu, gạch nối.
String slugify(String s) => foldVn(s).replaceAll(RegExp(r'[^a-z0-9]+'), '-').replaceAll(RegExp(r'^-+|-+$'), '');

// ---------- Phiên bản ----------

/// Một thuộc tính đã chọn trong form: các giá trị dùng, giá trị mặc định, giá cộng thêm.
class AttrPick {
  AttrPick(this.attr);

  final AttrDef attr;
  final List<int> values = [];
  int? def;
  final Map<int, num> extra = {};

  void toggle(int v) {
    if (values.remove(v)) {
      extra.remove(v);
      if (def == v) def = values.firstOrNull;
    } else {
      values.add(v);
      def ??= v;
    }
  }
}

/// Một tổ hợp giá trị (một phiên bản).
class Combo {
  const Combo(this.parts);

  /// (thuộc tính, id giá trị, giá cộng thêm, là giá trị mặc định).
  final List<(AttrDef, int, num, bool)> parts;

  String get key => parts.map((p) => p.$2).join('-');
  String get name => parts.map((p) => p.$1.valueName(p.$2)).join(' / ');

  /// Phiên bản mặc định = tổ hợp toàn giá trị mặc định (luật PC).
  bool get isDefault => parts.every((p) => p.$4);
  num get extra => parts.fold<num>(0, (a, p) => a + p.$3);
}

/// Mọi tổ hợp của các thuộc tính đã có giá trị, theo thứ tự chọn.
List<Combo> buildCombos(List<AttrPick> picks) {
  final sets = [
    for (final p in picks)
      if (p.values.isNotEmpty) [for (final v in p.values) (p.attr, v, p.extra[v] ?? 0, p.def == v)],
  ];
  if (sets.isEmpty) return const [];
  var acc = <List<(AttrDef, int, num, bool)>>[[]];
  for (final set in sets) {
    acc = [
      for (final c in acc)
        for (final s in set) [...c, s],
    ];
  }
  return [for (final c in acc) Combo(c)];
}

/// Giá trị riêng của từng phiên bản người dùng đã sửa.
class ComboState {
  ComboState(this.price);

  num price;

  /// Đã tự sửa giá → đổi giá gốc / giá cộng thêm không ghi đè nữa (bấm "Tính lại giá" để bỏ).
  bool edited = false;
  bool on = true;
  String barcode = '';
  String image = '';
  String sku = '';
  num? cost;
  bool open = false;

  /// Phiên bản đã có (khi sửa): gửi kèm id để API cập nhật đúng dòng — tồn kho
  /// gắn theo id phiên bản, mất id là thành phiên bản mới tồn 0.
  int? variantId;
  bool active = true;
  num? weight;

  /// Tên + cờ mặc định đang lưu của phiên bản cũ — giữ nguyên khi người dùng không đổi.
  String? name;
  bool? isDefault;
}

/// Toàn bộ nội dung form, dựng payload POST /admin/products (thêm) hoặc PUT (sửa).
class ProductDraft {
  ProductDraft();

  /// Bản nháp từ chi tiết mặt hàng (GET /admin/products/{id}) — dùng cho form sửa
  /// và cho các lần sửa nhanh phải gửi lại cả mặt hàng.
  factory ProductDraft.fromDetail(
    Map<String, dynamic> d, {
    List<GoodsCategory> categories = const [],
    FormSources src = const FormSources(),
  }) {
    num? n(Object? v) => v as num?;
    final x = ProductDraft()
      ..editId = (d['id'] as num).toInt()
      ..statusOrig = '${d['status'] ?? 'active'}'
      ..name = '${d['name'] ?? ''}'
      ..price = n(d['base_price']) ?? 0
      ..cost = n(d['cost_price'])
      ..sale = n(d['sale_price'])
      ..sku = '${d['sku'] ?? ''}'
      ..vat = (n(d['vat']) ?? 0).toInt()
      ..vatTouched = true
      ..active = d['status'] == 'active'
      ..deduct = d['is_stock_deducted'] != false
      ..serial = d['is_serial'] == true
      ..label = d['print_label'] != false
      ..description = '${d['description'] ?? ''}';
    final catId = (n(d['category_id']) ?? n((d['category'] as Map?)?['id']))?.toInt();
    final cat = d['category'] as Map?;
    x.category =
        categories.where((c) => c.id == catId).firstOrNull ??
        (catId == null
            ? null
            : GoodsCategory(id: catId, name: '${cat?['name'] ?? ''}', parentId: (cat?['parent_id'] as num?)?.toInt()));
    String unitName(int id) => src.units.where((u) => u.$1 == id).firstOrNull?.$2 ?? '#$id';
    final unit = d['unit'] as Map?, unitId = (n(d['unit_id']) ?? n(unit?['id']))?.toInt();
    if (unitId != null) x.unit = (unitId, '${unit?['name'] ?? unitName(unitId)}');
    x.convsWereNull = d['unit_conversions'] == null;
    for (final c in ((d['unit_conversions'] as List?) ?? const []).whereType<Map>()) {
      final id = (c['unit_id'] as num).toInt();
      x.convs.add(((id, unitName(id)), (c['quantity'] as num).toInt()));
    }
    for (final s in ((d['shops'] as List?) ?? const []).whereType<Map>()) {
      x.shopIds.add((s['id'] as num).toInt());
    }
    final loc = d['location'] as Map?;
    if (loc != null) x.location = ((loc['id'] as num).toInt(), '${loc['name'] ?? ''}');
    for (final t in ((d['tags'] as List?) ?? const [])) {
      x.tags.add(t is Map ? '${t['name']}' : '$t');
    }
    final imgs = ((d['images'] as List?) ?? const []).whereType<Map>().toList()
      ..sort((a, b) => ((a['sort_order'] as num?) ?? 0).compareTo((b['sort_order'] as num?) ?? 0));
    x.images.addAll([for (final i in imgs) '${i['url']}']);
    final thumb = '${d['thumbnail'] ?? ''}';
    if (x.images.isEmpty && thumb.isNotEmpty) x.images.add(thumb);

    final vs = ((d['variants'] as List?) ?? const []).whereType<Map>().toList();
    final def = vs.where((v) => v['is_default'] == true).firstOrNull ?? vs.firstOrNull;
    x.barcode = '${def?['barcode'] ?? ''}';
    x.weight = n(def?['weight_gram']) ?? 0;
    x.multiOrig = d['is_multi_variant'] == true;
    x.multi = vs.any((v) => ((v['attributes'] as List?) ?? const []).isNotEmpty);
    x.multiStart = x.multi;
    // Nhiều phiên bản mà không gắn thuộc tính (hàng nhập từ file…): form không dựng lại được
    // tổ hợp — giữ nguyên các dòng đang có khi lưu, kẻo API xoá mất phiên bản.
    if (!x.multi && vs.length > 1) {
      x.rawVariants = vs;
      return x;
    }
    if (!x.multi) {
      x.singleVariant = def;
      // Giá vốn của hàng một phiên bản nằm ở phiên bản (sửa nhanh ghi vào đó); trống thì theo sản phẩm.
      x.cost = n(def?['cost_price']) ?? x.cost;
      return x;
    }
    // Dựng lại thuộc tính theo thứ tự xuất hiện; danh sách giá trị lấy đủ từ /admin/thuoc-tinh
    // để còn bấm thêm giá trị mới.
    for (final v in vs) {
      for (final a in ((v['attributes'] as List?) ?? const []).whereType<Map>()) {
        final aid = (a['attribute_id'] as num).toInt(), vid = (a['value_id'] as num).toInt();
        var p = x.picks.where((p) => p.attr.id == aid).firstOrNull;
        if (p == null) {
          final known = src.attrs.where((t) => t.id == aid).firstOrNull;
          p = AttrPick(
            known ?? AttrDef(id: aid, name: '${(a['attribute'] as Map?)?['name'] ?? '#$aid'}', values: const []),
          );
          x.picks.add(p);
        }
        if (!p.attr.values.any((e) => e.$1 == vid)) {
          // Giá trị đã tắt / chưa có trong danh mục: vẫn phải hiện để giữ phiên bản.
          final at = p.attr;
          final i = x.picks.indexOf(p);
          x.picks[i] =
              AttrPick(
                  AttrDef(
                    id: at.id,
                    name: at.name,
                    values: [...at.values, (vid, '${(a['value'] as Map?)?['name'] ?? '#$vid'}')],
                  ),
                )
                ..values.addAll(p.values)
                ..def = p.def
                ..extra.addAll(p.extra);
          p = x.picks[i];
        }
        if (!p.values.contains(vid)) p.values.add(vid);
        if (v == def) p.def = vid;
      }
    }
    for (final p in x.picks) {
      p.def ??= p.values.firstOrNull;
    }
    for (final v in vs) {
      final ids = {
        for (final a in ((v['attributes'] as List?) ?? const []).whereType<Map>())
          (a['attribute_id'] as num).toInt(): (a['value_id'] as num).toInt(),
      };
      final key = [for (final p in x.picks) ?ids[p.attr.id]].join('-');
      x.states[key] = ComboState(n(v['price']) ?? 0)
        ..edited = true
        ..variantId = (v['id'] as num).toInt()
        ..barcode = '${v['barcode'] ?? ''}'
        ..image = '${v['image'] ?? ''}'
        ..sku = '${v['sku'] ?? ''}'
        ..cost = n(v['cost_price'])
        ..weight = n(v['weight_gram'])
        ..active = v['is_active'] != false
        ..name = '${v['name'] ?? ''}'
        ..isDefault = v['is_default'] == true;
    }
    // Tổ hợp chưa từng là phiên bản: để tắt sẵn, người dùng tự bật thêm nếu cần.
    for (final c in x.combos) {
      x.states.putIfAbsent(c.key, () => ComboState(x.price ?? 0)..on = false);
    }
    return x;
  }

  /// id mặt hàng đang sửa (null = thêm mới).
  int? editId;

  /// Trạng thái lúc mở form: tắt "Đang bán" thì giữ mức cũ (tạm ẩn / ngừng kinh doanh).
  String statusOrig = 'active';

  /// Dòng phiên bản duy nhất của hàng một phiên bản (khi sửa).
  Map? singleVariant;

  /// Phiên bản giữ nguyên khi lưu (nhiều dòng nhưng không có thuộc tính để dựng lại).
  List<Map>? rawVariants;

  /// Cờ `is_multi_variant` đang lưu; chỉ đổi khi người dùng bật / tắt công tắc.
  bool multiOrig = false;
  bool multiStart = false;

  /// Đã đổi ô cân nặng → áp cho mọi phiên bản; chưa đổi thì giữ cân nặng riêng từng dòng.
  bool weightTouched = false;

  /// Đã đổi giá trị mặc định → tính lại cờ mặc định; chưa đổi thì giữ cờ đang lưu.
  bool defaultTouched = false;

  /// Đã thêm / bỏ ảnh. Khi sửa mà chưa đụng ảnh thì không gửi `images` (API giữ nguyên).
  bool imagesTouched = false;

  /// Khi sửa: mặt hàng chưa từng khai quy đổi → không gửi khoá (giữ nguyên thay vì ghi []).
  bool convsWereNull = false;
  String name = '';
  GoodsCategory? category;

  /// null = để trống (khác 0 — giá 0 là hợp lệ như PC).
  num? price;
  num? cost;
  num? sale;
  num weight = 0;
  String sku = '';
  String barcode = '';
  (int, String)? unit;

  /// Quy đổi: 1 `đơn vị` = `số lượng` đơn vị chính.
  final List<((int, String)?, int)> convs = [];
  int vat = 0;
  bool vatTouched = false;
  bool active = true;
  bool deduct = true;
  bool serial = false;
  bool label = true;
  final Set<int> shopIds = {};
  (int, String)? location;
  final List<String> tags = [];
  String description = '';

  /// Ảnh đã tải lên (url), ảnh đầu là ảnh đại diện.
  final List<String> images = [];
  bool multi = false;
  final List<AttrPick> picks = [];
  final Map<String, ComboState> states = {};

  List<Combo> get combos => buildCombos(picks);

  /// Phiên bản đang bật, đồng bộ trạng thái với các tổ hợp hiện có.
  List<(Combo, ComboState)> get live {
    final out = <(Combo, ComboState)>[];
    for (final c in combos) {
      final st = states.putIfAbsent(c.key, () => ComboState((price ?? 0) + c.extra));
      if (!st.edited) st.price = (price ?? 0) + c.extra;
      if (st.on) out.add((c, st));
    }
    return out;
  }

  Map<String, dynamic> payload() {
    final variants = rawVariants != null && !multi
        ? [
            for (final v in rawVariants!)
              {
                'id': v['id'],
                'sku': v['sku'] ?? '',
                'barcode': v['barcode'] ?? '',
                'name': v['name'] ?? '',
                'attributes': const [],
                'is_default': v['is_default'],
                'pos': v['pos'],
                'price': v['price'],
                'cost_price': v['cost_price'],
                'weight_gram': weightTouched ? weight : (v['weight_gram'] ?? 0),
                'image': v['image'] ?? '',
                'is_active': v['is_active'],
              },
          ]
        : multi
        ? [
            for (final (i, (c, st)) in live.indexed)
              {
                'id': st.variantId ?? 0,
                'is_active': st.active,
                'is_default': defaultTouched || st.isDefault == null ? c.isDefault : st.isDefault,
                'name': st.name?.isNotEmpty == true ? st.name : c.name,
                'sku': st.sku,
                // Mã vạch chung ở "Mã hàng" gắn cho phiên bản mặc định (như PC).
                'barcode': st.barcode.isNotEmpty ? st.barcode : (c.isDefault ? barcode : ''),
                'attributes': [
                  for (final p in c.parts) {'attribute_id': p.$1.id, 'value_id': p.$2},
                ],
                'price': st.price,
                // Phiên bản đã có giữ nguyên giá vốn riêng (kể cả trống = theo sản phẩm); phiên bản mới lấy theo sản phẩm.
                'cost_price': st.variantId != null ? st.cost : (st.cost ?? cost),
                'weight_gram': weightTouched ? weight : (st.weight ?? weight),
                'image': st.image,
                'pos': i,
              },
          ]
        : [
            {
              'id': (singleVariant?['id'] as num?)?.toInt() ?? 0,
              // Sửa hàng một phiên bản: giữ mã phiên bản cũ (đã in tem), không sinh lại theo SKU.
              'sku': singleVariant == null ? sku.trim() : '${singleVariant!['sku'] ?? ''}',
              'barcode': barcode.trim(),
              'attributes': const [],
              'price': price ?? 0,
              // Hàng một phiên bản: giá vốn nằm ở phiên bản (ô "Giá vốn" sửa nhanh ghi vào đây).
              'cost_price': cost,
              'weight_gram': weight,
              'is_default': true,
              if (singleVariant != null) 'is_active': singleVariant!['is_active'] != false,
            },
          ];
    return {
      'category_id': category?.id,
      'name': name.trim(),
      'slug': slugify(name).isEmpty ? 'san-pham' : slugify(name),
      'sku': sku.trim(),
      'description': description.trim(),
      'unit_id': ?unit?.$1,
      if (!(editId != null && convsWereNull && convs.isEmpty))
        'unit_conversions': [
          for (final (u, q) in convs)
            if (u != null) {'unit_id': u.$1, 'quantity': q},
        ],
      'vat': vat,
      'base_price': price ?? 0,
      'sale_price': sale,
      'cost_price': cost,
      'shop_ids': shopIds.toList(),
      'tags': tags,
      'location_id': ?location?.$1,
      'is_multi_variant': editId != null && multi == multiStart ? multiOrig || multi : multi,
      'print_label': label,
      'is_stock_deducted': deduct,
      'is_serial': serial,
      'status': active
          ? 'active'
          : statusOrig == 'active'
          ? 'hidden'
          : statusOrig,
      'thumbnail': images.firstOrNull ?? '',
      if (editId == null || imagesTouched)
        'images': [
          for (final (i, u) in images.indexed) {'id': 0, 'url': u, 'sort_order': i, 'is_primary': i == 0},
        ],
      'variants': variants,
    };
  }
}

/// Tải ảnh sản phẩm (POST /admin/products/anh, tối đa 5MB) → địa chỉ để gán vào mặt hàng.
Future<String> uploadProductImage(WidgetRef ref, List<int> bytes, String filename) async {
  final res = await ref
      .read(apiClientProvider)
      .post<Map<String, dynamic>>(
        '/admin/products/anh',
        body: FormData.fromMap({'file': MultipartFile.fromBytes(bytes, filename: filename)}),
      );
  return '${res['url']}';
}

/// Tạo sản phẩm (POST /admin/products) → mặt hàng vừa tạo (kèm SKU sinh ra + phiên bản).
Future<Map<String, dynamic>> createProduct(WidgetRef ref, Map<String, dynamic> body) async {
  final api = ref.read(apiClientProvider);
  Map<String, dynamic> d;
  try {
    d = await api.post<Map<String, dynamic>>('/admin/products', body: body);
  } on ApiException catch (e) {
    // Tên khác nhau nhưng bỏ dấu ra trùng ("Áo" / "Ao") thì trùng đường dẫn — thêm đuôi rồi thử lại một lần.
    if (e.statusCode != 409 || !e.message.contains('slug')) rethrow;
    final tail = DateTime.now().millisecondsSinceEpoch.toRadixString(36);
    d = await api.post<Map<String, dynamic>>('/admin/products', body: {...body, 'slug': '${body['slug']}-$tail'});
  }
  ref.invalidate(goodsCatalogProvider);
  return d;
}

/// Nhập tồn ban đầu cho các phiên bản vừa tạo: mỗi dòng một lần nhập tay (delta) vào kho
/// chi nhánh đang làm việc — API chỉnh kho bắt buộc biết chi nhánh qua X-Chi-Nhanh.
Future<void> addOpeningStock(WidgetRef ref, Map<int, int> qtyByVariant, {num? unitCost}) async {
  final api = ref.read(apiClientProvider);
  final shop = ref.read(defaultShopProvider).value;
  for (final MapEntry(key: vid, value: q) in qtyByVariant.entries) {
    if (q <= 0) continue;
    await api.put<Object?>(
      '/admin/inventory/$vid',
      headers: {'X-Chi-Nhanh': ?shop?.toString()},
      body: {
        'mode': 'delta',
        'quantity': q,
        'type': 'import',
        'unit_cost': ?unitCost,
        'note': 'Tồn ban đầu khi thêm sản phẩm trên app',
      },
    );
  }
  ref.invalidate(goodsCatalogProvider);
}

/// Lưu sửa mặt hàng (PUT /admin/products/{id}) — gửi lại cả mặt hàng, phiên bản kèm id.
Future<void> updateProduct(WidgetRef ref, int id, Map<String, dynamic> body) async {
  await ref.read(apiClientProvider).put<Object?>('/admin/products/$id', body: body);
  ref.invalidate(goodsCatalogProvider);
  ref.invalidate(productDetailProvider(id));
}

/// Sửa nhanh giá bán = giá gốc của mặt hàng (như ô Giá bán trên PC). Hàng một phiên bản
/// thì giá phiên bản đi theo; hàng nhiều phiên bản giữ giá riêng từng phiên bản.
Future<void> setBasePrice(WidgetRef ref, Map<String, dynamic> d, num price) async {
  final x = ProductDraft.fromDetail(d)..price = price;
  await updateProduct(ref, x.editId!, x.payload());
}

/// Xoá mặt hàng (DELETE /admin/products/{id}, xoá mềm — sổ kho, đơn cũ vẫn giữ).
Future<void> deleteProduct(WidgetRef ref, int id) async {
  await ref.read(apiClientProvider).delete<Object?>('/admin/products/$id');
  ref.invalidate(goodsCatalogProvider);
}
