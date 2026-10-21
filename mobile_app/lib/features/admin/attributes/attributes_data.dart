import 'dart:math' as math;

import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/api/api_client.dart';
import '../../../core/format/vn_text.dart';
import '../../auth/auth_controller.dart';
import '../goods/product_form_data.dart' show productFormSourcesProvider;

/// Tối đa giá trị một thuộc tính (dto.ThuocTinhRequest: values max=100).
const maxAttrValues = 100;

/// Mã thuộc tính / giá trị: chữ không dấu + số (binding alphanum của API).
final attrCodeRe = RegExp(r'^[A-Za-z0-9]*$');

class AttrValue {
  const AttrValue({this.id, required this.code, required this.name});

  final int? id;
  final String code;
  final String name;
}

class Attr {
  const Attr({required this.id, required this.code, required this.name, required this.active, required this.values});

  factory Attr.fromJson(Map j) => Attr(
    id: (j['id'] as num).toInt(),
    code: '${j['code'] ?? ''}',
    name: '${j['name'] ?? ''}',
    active: j['is_active'] != false,
    values: [
      for (final v in ((j['values'] as List?) ?? const []).whereType<Map>())
        AttrValue(id: (v['id'] as num?)?.toInt(), code: '${v['code'] ?? ''}', name: '${v['name'] ?? ''}'),
    ],
  );

  final int id;
  final String code;
  final String name;
  final bool active;
  final List<AttrValue> values;
}

/// Danh sách thuộc tính + mức dùng đếm từ phiên bản sản phẩm.
class AttrBook {
  const AttrBook(this.attrs, {this.usedBy = const {}, this.valueUse = const {}});

  final List<Attr> attrs;

  /// Tên các sản phẩm có phiên bản mang thuộc tính này.
  final Map<int, List<String>> usedBy;

  /// Số sản phẩm có phiên bản mang giá trị này.
  final Map<int, int> valueUse;

  Attr? byId(int id) => attrs.where((a) => a.id == id).firstOrNull;
  List<String> used(Attr a) => usedBy[a.id] ?? const [];
  int valueUsed(int? valueId) => valueId == null ? 0 : valueUse[valueId] ?? 0;
}

String normAttr(String s) => s.trim().replaceAll(RegExp(r'\s+'), ' ').toLowerCase();

/// Khớp tên / mã thuộc tính hoặc tên / mã một giá trị (bỏ dấu).
bool attrMatches(Attr a, String q) {
  final f = foldVn(q.trim());
  if (f.isEmpty) return true;
  return foldVn('${a.name} ${a.code}').contains(f) || a.values.any((v) => foldVn('${v.name} ${v.code}').contains(f));
}

/// "S, M, L" hoặc mỗi dòng một giá trị → danh sách tên, bỏ trống và trùng.
List<String> splitBulk(String text) {
  final seen = <String>{};
  return [
    for (final s in text.split(RegExp(r'[\n,;]+')).map((x) => x.trim()))
      if (s.isNotEmpty && seen.add(normAttr(s))) s,
  ];
}

Duration? _noRetry(int _, Object _) => null;

final attrBookProvider = FutureProvider.autoDispose<AttrBook>((ref) async {
  final api = ref.read(apiClientProvider);
  // `in_use` của API luôn false (DangDuocDung chưa dựng) — tự đếm từ phiên bản sản phẩm,
  // không thì cho xoá cả thuộc tính đang có hàng dùng.
  Future<(Map<int, List<String>>, Map<int, int>)> usage() async {
    Future<ApiPage> page(int p) =>
        api.getPage('/products', query: {'all': 'true', 'shop_id': '0', 'page': '$p', 'page_size': '100'});
    final first = await page(1);
    final rest = await Future.wait([for (var p = 2; p <= math.min(first.totalPages, 30); p++) page(p)]);
    final by = <int, List<String>>{};
    final vals = <int, int>{};
    for (final pg in [first, ...rest]) {
      for (final prod in pg.data.whereType<Map>()) {
        final attrs = <int>{}, values = <int>{};
        for (final v in ((prod['variants'] as List?) ?? const []).whereType<Map>()) {
          for (final x in ((v['attributes'] as List?) ?? const []).whereType<Map>()) {
            if ((x['attribute_id'] as num?)?.toInt() case final a?) attrs.add(a);
            if ((x['value_id'] as num?)?.toInt() case final id?) values.add(id);
          }
        }
        for (final a in attrs) {
          (by[a] ??= []).add('${prod['name'] ?? ''}');
        }
        for (final v in values) {
          vals[v] = (vals[v] ?? 0) + 1;
        }
      }
    }
    return (by, vals);
  }

  final (list, (by, vals)) = await (
    api.get<List<dynamic>>('/admin/thuoc-tinh', query: {'page_size': '200'}),
    usage(),
  ).wait;
  return AttrBook([for (final a in list.whereType<Map>()) Attr.fromJson(a)], usedBy: by, valueUse: vals);
}, retry: _noRetry);

/// Thao tác ghi. Xong việc gọi [refresh]: tải lại danh sách và ô chọn thuộc tính của form hàng.
class AttrRepo {
  AttrRepo(this._ref);

  final Ref _ref;
  ApiClient get _api => _ref.read(apiClientProvider);

  /// [values] là trạng thái CUỐI của bảng giá trị: có id thì sửa, không id thì thêm,
  /// giá trị cũ vắng mặt là bị xoá. Mã bỏ trống: thêm mới = API tự đặt, sửa = giữ mã cũ.
  Map<String, dynamic> body({
    required String code,
    required String name,
    required bool active,
    required List<AttrValue> values,
  }) => {
    'code': code,
    'name': name,
    'is_active': active,
    'values': [
      for (final v in values) {'id': ?v.id, 'code': v.code, 'name': v.name},
    ],
  };

  Future<Attr> create(Map<String, dynamic> body) async =>
      Attr.fromJson(await _api.post<Map<String, dynamic>>('/admin/thuoc-tinh', body: body));

  Future<void> update(int id, Map<String, dynamic> body) => _api.put<Object?>('/admin/thuoc-tinh/$id', body: body);

  Future<void> setActive(int id, bool on) =>
      _api.put<Object?>('/admin/thuoc-tinh/$id/trang-thai', body: {'is_active': on});

  Future<void> delete(int id) => _api.delete<Object?>('/admin/thuoc-tinh/$id');

  void refresh() {
    _ref.invalidate(attrBookProvider);
    _ref.invalidate(productFormSourcesProvider);
  }
}

final attrRepoProvider = Provider<AttrRepo>(AttrRepo.new);
