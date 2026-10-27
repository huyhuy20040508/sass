import 'dart:math' as math;

import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/api/api_client.dart';
import '../../auth/auth_controller.dart';
import '../goods/product_form_data.dart' show productFormSourcesProvider;

/// Một loại thuế cố định (mặc định, tiêu thụ đặc biệt, đơn mua, đơn bán) — API không cho
/// thêm, xoá; chỉ bật/tắt và chọn mức nào được hiện trong bộ [choices] cho phép.
class TaxKind {
  const TaxKind({
    required this.id,
    required this.kind,
    required this.name,
    required this.desc,
    required this.levels,
    required this.choices,
    required this.active,
  });

  factory TaxKind.fromJson(Map j) => TaxKind(
    id: (j['id'] as num).toInt(),
    kind: '${j['loai'] ?? ''}',
    name: '${j['ten'] ?? ''}',
    desc: '${j['mo_ta'] ?? ''}',
    levels: [for (final v in (j['muc'] as List?) ?? const []) (v as num).toInt()],
    choices: [
      for (final c in ((j['chon_duoc'] as List?) ?? const []).whereType<Map>())
        ((c['gia_tri'] as num).toInt(), '${c['nhan'] ?? ''}'),
    ],
    active: j['is_active'] != false,
  );

  final int id;

  /// mac-dinh, tieu-thu-dac-biet, mua-hang, ban-hang.
  final String kind;
  final String name;
  final String desc;

  /// Mức đang hiện ở màn nghiệp vụ.
  final List<int> levels;

  /// Bộ mức API cho phép chọn: (giá trị, nhãn) — -1 = KCT, -2 = KKKNT.
  final List<(int, String)> choices;
  final bool active;

  /// Chỉ loại "mặc định" là mức khai cho nhóm hàng và mặt hàng.
  bool get isDefault => kind == 'mac-dinh';

  String label(int v) => choices.where((c) => c.$1 == v).firstOrNull?.$2 ?? '$v%';

  /// Xếp theo thứ tự danh mục của API, không theo lượt bấm.
  List<int> ordered(Iterable<int> picked) => [
    for (final c in choices)
      if (picked.contains(c.$1)) c.$1,
  ];
}

/// KCT / KKKNT là mã trên hoá đơn điện tử, không phải phần trăm.
bool isTaxCode(int v) => v < 0;

class TaxBook {
  const TaxBook(this.kinds, {this.productVat = const {}, this.categoryVat = const {}});

  final List<TaxKind> kinds;

  /// Số sản phẩm / nhóm hàng đang khai từng mức (chỉ dùng cho loại mặc định).
  final Map<int, int> productVat;
  final Map<int, int> categoryVat;

  TaxKind? byId(int id) => kinds.where((k) => k.id == id).firstOrNull;

  /// "3 sp · 1 nhóm"; null = không ai khai hoặc không phải loại mặc định.
  String? usage(TaxKind k, int v, {bool long = false}) {
    if (!k.isDefault) return null;
    final sp = productVat[v] ?? 0, nh = categoryVat[v] ?? 0;
    if (sp == 0 && nh == 0) return null;
    return [
      if (sp > 0) long ? '$sp sản phẩm' : '$sp sp',
      if (nh > 0) long ? '$nh nhóm hàng' : '$nh nhóm',
    ].join(long ? ', ' : ' · ');
  }
}

Duration? _noRetry(int _, Object _) => null;

final taxBookProvider = FutureProvider.autoDispose<TaxBook>((ref) async {
  final api = ref.read(apiClientProvider);
  Future<Map<int, int>> products() async {
    Future<ApiPage> page(int p) => api.getPage(
      '/products',
      query: {'all': 'true', 'slim': 'true', 'shop_id': '0', 'page': '$p', 'page_size': '100'},
    );
    final first = await page(1);
    final rest = await Future.wait([for (var p = 2; p <= math.min(first.totalPages, 50); p++) page(p)]);
    final out = <int, int>{};
    for (final pg in [first, ...rest]) {
      for (final m in pg.data.whereType<Map>()) {
        final v = ((m['vat'] as num?) ?? 0).toInt();
        out[v] = (out[v] ?? 0) + 1;
      }
    }
    return out;
  }

  Future<Map<int, int>> categories() async {
    final res = await api.getRaw('/categories', query: {'all': 'true'});
    final out = <int, int>{};
    for (final c in ((res['data'] as List?) ?? const []).whereType<Map>()) {
      final v = ((c['vat'] as num?) ?? 0).toInt();
      out[v] = (out[v] ?? 0) + 1;
    }
    return out;
  }

  final (list, sp, nh) = await (api.get<List<dynamic>>('/admin/thue'), products(), categories()).wait;
  return TaxBook([for (final t in list.whereType<Map>()) TaxKind.fromJson(t)], productVat: sp, categoryVat: nh);
}, retry: _noRetry);

class TaxRepo {
  TaxRepo(this._ref);

  final Ref _ref;
  ApiClient get _api => _ref.read(apiClientProvider);

  Future<void> setLevels(int id, List<int> levels) => _api.put<Object?>('/admin/thue/$id', body: {'muc': levels});

  Future<void> setActive(int id, bool on) => _api.put<Object?>('/admin/thue/$id/trang-thai', body: {'is_active': on});

  void refresh() {
    _ref.invalidate(taxBookProvider);
    // Ô chọn thuế của form thêm / sửa hàng ăn theo màn này.
    _ref.invalidate(productFormSourcesProvider);
  }
}

final taxRepoProvider = Provider<TaxRepo>(TaxRepo.new);
