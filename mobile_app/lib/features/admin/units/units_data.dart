import 'dart:math' as math;

import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/api/api_client.dart';
import '../../../core/format/vn_text.dart';
import '../../auth/auth_controller.dart';
import '../goods/product_form_data.dart' show productFormSourcesProvider;

/// Mã đơn vị: chữ không dấu + số (binding alphanum của API).
final unitCodeRe = RegExp(r'^[A-Za-z0-9]*$');

/// Đơn vị hay dùng — gợi ý trong hộp Thêm (bỏ những cái cửa hàng đã có).
const commonUnits = [
  'Cái', 'Chiếc', 'Hộp', 'Thùng', 'Chai', 'Lon', 'Gói', 'Kg', 'Gam', 'Lít', //
  'Bộ', 'Đôi', 'Vỉ', 'Lốc', 'Cuộn', 'Mét', 'Túi', 'Bao', 'Ly', 'Phần',
];

class Unit {
  const Unit({required this.id, required this.code, required this.name, required this.active});

  factory Unit.fromJson(Map j) => Unit(
    id: (j['id'] as num).toInt(),
    code: '${j['code'] ?? ''}',
    name: '${j['name'] ?? ''}',
    active: j['is_active'] != false,
  );

  final int id;
  final String code;
  final String name;
  final bool active;
}

/// Danh sách đơn vị + mức dùng đếm từ sản phẩm.
class UnitBook {
  const UnitBook(this.units, {this.main = const {}, this.conv = const {}});

  final List<Unit> units;

  /// Số sản phẩm lấy đơn vị này làm đơn vị chính (unit_id).
  final Map<int, int> main;

  /// Số sản phẩm có đơn vị này trong bảng quy đổi (unit_conversions).
  final Map<int, int> conv;

  Unit? byId(int id) => units.where((u) => u.id == id).firstOrNull;
  int mainOf(Unit u) => main[u.id] ?? 0;
  int convOf(Unit u) => conv[u.id] ?? 0;
  int used(Unit u) => mainOf(u) + convOf(u);

  /// "chính 3 sp · quy đổi 2 sp" (dòng danh sách) hoặc bản đủ chữ (ngăn thao tác); null = chưa dùng.
  String? useText(Unit u, {bool short = false}) {
    final a = mainOf(u), b = convOf(u);
    if (a == 0 && b == 0) return null;
    return [
      if (a > 0) short ? 'chính $a sp' : 'đơn vị chính của $a sp',
      if (b > 0) short ? 'quy đổi $b sp' : 'quy đổi ở $b sp',
    ].join(' · ');
  }
}

String normUnit(String s) => s.trim().replaceAll(RegExp(r'\s+'), ' ').toLowerCase();

bool unitMatches(Unit u, String q) {
  final f = foldVn(q.trim());
  return f.isEmpty || foldVn('${u.name} ${u.code}').contains(f);
}

/// Gợi ý đơn vị hay dùng mà cửa hàng CHƯA có; đang gõ thì lọc theo chữ đầu.
List<String> unitSuggestions(List<Unit> have, String typed) {
  final names = {for (final u in have) normUnit(u.name)};
  final q = normUnit(typed);
  return [
    for (final n in commonUnits)
      if (!names.contains(normUnit(n)) && (q.isEmpty || normUnit(n).startsWith(q)) && normUnit(n) != q) n,
  ].take(10).toList();
}

Duration? _noRetry(int _, Object _) => null;

final unitBookProvider = FutureProvider.autoDispose<UnitBook>((ref) async {
  final api = ref.read(apiClientProvider);
  // API xoá mềm và không kiểm tra hàng đang dùng — tự đếm cả đơn vị chính lẫn quy đổi.
  Future<(Map<int, int>, Map<int, int>)> usage() async {
    Future<ApiPage> page(int p) =>
        api.getPage('/products', query: {'all': 'true', 'shop_id': '0', 'page': '$p', 'page_size': '100'});
    final first = await page(1);
    final rest = await Future.wait([for (var p = 2; p <= math.min(first.totalPages, 30); p++) page(p)]);
    final main = <int, int>{}, conv = <int, int>{};
    for (final pg in [first, ...rest]) {
      for (final prod in pg.data.whereType<Map>()) {
        if ((prod['unit_id'] as num?)?.toInt() case final u? when u > 0) main[u] = (main[u] ?? 0) + 1;
        final cs = {
          for (final c in ((prod['unit_conversions'] as List?) ?? const []).whereType<Map>())
            ?(c['unit_id'] as num?)?.toInt(),
        };
        for (final u in cs) {
          conv[u] = (conv[u] ?? 0) + 1;
        }
      }
    }
    return (main, conv);
  }

  final (list, (main, conv)) = await (
    api.get<List<dynamic>>('/admin/don-vi-tinh', query: {'page_size': '500'}),
    usage(),
  ).wait;
  return UnitBook([for (final u in list.whereType<Map>()) Unit.fromJson(u)], main: main, conv: conv);
}, retry: _noRetry);

class UnitRepo {
  UnitRepo(this._ref);

  final Ref _ref;
  ApiClient get _api => _ref.read(apiClientProvider);

  /// Mã bỏ trống: thêm mới = API tự đặt (quy tắc mã, không thì DV001…), sửa = giữ mã cũ
  /// (đơn vị đã in lên tem).
  Future<Unit> create({required String code, required String name, required bool active}) async => Unit.fromJson(
    await _api.post<Map<String, dynamic>>(
      '/admin/don-vi-tinh',
      body: {'code': code, 'name': name, 'is_active': active},
    ),
  );

  Future<void> update(int id, {required String code, required String name, required bool active}) =>
      _api.put<Object?>('/admin/don-vi-tinh/$id', body: {'code': code, 'name': name, 'is_active': active});

  Future<void> setActive(int id, bool on) =>
      _api.put<Object?>('/admin/don-vi-tinh/$id/trang-thai', body: {'is_active': on});

  Future<void> delete(int id) => _api.delete<Object?>('/admin/don-vi-tinh/$id');

  void refresh() {
    _ref.invalidate(unitBookProvider);
    // Ô chọn đơn vị của form thêm / sửa hàng.
    _ref.invalidate(productFormSourcesProvider);
  }
}

final unitRepoProvider = Provider<UnitRepo>(UnitRepo.new);
